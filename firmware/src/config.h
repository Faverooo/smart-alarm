#pragma once
// Smart Alarm firmware — all tunables in one place.
// Pins here are the ones OUR code drives (backlight, buzzer, buttons).
// The TFT SPI pins live in platformio.ini (TFT_eSPI reads them at compile time).

// ---- Backend -----------------------------------------------------------------
// ⚠ CHANGE ME: your server's https origin (no trailing /). Can also be set
// without editing this file via a build flag in platformio.ini:
//   -DSERVER_URL='"https://alarm.example.com"'
#ifndef SERVER_URL
#define SERVER_URL "https://alarm.example.com"
#endif
#define API_BASE   SERVER_URL "/api/v1"

// Cloudflare rotates edge certs, so pinning a leaf is fragile. For a hobby
// device on your own LAN we skip cert validation by default. Set this to 0 and
// fill ROOT_CA in ApiClient.cpp to validate the chain properly.
#define USE_INSECURE_TLS 1

// ---- Timekeeping -------------------------------------------------------------
// ⚠ CHANGE ME (if you're not in Italy): POSIX TZ string for YOUR timezone —
// it MUST describe the same zone as APP_TIMEZONE in the server's .env, because
// the whole system runs on local wall-clock time. Find yours here:
// https://github.com/nayarsystems/posix_tz_db/blob/master/zones.csv
// Default: Europe/Rome (CET/CEST with EU DST rules).
#ifndef TZ_INFO
#define TZ_INFO "CET-1CEST,M3.5.0,M10.5.0/3"
#endif
#define NTP_SERVER_1 "pool.ntp.org"
#define NTP_SERVER_2 "time.cloudflare.com"

// ---- Poll intervals (ms) -----------------------------------------------------
#define SYNC_INTERVAL_MS     60000UL    // GET /sync (cheap thanks to ETag/304)
#define TIME_INTERVAL_MS     300000UL   // GET /time fallback if NTP drifts/fails
#define STATUS_INTERVAL_MS   300000UL   // POST /device/status heartbeat
#define FIRMWARE_INTERVAL_MS 21600000UL // GET /firmware/latest (~6h)
#define PAIR_POLL_MS         5000UL     // GET /pair/status while waiting to be claimed
#define PHOTO_RETRY_MS       10000UL    // back-off between failed photo downloads
#define WIFI_RETRY_MS        10000UL    // back-off between WiFi.reconnect() attempts

// ---- Capacities (bounded so parsing can't blow the heap) ---------------------
#define MAX_ALARMS   16
#define MAX_PHOTOS   10
#define MAX_MESSAGES 8

// ---- GPIO we drive ourselves -------------------------------------------------
#define BL_PIN        21    // TFT backlight (PWM via LEDC for brightness control)
#define BUZZER_PIN    25    // buzzer signal pin (see BUZZER_ACTIVE below)
// Buzzer type:
//   BUZZER_ACTIVE 1 -> 3-pin active module (VCC/IO/GND, built-in oscillator):
//                      we just drive the IO pin HIGH/LOW, no PWM, no volume control.
//   BUZZER_ACTIVE 0 -> raw passive buzzer: we generate a ~2.7kHz PWM tone (volume = duty).
// BUZZER_ACTIVE_HIGH: 1 = sounds when IO is HIGH ("high level trigger"), 0 = sounds on LOW.
#define BUZZER_ACTIVE       1
#define BUZZER_ACTIVE_HIGH  1
// The one and only button (INPUT_PULLUP, wired to GND, active LOW):
//   ringing → short press = snooze, hold LONG_PRESS_MS = dismiss
//   idle    → short press = mark the on-screen message as read
//   boot    → held during power-on = factory reset
// (rotation photos advance on their own via interval_minutes — no manual "next")
#define BTN_PIN       32
#define BTN_LED_PIN   26    // LED inside the button, driven via BC547 NPN transistor

// ---- LEDC (PWM) channels -----------------------------------------------------
#define BL_LEDC_CHANNEL     0
#define BL_LEDC_FREQ        5000
#define BL_LEDC_RESOLUTION  8       // 0..255 duty
#define BUZZER_LEDC_CHANNEL 1
#define BUZZER_LEDC_RESOLUTION 8

// ---- Behaviour ---------------------------------------------------------------
#define SNOOZE_MINUTES 9
#define LONG_PRESS_MS  1000  // hold this long while ringing to dismiss (vs snooze)
#define ROTATION_MIN_INTERVAL 1      // floor for program interval_minutes
#define WIFI_PORTAL_NAME "SmartAlarm-Setup"

// Firmware version reported to the OTA endpoint and telemetry.
#define FIRMWARE_VERSION "0.1.0"
