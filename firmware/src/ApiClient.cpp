#include "ApiClient.h"
#include "config.h"
#include "Storage.h"
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <ArduinoJson.h>

// One TLS client reused across requests (HTTPS setup is expensive on ESP32).
static WiFiClientSecure tls;

#if !USE_INSECURE_TLS
// Paste the PEM root CA that signs your Cloudflare edge cert here and set
// USE_INSECURE_TLS=0 in config.h to validate the chain.
static const char* ROOT_CA = R"EOF(
-----BEGIN CERTIFICATE-----
...replace me...
-----END CERTIFICATE-----
)EOF";
#endif

void ApiClient::begin() {
#if USE_INSECURE_TLS
    tls.setInsecure();
#else
    tls.setCACert(ROOT_CA);
#endif
}

// Common request setup: target URL, JSON Accept, bearer token, sane timeouts.
bool ApiClient::beginRequest(HTTPClient& http, const String& url) {
    if (WiFi.status() != WL_CONNECTED) return false;
    if (!http.begin(tls, url)) return false;
    http.setTimeout(8000);
    http.setReuse(false);
    http.addHeader("Accept", "application/json");
    if (_token.length()) http.addHeader("Authorization", "Bearer " + _token);
    return true;
}

// ---- pairing -----------------------------------------------------------------

PairInfo ApiClient::pairInit(const String& mac) {
    PairInfo r;
    HTTPClient http;
    if (!http.begin(tls, String(API_BASE) + "/pair/init")) return r;
    http.setTimeout(8000);
    http.addHeader("Accept", "application/json");
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    int code = http.POST("mac_address=" + mac);
    if (code == 201 || code == 200) {
        JsonDocument doc;
        if (!deserializeJson(doc, http.getStream())) {
            String token = doc["device_token"] | "";
            if (token.length()) {
                Storage::setToken(token);
                _token = token;
                r.ok          = true;
                r.pairingCode = String((const char*)(doc["pairing_code"] | ""));
                r.name        = String((const char*)(doc["name"] | ""));
                r.expiresIn   = doc["expires_in"] | 0L;
            }
        }
    }
    http.end();
    return r;
}

PairStatus ApiClient::pairStatus() {
    PairStatus r;
    HTTPClient http;
    if (!beginRequest(http, String(API_BASE) + "/pair/status")) return r;

    int code = http.GET();
    if (code == 401) { r.unauthorized = true; }
    else if (code == 200) {
        JsonDocument doc;
        if (!deserializeJson(doc, http.getStream())) {
            r.ok          = true;
            r.paired      = doc["paired"] | false;
            r.name        = String((const char*)(doc["name"] | ""));
            r.pairingCode = String((const char*)(doc["pairing_code"] | ""));
            r.expiresIn   = doc["expires_in"] | 0L;
        }
    }
    http.end();
    return r;
}

PairInfo ApiClient::forget() {
    PairInfo r;
    HTTPClient http;
    if (!beginRequest(http, String(API_BASE) + "/device/forget")) return r;

    int code = http.POST("");
    if (code == 200 || code == 201) {
        JsonDocument doc;
        if (!deserializeJson(doc, http.getStream())) {
            String token = doc["device_token"] | "";
            if (token.length()) {
                Storage::setToken(token);
                _token = token;
                r.ok          = true;
                r.pairingCode = String((const char*)(doc["pairing_code"] | ""));
                r.name        = String((const char*)(doc["name"] | ""));
                r.expiresIn   = doc["expires_in"] | 0L;
            }
        }
    }
    http.end();
    return r;
}

// ---- sync --------------------------------------------------------------------

