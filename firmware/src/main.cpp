// Smart Alarm — ESP32 firmware entry point.
//
// State machine:  BOOT → (WiFi provisioning) → PAIRING (until claimed) → RUNNING.
// In RUNNING we poll /sync (ETag/304), keep a local clock on Europe/Rome wall
// time, render the active program photo + clock, and ring matching alarms.
// Everything time-related is LOCAL wall-clock — see docs/IMPLEMENTATION_GUIDE.md.

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <ArduinoJson.h>
#include <time.h>
#include <limits.h>

#include "config.h"
#include "types.h"
#include "Storage.h"
#include "ApiClient.h"
#include "DisplayUI.h"

enum class State { Boot, Pairing, Running };
static State state = State::Boot;

static ApiClient api;
static SyncData  data;            // last good /sync payload
static bool      haveData = false;

// Timers
static unsigned long lastSync = 0, lastTime = 0, lastStatus = 0,
                     lastPairPoll = 0, lastSecond = 0, lastRotate = 0,
                     lastFwCheck = 0;

// Photo cache (current frame only — re-fetched when its hash changes). The JPEG
// bytes are kept resident so the clock overlay can repaint behind its text
// without re-downloading the photo.
static int      photoIndex = 0;
static String   shownPhotoHash;
static String   failedPhotoHash;        // last hash whose download failed
static unsigned long lastPhotoAttempt = 0;
static uint8_t* photoJpeg = nullptr;
static size_t   photoJpegLen = 0;
static int      lastClockMinute = -1;   // force an overlay redraw when != now
static String   pairingCode, deviceMac;
static long     pairingExpiresIn = 0;

// Alarm ring state.
static bool   ringing = false;
static String ringingTime, ringingCaption;
static long   ringingAlarmId = 0;
static String ringingScheduledDate;
// De-dupe: each alarm fires at most once per wall-clock minute. A single
// "last key" isn't enough — two alarms sharing one HH:MM must BOTH ring, and
// dismissing the second must not let the first ring again.
static String firedMinute;         // the "YYYY-MM-DDTHH:MM" the list belongs to
static String firedIds;            // ",id," list of alarms already fired in it
static bool   snoozePending = false;
static unsigned long snoozeAt = 0; // when the snoozed alarm re-fires (millis)
// Which alarm the pending snooze belongs to. Kept SEPARATE from ringingAlarmId:
// another alarm may ring (and overwrite the ringing state) while a snooze is
// pending, and the re-fire must still target the alarm that was snoozed.
static long   snoozedAlarmId = 0;

static const char* WEEKDAYS[] = {"Sunday","Monday","Tuesday","Wednesday",
                                 "Thursday","Friday","Saturday"};

// ---- helpers -----------------------------------------------------------------

// Colon-separated, as the server's mac_address validation expects.
static String deviceMacAddress() {
    return WiFi.macAddress();       // AA:BB:CC:DD:EE:FF
}

static bool getLocalTm(struct tm& out) {
    time_t now = time(nullptr);
    if (now < 1700000000) return false;   // clock not set yet (< 2023)
    localtime_r(&now, &out);
    return true;
}

// Parse "HH:MM" → minutes since midnight, or -1.
static int hhmmToMinutes(const String& s) {
    int c = s.indexOf(':');
    if (c < 0) return -1;
    return s.substring(0, c).toInt() * 60 + s.substring(c + 1).toInt();
}

static void syncSystemClock() {
    // Primary: NTP with the Europe/Rome TZ rule so localtime() is wall-clock.
    configTzTime(TZ_INFO, NTP_SERVER_1, NTP_SERVER_2);
}

// Fallback if NTP never resolves: trust the server's wall-clock string.
static void clockFromServer() {
    String st, tz;
    if (!api.fetchTime(st, tz)) return;
    struct tm t = {};
    if (sscanf(st.c_str(), "%d-%d-%dT%d:%d:%d",
               &t.tm_year, &t.tm_mon, &t.tm_mday,
               &t.tm_hour, &t.tm_min, &t.tm_sec) == 6) {
        t.tm_year -= 1900; t.tm_mon -= 1; t.tm_isdst = -1;
        setenv("TZ", TZ_INFO, 1); tzset();
        time_t epoch = mktime(&t);
        struct timeval tv = { .tv_sec = epoch, .tv_usec = 0 };
        settimeofday(&tv, nullptr);
    }
}

