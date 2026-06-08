// Smart Alarm — ESP32 firmware entry point.
//
// State machine:  BOOT → (WiFi provisioning) → PAIRING (until claimed) → RUNNING.
// In RUNNING we poll /sync (ETag/304), keep a local clock on Europe/Rome wall
// time, render the active program photo + clock, and ring matching alarms.
// Everything time-related is LOCAL wall-clock — see docs/IMPLEMENTATION_GUIDE.md.

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <time.h>

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
                     lastPairPoll = 0, lastSecond = 0, lastRotate = 0;

// Photo cache (current frame only — re-fetched when its hash changes). The JPEG
// bytes are kept resident so the clock overlay can repaint behind its text
// without re-downloading the photo.
static int      photoIndex = 0;
static String   shownPhotoHash;
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
static String lastFiredKey;        // de-dupe: fire each alarm once per minute
static unsigned long snoozeUntil = 0;
static String snoozeKey;

static const char* WEEKDAYS[] = {"Sunday","Monday","Tuesday","Wednesday",
                                 "Thursday","Friday","Saturday"};

// ---- helpers -----------------------------------------------------------------

static String macNoColons() {
    String m = WiFi.macAddress();   // AA:BB:CC:DD:EE:FF
    return m;
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
static void renderPhoto(int idx) {
    if (idx < 0 || idx >= (int)data.program.photos.size()) return;
    const ProgramPhoto& p = data.program.photos[idx];
    if (p.hash == shownPhotoHash) return;   // already on screen

    uint8_t* buf = nullptr; size_t len = 0;
    if (api.fetchPhoto(p.renderUrl, &buf, &len) && buf) {
        if (DisplayUI::drawPhoto(buf, len)) {
            // Keep the bytes resident for the overlay's per-minute repaint.
            free(photoJpeg);
            photoJpeg = buf; photoJpegLen = len;
            shownPhotoHash = p.hash;
            lastClockMinute = -1;            // force clock redraw over the new photo
            api.photoShown(p.id);            // best-effort bookkeeping
        } else {
            free(buf);
        }
    }
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

static String dueMessageFor(const struct tm& now) {
    // Show the most recent message whose scheduled_at has passed.
    char nowStr[20];
    strftime(nowStr, sizeof(nowStr), "%Y-%m-%dT%H:%M:%S", &now);
    String best;
    for (auto& m : data.messages)
        if (m.scheduledAt.length() && m.scheduledAt <= String(nowStr)) best = m.text;
    return best;
}

static String nextAlarmLabel() {
    String best;
    for (auto& a : data.alarms)
        if (a.time.length() && (best.length() == 0 || a.time < best)) best = a.time;
    return best;
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

static void startRinging(const Alarm& a) {
    ringing = true;
    ringingTime          = a.time;
    ringingAlarmId       = a.id;
    ringingScheduledDate = a.scheduledDate;
    ringingCaption       = data.program.photos.empty() ? "" : "Wake up!";
    DisplayUI::showRinging(ringingTime, ringingCaption);
    DisplayUI::buzzerOn(data.settings.volume);
    DisplayUI::setBrightness(100);
}

static void stopRinging(bool snooze) {
    DisplayUI::buzzerOff();
    ringing = false;
    if (snooze) {
        snoozeUntil = millis() + (unsigned long)SNOOZE_MINUTES * 60000UL;
        snoozeKey   = lastFiredKey;          // don't immediately re-fire same minute
    } else if (ringingScheduledDate.length()) {
        api.alarmDismissed(ringingAlarmId);  // one-time → deactivate server-side
    }
    // Repaint the photo + clock from cache (no re-download needed).
    if (photoJpeg) DisplayUI::drawPhoto(photoJpeg, photoJpegLen);
    lastClockMinute = -1;
}

// ---- buttons (active-low, simple debounce) -----------------------------------

static bool pressed(int pin) {
    if (digitalRead(pin) == LOW) { delay(25); return digitalRead(pin) == LOW; }
    return false;
}

static void handleButtons() {
    if (ringing) {
        if (pressed(BTN_DISMISS)) { stopRinging(false); while (pressed(BTN_DISMISS)) delay(10); }
        else if (pressed(BTN_SNOOZE)) { stopRinging(true); while (pressed(BTN_SNOOZE)) delay(10); }
        return;
    }
    if (pressed(BTN_NEXT)) {
        // Mark the current due message read, and advance a rotation program.
        struct tm now;
        if (getLocalTm(now)) {
            for (auto& m : data.messages) {
                char ns[20]; strftime(ns, sizeof(ns), "%Y-%m-%dT%H:%M:%S", &now);
                if (m.scheduledAt.length() && m.scheduledAt <= String(ns)) {
                    api.messageRead(m.id); m.scheduledAt = ""; break;
                }
            }
        }
        if (data.program.type == "rotation") { photoIndex++; shownPhotoHash = ""; }
        while (pressed(BTN_NEXT)) delay(10);
    }
}

// ---- sync --------------------------------------------------------------------

static void doSync() {
    SyncResult r = api.sync(data);
    if (r == SyncResult::Updated) {
        haveData = true;
        shownPhotoHash = "";                 // program may have changed → repaint
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
    } else {
        api.setToken(Storage::token());
    }
    DisplayUI::showPairing(pairingCode, deviceMac, pairingExpiresIn);
    lastPairPoll = 0;
}

static void pollPairing() {
    PairStatus ps = api.pairStatus();
    if (ps.unauthorized) {                   // window expired → fresh code
        Storage::clearToken();
        PairInfo pi = api.pairInit(deviceMac);
        if (pi.ok) { pairingCode = pi.pairingCode; pairingExpiresIn = pi.expiresIn; }
        DisplayUI::showPairing(pairingCode, deviceMac, pairingExpiresIn);
        return;
    }
    if (ps.ok) {
        if (ps.paired) {                     // claimed → start running
            state = State::Running;
            DisplayUI::showBooting("Paired! Syncing...");
            doSync();
            return;
        }
        if (ps.pairingCode.length()) pairingCode = ps.pairingCode;
        pairingExpiresIn = ps.expiresIn;
        DisplayUI::showPairing(pairingCode, deviceMac, pairingExpiresIn);
    }
}

// ---- setup / loop ------------------------------------------------------------

void setup() {
    Serial.begin(115200);
    Storage::begin();
    DisplayUI::begin();
    DisplayUI::showBooting("Starting...");

    pinMode(BTN_DISMISS, INPUT_PULLUP);
    pinMode(BTN_SNOOZE,  INPUT_PULLUP);
    pinMode(BTN_NEXT,    INPUT_PULLUP);

    // Factory reset: hold DISMISS during boot.
    bool resetHeld = digitalRead(BTN_DISMISS) == LOW;

    // WiFi provisioning (captive portal on first run / when creds are lost).
    DisplayUI::showWifiPortal(WIFI_PORTAL_NAME);
    WiFiManager wm;
    wm.setConfigPortalTimeout(300);
    if (!wm.autoConnect(WIFI_PORTAL_NAME)) {
        DisplayUI::showBooting("WiFi failed, rebooting");
        delay(3000); ESP.restart();
    }

    deviceMac = macNoColons();
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
        Storage::setEtag("");
    }

    enterPairing();

    // If we already had a token and it still works, jump straight to RUNNING.
    if (Storage::hasToken()) {
        PairStatus ps = api.pairStatus();
        if (ps.ok && ps.paired) { state = State::Running; doSync(); }
    }
}

void loop() {
    unsigned long ms = millis();

    if (WiFi.status() != WL_CONNECTED) {
        WiFi.reconnect();
        delay(1000);
        return;
    }

    if (state == State::Pairing) {
        if (ms - lastPairPoll >= PAIR_POLL_MS) { lastPairPoll = ms; pollPairing(); }
        delay(50);
        return;
    }

    // ---- RUNNING ----
    if (ms - lastSync   >= SYNC_INTERVAL_MS)   { lastSync = ms;   doSync(); }
    if (ms - lastStatus >= STATUS_INTERVAL_MS) { lastStatus = ms; api.postStatus(); }
    if (ms - lastTime   >= TIME_INTERVAL_MS)   {
        lastTime = ms;
        struct tm t; if (!getLocalTm(t)) clockFromServer();
    }

    handleButtons();

    // Snooze re-fire.
    if (!ringing && snoozeUntil && ms >= snoozeUntil) {
        snoozeUntil = 0;
        // Reconstruct the snoozed alarm from the saved ring state and re-ring it.
        Alarm a; a.id = ringingAlarmId; a.time = ringingTime;
        a.scheduledDate = ringingScheduledDate;
        startRinging(a);
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

                // Alarm matching (de-dupe per minute via a key).
                char key[24];
                strftime(key, sizeof(key), "%Y-%m-%dT%H:%M", &now);
                for (auto& a : data.alarms) {
                    if (alarmMatches(a, now)) {
                        String k = String(a.id) + "@" + key;
                        if (k != lastFiredKey && k != snoozeKey) {
                            lastFiredKey = k;
                            startRinging(a);
                        }
                        break;
                    }
                }

                if (!ringing) {
                    int idx = pickPhotoIndex(now);
                    renderPhoto(idx);        // full repaint only when the photo changes

                    // Overlay is cheap but repaints a strip of the photo, so only
                    // redraw it when the displayed minute actually changes.
                    if (now.tm_min != lastClockMinute) {
                        lastClockMinute = now.tm_min;
                        String caption = (idx >= 0 && idx < (int)data.program.photos.size())
                                       ? data.program.photos[idx].caption : "";
                        DisplayUI::drawClockOverlay(photoJpeg, photoJpegLen, now,
                            data.settings.timeFormat, caption,
                            nextAlarmLabel(), dueMessageFor(now));
                    }
                }
            }
        }
    }

    delay(20);
}