SyncResult ApiClient::sync(SyncData& out) {
    HTTPClient http;
    if (!beginRequest(http, String(API_BASE) + "/sync")) return SyncResult::Error;

    if (_etag.length()) http.addHeader("If-None-Match", _etag);

    const char* collect[] = {"ETag"};
    http.collectHeaders(collect, 1);

    int code = http.GET();

    if (code == 304) { http.end(); return SyncResult::NotModified; }
    if (code == 401) { http.end(); return SyncResult::Unauthorized; }
    if (code != 200) { http.end(); return SyncResult::Error; }

    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, http.getStream());
    if (err) { http.end(); return SyncResult::Error; }

    out = SyncData{};  // reset
    out.serverTime = String((const char*)(doc["server_time"] | ""));
    out.timezone   = String((const char*)(doc["timezone"] | ""));
    out.revision   = String((const char*)(doc["revision"] | ""));
    out.deviceName = String((const char*)(doc["device"]["name"] | ""));

    JsonObject s = doc["settings"];
    if (!s.isNull()) {
        out.settings.brightness      = s["brightness"]       | 80;
        out.settings.nightBrightness = s["night_brightness"] | 10;
        out.settings.nightStart      = String((const char*)(s["night_start"] | "22:00"));
        out.settings.nightEnd        = String((const char*)(s["night_end"]   | "07:00"));
        out.settings.volume          = s["volume"]           | 70;
        out.settings.timeFormat      = String((const char*)(s["time_format"] | "24"));
    }

    for (JsonObject a : doc["alarms"].as<JsonArray>()) {
        if (out.alarms.size() >= MAX_ALARMS) break;
        Alarm al;
        al.id            = a["id"] | 0L;
        al.time          = String((const char*)(a["time"] | ""));
        al.scheduledDate = String((const char*)(a["scheduled_date"] | ""));
        for (JsonVariant d : a["days_of_week"].as<JsonArray>())
            al.days.push_back(String((const char*)(d | "")));
        out.alarms.push_back(al);
    }

    JsonObject p = doc["program"];
    if (!p.isNull()) {
        out.program.type            = String((const char*)(p["type"] | ""));
        out.program.intervalMinutes = p["interval_minutes"] | 0;
        for (JsonObject ph : p["photos"].as<JsonArray>()) {
            if (out.program.photos.size() >= MAX_PHOTOS) break;
            ProgramPhoto pp;
            pp.id           = ph["id"] | 0L;
            pp.renderUrl    = String((const char*)(ph["render_url"] | ""));
            pp.caption      = String((const char*)(ph["caption"] | ""));
            pp.scheduledFor = String((const char*)(ph["scheduled_for"] | ""));
            pp.hash         = String((const char*)(ph["hash"] | ""));
            out.program.photos.push_back(pp);
        }
    }

    for (JsonObject m : doc["messages"].as<JsonArray>()) {
        if (out.messages.size() >= MAX_MESSAGES) break;
        Message msg;
        msg.id          = m["id"] | 0L;
        msg.text        = String((const char*)(m["text"] | ""));
        msg.scheduledAt = String((const char*)(m["scheduled_at"] | ""));
        out.messages.push_back(msg);
    }

    // Remember the new ETag (RAM only) so the next poll can 304.
    if (http.hasHeader("ETag")) _etag = http.header("ETag");
    http.end();
    return SyncResult::Updated;
}

bool ApiClient::fetchTime(String& serverTimeOut, String& tzOut) {
    HTTPClient http;
    if (!beginRequest(http, String(API_BASE) + "/time")) return false;
    bool ok = false;
    if (http.GET() == 200) {
        JsonDocument doc;
        if (!deserializeJson(doc, http.getStream())) {
            serverTimeOut = String((const char*)(doc["server_time"] | ""));
            tzOut         = String((const char*)(doc["timezone"] | ""));
            ok = serverTimeOut.length() > 0;
        }
    }
    http.end();
    return ok;
}

// ---- photo download ----------------------------------------------------------

// Write-only Stream that grows a heap buffer. Letting HTTPClient::writeToStream
// fill it means chunked transfer-encoding (proxies like Cloudflare may re-chunk,
// dropping Content-Length) is decoded for us — reading the raw stream wouldn't be.
class JpegBuffer : public Stream {
public:
    static const size_t CAP = 200 * 1024;   // sanity cap
    uint8_t* buf = nullptr;
    size_t   len = 0, alloc = 0;
    bool     failed = false;