// ---- program / photo rendering ----------------------------------------------

// Choose which photo index to show for the current program + date.
static int pickPhotoIndex(const struct tm& now) {
    if (data.program.photos.empty()) return -1;
    const String& type = data.program.type;

    if (type == "fixed") return 0;

    if (type == "rotation") {
        int n = data.program.photos.size();
        return ((photoIndex % n) + n) % n;
    }

    if (type == "daily") {
        char today[11];
        strftime(today, sizeof(today), "%Y-%m-%d", &now);
        for (size_t i = 0; i < data.program.photos.size(); i++)
            if (data.program.photos[i].scheduledFor == String(today)) return i;
        // fall back to first unpinned (no scheduled_for), else first.
        for (size_t i = 0; i < data.program.photos.size(); i++)
            if (data.program.photos[i].scheduledFor.length() == 0) return i;
        return 0;
    }
    return 0;
}

// Download (if hash changed) and draw the chosen photo as the background.
// This runs from the 1Hz tick, so failures back off (PHOTO_RETRY_MS) instead
// of re-attempting a TLS download every second during a network blip.
static void renderPhoto(int idx) {
    if (idx < 0 || idx >= (int)data.program.photos.size()) return;
    const ProgramPhoto& p = data.program.photos[idx];
    if (p.hash == shownPhotoHash) return;   // already on screen
    if (p.hash == failedPhotoHash && millis() - lastPhotoAttempt < PHOTO_RETRY_MS) return;

    bool ok = false;
    uint8_t* buf = nullptr; size_t len = 0;
    if (api.fetchPhoto(p.renderUrl, &buf, &len) && buf) {
        if (DisplayUI::drawPhoto(buf, len)) {
            // Keep the bytes resident for the overlay's per-minute repaint.
            free(photoJpeg);
            photoJpeg = buf; photoJpegLen = len;
            shownPhotoHash = p.hash;
            failedPhotoHash = "";
            lastClockMinute = -1;            // force clock redraw over the new photo
            api.photoShown(p.id);            // best-effort bookkeeping
            ok = true;
        } else {
            free(buf);
        }
    }
    if (!ok) { failedPhotoHash = p.hash; lastPhotoAttempt = millis(); }
}

// ---- night mode / brightness -------------------------------------------------

static bool inNightWindow(const struct tm& now) {
    int cur = now.tm_hour * 60 + now.tm_min;
    int s = hhmmToMinutes(data.settings.nightStart);
    int e = hhmmToMinutes(data.settings.nightEnd);
    if (s < 0 || e < 0) return false;
    return (s <= e) ? (cur >= s && cur < e)      // same-day window
                    : (cur >= s || cur < e);     // wraps midnight
}

static void applyBrightness(const struct tm& now) {
    DisplayUI::setBrightness(inNightWindow(now) ? data.settings.nightBrightness
                                                : data.settings.brightness);
}

// ---- alarms ------------------------------------------------------------------

// Index of the message currently on screen: the most recent one whose
// scheduled_at has passed (messages arrive sorted ascending). -1 if none.
// A short idle press must mark THIS one read — display and acknowledge must agree.
static int dueMessageIndex(const struct tm& now) {
    char nowStr[20];
    strftime(nowStr, sizeof(nowStr), "%Y-%m-%dT%H:%M:%S", &now);
    int best = -1;
    for (size_t i = 0; i < data.messages.size(); i++) {
        const Message& m = data.messages[i];
        if (m.scheduledAt.length() && m.scheduledAt <= String(nowStr)) best = (int) i;
    }
    return best;
}

static String dueMessageFor(const struct tm& now) {
    int i = dueMessageIndex(now);
    return i >= 0 ? data.messages[i].text : String();
}

// The alarm that will actually fire soonest from `now`, honouring one-time
// dates and recurring weekdays. `.time` is "HH:MM" and `.date` a friendly day
// label ("Today" / "Tomorrow" / "Thu 26 Jun"); both "" when none is upcoming.
struct NextAlarm { String time; String date; };

