#include "ApiClient.h"
#include "config.h"
#include "Storage.h"
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
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

    String stored = Storage::etag();
    if (stored.length()) http.addHeader("If-None-Match", stored);

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

    // Persist the new ETag so the next poll can 304.
    if (http.hasHeader("ETag")) Storage::setEtag(http.header("ETag"));
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

bool ApiClient::fetchPhoto(const String& renderUrl, uint8_t** buf, size_t* len) {
    *buf = nullptr; *len = 0;
    HTTPClient http;
    if (!beginRequest(http, renderUrl)) return false;
    http.addHeader("Accept", "image/jpeg");

    int code = http.GET();
    if (code != 200) { http.end(); return false; }

    int size = http.getSize();
    if (size <= 0 || size > 200 * 1024) { http.end(); return false; } // sanity cap

    uint8_t* mem = (uint8_t*) malloc(size);
    if (!mem) { http.end(); return false; }

    WiFiClient* stream = http.getStreamPtr();
    int read = 0;
    unsigned long start = millis();
    while (read < size && millis() - start < 10000) {
        size_t avail = stream->available();
        if (avail) {
            int n = stream->readBytes(mem + read, min((size_t)(size - read), avail));
            read += n;
            start = millis();
        } else {
            delay(1);
        }
    }
    http.end();

    if (read != size) { free(mem); return false; }
    *buf = mem; *len = size;
    return true;
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

static bool simplePost(ApiClient*, const String& url, const String& token) {
    HTTPClient http;
    WiFiClient* /*unused*/ _ = nullptr; (void)_;
    if (!http.begin(tls, url)) return false;
    http.setTimeout(8000);
    http.addHeader("Accept", "application/json");
    if (token.length()) http.addHeader("Authorization", "Bearer " + token);
    int code = http.POST("");
    http.end();
    return code == 200;
}

bool ApiClient::alarmDismissed(long id) {
    return simplePost(this, String(API_BASE) + "/alarms/" + id + "/dismissed", _token);
}
bool ApiClient::messageRead(long id) {
    return simplePost(this, String(API_BASE) + "/messages/" + id + "/read", _token);
}
bool ApiClient::photoShown(long id) {
    return simplePost(this, String(API_BASE) + "/programs/photos/" + id + "/shown", _token);
}