    ~JpegBuffer() { free(buf); }

    uint8_t* release() { uint8_t* b = buf; buf = nullptr; return b; }

    size_t write(const uint8_t* data, size_t n) override {
        if (failed) return n;                // swallow the rest, flag stays set
        if (len + n > alloc) {
            size_t next = alloc ? alloc * 2 : 32 * 1024;
            while (next < len + n) next *= 2;
            if (next > CAP) next = CAP;                       // clamp, don't overshoot
            if (len + n > next) { failed = true; return n; }  // body exceeds the cap
            uint8_t* bigger = (uint8_t*) realloc(buf, next);
            if (!bigger) { failed = true; return n; }
            buf = bigger; alloc = next;
        }
        memcpy(buf + len, data, n);
        len += n;
        return n;
    }
    size_t write(uint8_t c) override { return write(&c, 1); }
    int available() override { return 0; }
    int read() override  { return -1; }
    int peek() override  { return -1; }
};

bool ApiClient::fetchPhoto(const String& renderUrl, uint8_t** buf, size_t* len) {
    *buf = nullptr; *len = 0;
    HTTPClient http;
    if (!beginRequest(http, renderUrl)) return false;
    http.addHeader("Accept", "image/jpeg");

    int code = http.GET();
    if (code != 200) { http.end(); return false; }

    int declared = http.getSize();           // -1 when chunked
    if (declared == 0 || declared > (int) JpegBuffer::CAP) { http.end(); return false; }

    JpegBuffer body;
    int written = http.writeToStream(&body); // handles identity AND chunked
    http.end();

    bool ok = !body.failed && written > 0 && body.len == (size_t) written
              && (declared < 0 || body.len == (size_t) declared);
    if (!ok) return false;

    *len = body.len;
    *buf = body.release();                   // caller owns + frees
    return true;
}

// ---- OTA -----------------------------------------------------------------------

bool ApiClient::checkFirmware(String& urlOut) {
    urlOut = "";
    HTTPClient http;
    if (!beginRequest(http, String(API_BASE) + "/firmware/latest?current=" FIRMWARE_VERSION))
        return false;

    bool available = false;
    if (http.GET() == 200) {
        JsonDocument doc;
        if (!deserializeJson(doc, http.getStream())) {
            available = doc["update_available"] | false;
            urlOut    = String((const char*)(doc["url"] | ""));
        }
    }
    http.end();
    return available && urlOut.length() > 0;
}

void ApiClient::otaUpdate(const String& url) {
    // On success the ESP reboots into the new firmware; on failure we return
    // and the caller carries on with the running build.
    httpUpdate.rebootOnUpdate(true);
    httpUpdate.update(tls, url);
}

// ---- telemetry & interactions ------------------------------------------------

bool ApiClient::postStatus() {
    HTTPClient http;
    if (!beginRequest(http, String(API_BASE) + "/device/status")) return false;
    http.addHeader("Content-Type", "application/json");

    JsonDocument doc;
    doc["firmware_version"] = FIRMWARE_VERSION;
    doc["free_heap"]        = ESP.getFreeHeap();
    doc["rssi"]             = WiFi.RSSI();
    doc["ip"]               = WiFi.localIP().toString();
    String body; serializeJson(doc, body);

    int code = http.POST(body);
    http.end();
    return code == 200;
}

bool ApiClient::simplePost(const String& url) {
    HTTPClient http;
    if (!beginRequest(http, url)) return false;
    int code = http.POST("");
    http.end();
    return code == 200;
}

bool ApiClient::alarmDismissed(long id) {
    return simplePost(String(API_BASE) + "/alarms/" + id + "/dismissed");
}
bool ApiClient::messageRead(long id) {
    return simplePost(String(API_BASE) + "/messages/" + id + "/read");
}
bool ApiClient::photoShown(long id) {
    return simplePost(String(API_BASE) + "/programs/photos/" + id + "/shown");
}