static NextAlarm nextAlarmInfo(const struct tm& now) {
    long best = LONG_MAX;
    String label;
    int curMin = now.tm_hour * 60 + now.tm_min;

    for (auto& a : data.alarms) {
        int t = hhmmToMinutes(a.time);
        if (t < 0) continue;
        long delta = LONG_MAX;   // minutes from now until this alarm fires

        if (a.scheduledDate.length()) {          // one-time: days until its date
            struct tm d = {};
            if (sscanf(a.scheduledDate.c_str(), "%d-%d-%d",
                       &d.tm_year, &d.tm_mon, &d.tm_mday) != 3) continue;
            d.tm_year -= 1900; d.tm_mon -= 1; d.tm_hour = 12; d.tm_isdst = -1;
            struct tm ref = now;
            ref.tm_hour = 12; ref.tm_min = 0; ref.tm_sec = 0; ref.tm_isdst = -1;
            long days = (long) lround(difftime(mktime(&d), mktime(&ref)) / 86400.0);
            if (days < 0 || (days == 0 && t <= curMin)) continue;   // already passed
            delta = days * 1440L + (t - curMin);
        } else {                                  // recurring: next listed weekday
            // off goes to 7 (not 6) so a once-weekly alarm whose only day is
            // today still resolves to the SAME weekday next week once today's
            // slot has passed — otherwise it would never be found.
            for (int off = 0; off <= 7; off++) {
                if (off == 0 && t <= curMin) continue;   // today's slot already passed
                int wd = (now.tm_wday + off) % 7;
                bool listed = false;
                for (auto& dn : a.days) if (dn == WEEKDAYS[wd]) { listed = true; break; }
                if (!listed) continue;
                delta = off * 1440L + (t - curMin);
                break;
            }
        }

        if (delta < best) { best = delta; label = a.time; }
    }

    NextAlarm out;
    if (best == LONG_MAX || !label.length()) return out;   // nothing upcoming
    out.time = label;

    // Calendar day the alarm falls on = now + `best` minutes, as a friendly label.
    struct tm tnow = now; tnow.tm_isdst = -1;
    time_t target = mktime(&tnow) + (time_t)best * 60;
    struct tm tt;  localtime_r(&target, &tt);

    struct tm a0 = tt;  a0.tm_hour = 0; a0.tm_min = 0; a0.tm_sec = 0; a0.tm_isdst = -1;
    struct tm n0 = now; n0.tm_hour = 0; n0.tm_min = 0; n0.tm_sec = 0; n0.tm_isdst = -1;
    long days = lround(difftime(mktime(&a0), mktime(&n0)) / 86400.0);
    if      (days <= 0) out.date = "Today";
    else if (days == 1) out.date = "Tomorrow";
    else { char b[24]; strftime(b, sizeof(b), "%a %e %b", &tt); out.date = b; }
    return out;
}

// Does this alarm fire at `now` (to the minute)?
static bool alarmMatches(const Alarm& a, const struct tm& now) {
    char hhmm[6]; snprintf(hhmm, sizeof(hhmm), "%02d:%02d", now.tm_hour, now.tm_min);
    if (a.time != String(hhmm)) return false;

    if (a.scheduledDate.length()) {          // one-time
        char today[11]; strftime(today, sizeof(today), "%Y-%m-%d", &now);
        return a.scheduledDate == String(today);
    }
    // recurring: weekday name must be listed
    String wd = WEEKDAYS[now.tm_wday];
    for (auto& d : a.days) if (d == wd) return true;
    return false;
}

// Intermittent alarm tone: three short beeps, a pause, repeat — driven from
// loop() every iteration while ringing (millis()-paced, non-blocking). The
// pattern is on/off/on/off/on/<long off>, so even-numbered steps sound.
static unsigned long beepAt = 0;
static int           beepStep = 0;
static void serviceAlarmSound() {
    static const uint16_t dur[6] = {140, 140, 140, 140, 140, 650};
    if (millis() - beepAt < dur[beepStep]) return;
    beepAt = millis();
    beepStep = (beepStep + 1) % 6;
    if (beepStep % 2 == 0) DisplayUI::buzzerOn(data.settings.volume);
    else                   DisplayUI::buzzerOff();
}

static void startRinging(const Alarm& a) {
    ringing = true;
    ringingTime          = a.time;
    ringingAlarmId       = a.id;
    ringingScheduledDate = a.scheduledDate;
    ringingCaption       = data.program.photos.empty() ? "" : "Wake up!";
    DisplayUI::showRinging(ringingTime, ringingCaption);
    beepStep = 0; beepAt = millis();         // start the beep pattern on its first beep
    DisplayUI::buzzerOn(data.settings.volume);
    DisplayUI::setBrightness(100);
}

static void stopRinging(bool snooze) {
    DisplayUI::buzzerOff();
    ringing = false;
    if (snooze) {
        snoozePending = true;
        snoozedAlarmId = ringingAlarmId;
        snoozeAt = millis() + (unsigned long)SNOOZE_MINUTES * 60000UL;
    } else if (ringingScheduledDate.length()) {
        api.alarmDismissed(ringingAlarmId);  // one-time → deactivate server-side
    }
    // Repaint the photo + clock from cache (no re-download needed).
    if (photoJpeg) DisplayUI::drawPhoto(photoJpeg, photoJpegLen);
    lastClockMinute = -1;
}

// ---- the single button (active-low, short/long press) -------------------------
//
// One physical button does everything, disambiguated by state + hold time:
//   ringing → short press = snooze; held LONG_PRESS_MS = dismiss (fires while
//             still held, so "hold until it stops ringing" just works)
//   idle    → short press (on release) = mark the on-screen message read
// Debounce: a raw state change only counts once it has been stable for 25ms —
// tracked by timestamp, never by delay(), so the loop (buzzer pacing, alarm
// matching) is never blocked.

static bool          btnDown = false;       // debounced "currently held"
static unsigned long btnDownAt = 0;
static bool          btnLongFired = false;  // long-press action already taken
static bool          btnRaw = false;        // last raw reading
static unsigned long btnRawChangedAt = 0;   // when the raw reading last flipped

static void shortPressIdle() {
    // Mark the message currently ON SCREEN read (the latest due one — the same
    // index dueMessageFor shows, so display and acknowledge always agree).
    // Clear the local state only if the server ACCEPTED the read: clearing on
    // a failed call (WiFi down) would lose the ack — the server keeps it
    // unread and it silently reappears at the next payload change. Failing
    // leaves the message on screen, so the press can simply be repeated.
    struct tm now;
    if (!getLocalTm(now)) return;
    int i = dueMessageIndex(now);
    if (i >= 0 && api.messageRead(data.messages[i].id)) {
        data.messages[i].scheduledAt = "";   // locally no longer due
        lastClockMinute = -1;                // refresh the overlay now
    }
}

static void handleButton() {
    unsigned long ms = millis();
    bool raw = digitalRead(BTN_PIN) == LOW;

    if (raw != btnRaw) {                     // raw flip → restart the settle timer
        btnRaw = raw;
        btnRawChangedAt = ms;
        return;
    }
    if (ms - btnRawChangedAt < 25) return;   // still settling (bounce)

    if (btnRaw && !btnDown) {                // debounced press started
        btnDown = true; btnLongFired = false; btnDownAt = ms;
        return;
    }

    if (btnRaw && btnDown) {                 // still held → check the long press
        if (!btnLongFired && ringing && ms - btnDownAt >= LONG_PRESS_MS) {
            btnLongFired = true;
            stopRinging(false);              // dismiss the moment the hold qualifies
        }
        return;
    }

    if (!btnRaw && btnDown) {                // debounced release
        btnDown = false;
        if (btnLongFired) return;            // the hold already did its job
        if (ringing) stopRinging(true);      // short press while ringing → snooze
        else         shortPressIdle();
    }
}

// ---- sync --------------------------------------------------------------------

// Persist a compact alarms+settings snapshot to NVS so a reboot with the
// server unreachable still rings on schedule. Photos/messages are left out:
// they need the network anyway, and NVS entries are capped at ~4KB.
static void saveSyncCache() {
    JsonDocument doc;
    JsonObject s = doc["settings"].to<JsonObject>();
    s["brightness"]       = data.settings.brightness;
    s["night_brightness"] = data.settings.nightBrightness;
    s["night_start"]      = data.settings.nightStart;
    s["night_end"]        = data.settings.nightEnd;
    s["volume"]           = data.settings.volume;
    s["time_format"]      = data.settings.timeFormat;

    JsonArray arr = doc["alarms"].to<JsonArray>();
    for (auto& a : data.alarms) {
        JsonObject o = arr.add<JsonObject>();
        o["id"]   = a.id;
        o["time"] = a.time;
        if (a.scheduledDate.length()) o["date"] = a.scheduledDate;
        JsonArray d = o["days"].to<JsonArray>();
        for (auto& dn : a.days) d.add(dn);
    }

    String out;
    serializeJson(doc, out);
    Storage::setSyncCache(out);
}

// Boot fallback: restore the last-synced alarms + settings. Returns true when
// a cache was loaded (→ haveData, so the clock runs and alarms ring offline).
static bool loadSyncCache() {
    String json = Storage::syncCache();
    if (!json.length()) return false;

    JsonDocument doc;
    if (deserializeJson(doc, json)) return false;

    JsonObject s = doc["settings"];
    if (!s.isNull()) {
        data.settings.brightness      = s["brightness"]       | 80;
        data.settings.nightBrightness = s["night_brightness"] | 10;
        data.settings.nightStart      = String((const char*)(s["night_start"] | "22:00"));
        data.settings.nightEnd        = String((const char*)(s["night_end"]   | "07:00"));
        data.settings.volume          = s["volume"]           | 70;
        data.settings.timeFormat      = String((const char*)(s["time_format"] | "24"));
    }

    data.alarms.clear();
    for (JsonObject a : doc["alarms"].as<JsonArray>()) {
        if (data.alarms.size() >= MAX_ALARMS) break;
        Alarm al;
        al.id            = a["id"] | 0L;
        al.time          = String((const char*)(a["time"] | ""));
        al.scheduledDate = String((const char*)(a["date"] | ""));
        for (JsonVariant d : a["days"].as<JsonArray>())
            al.days.push_back(String((const char*)(d | "")));
        data.alarms.push_back(al);
    }
    return true;
}

static void doSync() {
    SyncResult r = api.sync(data);
    if (r == SyncResult::Updated) {
        haveData = true;
        saveSyncCache();
        // No need to clear shownPhotoHash: renderPhoto compares per-photo
        // hashes, so an unrelated change (message read, settings tweak)
        // doesn't force a pointless re-download of the same photo.
    } else if (r == SyncResult::Unauthorized) {
        // Token rejected (pairing window expired / device pruned) → re-pair.
        Storage::clearToken();
        ESP.restart();
    }
    // NotModified / Error: keep last good data, stay resilient.
}

// ---- pairing -----------------------------------------------------------------

static void enterPairing() {
    state = State::Pairing;
    if (!Storage::hasToken()) {
        PairInfo pi = api.pairInit(deviceMac);
        if (pi.ok) { pairingCode = pi.pairingCode; pairingExpiresIn = pi.expiresIn; }
        else if (pi.alreadyOwned) { DisplayUI::showBooting("Device owned - remove from panel"); return; }
    } else {
        api.setToken(Storage::token());
    }
    if (pairingCode.length()) DisplayUI::showPairing(pairingCode, deviceMac, pairingExpiresIn);
    else                      DisplayUI::showBooting("Connecting to server...");
    lastPairPoll = 0;
}

static void pollPairing() {
    PairStatus ps = api.pairStatus();
    if (ps.unauthorized) {                   // window expired → fresh code
        Storage::clearToken();
        PairInfo pi = api.pairInit(deviceMac);
        if (pi.ok) {
            pairingCode = pi.pairingCode;
            pairingExpiresIn = pi.expiresIn;
            DisplayUI::showPairing(pairingCode, deviceMac, pairingExpiresIn);
        } else if (pi.alreadyOwned) {
            DisplayUI::showBooting("Device owned - remove from panel");
        } else {
            DisplayUI::showBooting("Connecting to server...");
        }
        return;
    }
    if (ps.ok) {
        if (ps.paired) {                     // claimed → start running
            state = State::Running;
            DisplayUI::showBooting("Paired! Syncing...");
            doSync();
            return;
        }
        bool codeChanged = ps.pairingCode.length() && ps.pairingCode != pairingCode;
        if (ps.pairingCode.length()) pairingCode = ps.pairingCode;
        pairingExpiresIn = ps.expiresIn;
        // Redraw when the code changes, or once a minute so the "expires in"
        // countdown stays honest — not every poll (fillScreen flicker).
        static long shownExpiryMin = -1;
        long expiryMin = pairingExpiresIn > 0 ? pairingExpiresIn / 60 : -1;
        if (codeChanged || expiryMin != shownExpiryMin) {
            shownExpiryMin = expiryMin;
            DisplayUI::showPairing(pairingCode, deviceMac, pairingExpiresIn);
        }
    }
}

// ---- OTA ----------------------------------------------------------------------

static void checkFirmwareUpdate() {
    String url, md5;
    if (!api.checkFirmware(url, md5)) return;

    DisplayUI::showBooting("Updating firmware...");
    api.otaUpdate(url, md5);             // reboots into the new build on success

    // Still here → the update failed; restore the normal screen and carry on.
    if (photoJpeg) DisplayUI::drawPhoto(photoJpeg, photoJpegLen);
    lastClockMinute = -1;
}

// ---- setup / loop ------------------------------------------------------------

void setup() {
    Serial.begin(115200);
    Storage::begin();
    DisplayUI::begin();
    DisplayUI::showBooting("Starting...");

    pinMode(BTN_PIN, INPUT_PULLUP);

    // Factory reset: hold the button during boot.
    bool resetHeld = digitalRead(BTN_PIN) == LOW;

    // WiFi provisioning (captive portal on first run / when creds are lost).
    DisplayUI::showWifiPortal(WIFI_PORTAL_NAME);
    WiFiManager wm;
    wm.setConfigPortalTimeout(300);
    if (!wm.autoConnect(WIFI_PORTAL_NAME)) {
        DisplayUI::showBooting("WiFi failed, rebooting");
        delay(3000); ESP.restart();
    }

    deviceMac = deviceMacAddress();
    api.begin();

    DisplayUI::showBooting("Syncing clock...");
    syncSystemClock();
    for (int i = 0; i < 20; i++) {           // wait up to ~10s for NTP
        struct tm t; if (getLocalTm(t)) break;
        delay(500);
    }
    { struct tm t; if (!getLocalTm(t)) { api.setToken(Storage::token()); clockFromServer(); } }

    if (resetHeld && Storage::hasToken()) {
        api.setToken(Storage::token());
        PairInfo pi = api.forget();
        if (pi.ok) { pairingCode = pi.pairingCode; pairingExpiresIn = pi.expiresIn; }
    }

    // Already paired? Go straight to RUNNING — don't flash the pairing screen.
    if (Storage::hasToken()) {
        api.setToken(Storage::token());
        PairStatus ps = api.pairStatus();
        if (ps.ok && ps.paired) {
            state = State::Running;
            DisplayUI::showBooting("Syncing...");
            doSync();
        } else if (!ps.ok && !ps.unauthorized) {
            // Server unreachable at boot. We hold a token, so odds are we're
            // still paired: run on the cached alarms instead of camping on the
            // pairing screen. If we're actually unpaired, the first successful
            // /sync answers 401 → token cleared → reboot into pairing.
            state = State::Running;
        } else {
            if (ps.unauthorized) Storage::clearToken();   // pruned → fresh pair/init
            if (ps.ok) { pairingCode = ps.pairingCode; pairingExpiresIn = ps.expiresIn; }
            enterPairing();
        }

        // No fresh payload (offline boot / failed first sync) → fall back to
        // the NVS snapshot. NOTE: with no RTC on board, cached alarms only
        // actually ring once the clock is known — i.e. after one successful
        // NTP or /time fix. "Server down but internet up" recovers fully;
        // a completely offline boot shows the cache but can't match alarms
        // until connectivity (and thus time) returns.
        if (state == State::Running && !haveData && loadSyncCache()) haveData = true;
    } else {
        enterPairing();
    }

    // First OTA check one minute after boot, then every FIRMWARE_INTERVAL_MS.
    lastFwCheck = millis() + 60000UL - FIRMWARE_INTERVAL_MS;
}

void loop() {
    unsigned long ms = millis();

    // WiFi reconnection is NON-blocking: the device is first and foremost an
    // alarm clock, so everything local (alarm matching, button, buzzer, clock
    // overlay) must keep running on cached data even with the network down.
    // Only the network calls below are gated on `online`.
    bool online = WiFi.status() == WL_CONNECTED;
    static unsigned long lastWifiAttempt = 0;
    if (!online && ms - lastWifiAttempt >= WIFI_RETRY_MS) {
        lastWifiAttempt = ms;
        WiFi.reconnect();
    }

    if (state == State::Pairing) {
        if (online && ms - lastPairPoll >= PAIR_POLL_MS) { lastPairPoll = ms; pollPairing(); }
        delay(50);
        return;
    }

    // ---- RUNNING ----
    if (online) {
        if (ms - lastSync   >= SYNC_INTERVAL_MS)   { lastSync = ms;   doSync(); }
        if (ms - lastStatus >= STATUS_INTERVAL_MS) { lastStatus = ms; api.postStatus(); }
        if (ms - lastTime   >= TIME_INTERVAL_MS)   {
            lastTime = ms;
            struct tm t; if (!getLocalTm(t)) clockFromServer();
        }
        // !snoozePending too: a successful OTA reboots, and the snooze re-fire
        // lives in RAM only — updating mid-snooze would silently lose the
        // re-wake. The update just waits for the next 6h check.
        if (!ringing && !snoozePending && ms - lastFwCheck >= FIRMWARE_INTERVAL_MS) {
            lastFwCheck = ms;
            checkFirmwareUpdate();           // never reboots mid-alarm (guarded above)
        }
    }

    handleButton();
    if (ringing) serviceAlarmSound();        // beep-beep-beep pattern while ringing

    // Snooze re-fire. Signed-difference compare so it survives millis() rollover
    // (~49.7 days) — this device is meant to stay on for months.
    if (!ringing && snoozePending && (long)(ms - snoozeAt) >= 0) {
        snoozePending = false;
        // Re-ring only if the alarm still exists and is active — it may have
        // been deleted or disabled from the panel during the snooze (/sync
        // only ships active alarms, so absence here means "don't ring").
        for (auto& a : data.alarms) {
            if (a.id == snoozedAlarmId) { startRinging(a); break; }
        }
    }

    // Once per second: clock, alarm check, render.
    if (ms - lastSecond >= 1000) {
        lastSecond = ms;
        struct tm now;
        if (haveData && getLocalTm(now)) {
            applyBrightness(now);

            if (!ringing) {
                // Rotation advance by interval_minutes.
                if (data.program.type == "rotation" && data.program.intervalMinutes > 0) {
                    unsigned long iv = (unsigned long)max(data.program.intervalMinutes,
                                          ROTATION_MIN_INTERVAL) * 60000UL;
                    if (ms - lastRotate >= iv) { lastRotate = ms; photoIndex++; shownPhotoHash = ""; }
                }

                // Alarm matching. Skip — don't stop at — alarms that already
                // fired this minute, so two alarms sharing one HH:MM both ring.
                char key[24];
                strftime(key, sizeof(key), "%Y-%m-%dT%H:%M", &now);
                if (firedMinute != String(key)) { firedMinute = key; firedIds = ""; }
                for (auto& a : data.alarms) {
                    if (!alarmMatches(a, now)) continue;
                    String tag = "," + String(a.id) + ","; // Check with commas to avoid substring collisions (e.g. 12 vs 112).
                    if (firedIds.indexOf(tag) >= 0) continue;   // if tag is it fired => already had its turn
                    firedIds += tag;
                    startRinging(a);
                    break;
                }

                // if there isn't a new alarm ringing, update the clock overlay (and photo if needed)
                if (!ringing) {
                    int idx = pickPhotoIndex(now);
                    if (idx < 0) {
                        // No program associated → drop any cached photo so the
                        // overlay paints a plain black background.
                        // This checks are done the first time the program is empty, then the last clock minutes is "real" and the photo 
                        // is not drawn anymore, so the overlay will be drawn on a black background.
                        if (photoJpeg) { free(photoJpeg); photoJpeg = nullptr; photoJpegLen = 0; }
                        if (shownPhotoHash.length()) { shownPhotoHash = ""; lastClockMinute = -1; }
                    } else if (online) {
                        renderPhoto(idx);    // full repaint only when the photo changes
                    }

                    // The clock updates in place via a small sprite (no flicker);
                    // a full overlay repaint happens only when the minute tick is
                    // also a forced redraw (lastClockMinute == -1, e.g. new photo)
                    // — drawClockOverlay itself escalates to full when the date,
                    // caption, next-alarm or message changed.
                    if (now.tm_min != lastClockMinute) {
                        bool full = (lastClockMinute == -1); // force a full overlay repaint when the photo changed, or the minute tick is the first one after boot.
                        lastClockMinute = now.tm_min;
                        String caption = (idx >= 0 && idx < (int)data.program.photos.size())
                                       ? data.program.photos[idx].caption : "";
                        NextAlarm na = nextAlarmInfo(now);
                        DisplayUI::drawClockOverlay(photoJpeg, photoJpegLen, now,
                            data.settings.timeFormat, caption,
                            na.time, na.date, dueMessageFor(now), full);
                    }
                }
            }
        }
    }

    delay(20);
}
