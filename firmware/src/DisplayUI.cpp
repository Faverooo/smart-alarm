#include "DisplayUI.h"
#include "config.h"
#include <TFT_eSPI.h>
#include <TJpg_Decoder.h>

static TFT_eSPI tft;

// TJpg_Decoder pushes decoded 16x16/8x8 blocks here; we blit them straight to
// the panel — no full framebuffer, so a 320x240 photo fits in tiny RAM.
static bool tftBlit(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t* bitmap) {
    if (y >= tft.height()) return false;
    tft.pushImage(x, y, w, h, bitmap);
    return true;
}

namespace DisplayUI {

void begin() {
    // Backlight PWM.
    ledcSetup(BL_LEDC_CHANNEL, BL_LEDC_FREQ, BL_LEDC_RESOLUTION);
    ledcAttachPin(BL_PIN, BL_LEDC_CHANNEL);
    setBrightness(80);

    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);

    tft.init();
    tft.setRotation(1);            // landscape 320x240
    tft.fillScreen(TFT_BLACK);

    TJpgDec.setJpgScale(1);
    TJpgDec.setSwapBytes(true);
    TJpgDec.setCallback(tftBlit);
}

void setBrightness(int pct) {
    pct = constrain(pct, 0, 100);
    ledcWrite(BL_LEDC_CHANNEL, map(pct, 0, 100, 0, 255));
}

void buzzerOn(int volumePct) {
    // Passive buzzer: a ~2.7kHz tone. "Volume" loosely maps to PWM duty.
    ledcSetup(BUZZER_LEDC_CHANNEL, 2700, BUZZER_LEDC_RESOLUTION);
    ledcAttachPin(BUZZER_PIN, BUZZER_LEDC_CHANNEL);
    ledcWrite(BUZZER_LEDC_CHANNEL, map(constrain(volumePct, 0, 100), 0, 100, 0, 200));
}
void buzzerOff() {
    ledcWrite(BUZZER_LEDC_CHANNEL, 0);
    ledcDetachPin(BUZZER_PIN);
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);
}

void showBooting(const String& line) {
    tft.fillScreen(TFT_BLACK);
    tft.setTextColor(TFT_WHITE, TFT_BLACK);
    tft.setTextDatum(MC_DATUM);
    tft.drawString("Smart Alarm", tft.width() / 2, tft.height() / 2 - 20, 4);
    tft.drawString(line, tft.width() / 2, tft.height() / 2 + 20, 2);
}

void showWifiPortal(const String& apName) {
    tft.fillScreen(TFT_NAVY);
    tft.setTextColor(TFT_WHITE, TFT_NAVY);
    tft.setTextDatum(MC_DATUM);
    tft.drawString("WiFi setup", tft.width() / 2, 60, 4);
    tft.drawString("Connect to WiFi network:", tft.width() / 2, 120, 2);
    tft.drawString(apName, tft.width() / 2, 150, 4);
    tft.drawString("then open the portal page", tft.width() / 2, 190, 2);
}

void showPairing(const String& code, const String& mac, long secondsLeft) {
    tft.fillScreen(TFT_BLACK);
    tft.setTextDatum(MC_DATUM);
    tft.setTextColor(TFT_CYAN, TFT_BLACK);
    tft.drawString("Pair this device", tft.width() / 2, 40, 4);

    tft.setTextColor(TFT_WHITE, TFT_BLACK);
    tft.drawString("Enter this code in the app:", tft.width() / 2, 90, 2);
    tft.setTextColor(TFT_GREENYELLOW, TFT_BLACK);
    tft.drawString(code, tft.width() / 2, 140, 7);   // big 7-seg style

    tft.setTextColor(TFT_DARKGREY, TFT_BLACK);
    tft.drawString(mac, tft.width() / 2, 195, 2);
    if (secondsLeft > 0) {
        long m = secondsLeft / 60, s = secondsLeft % 60;
        char buf[32];
        snprintf(buf, sizeof(buf), "expires in %ld:%02ld", m, s);
        tft.drawString(buf, tft.width() / 2, 220, 2);
    }
}

bool drawPhoto(const uint8_t* jpeg, size_t len) {
    return TJpgDec.drawJpg(0, 0, jpeg, len) == JDR_OK;
}

void drawClock(const struct tm& now, const String& timeFormat,
               const String& caption, const String& nextAlarm,
               const String& dueMessage) {
    // Semi-transparent-ish bands over the photo for legibility.
    int W = tft.width(), H = tft.height();

    char tbuf[16];
    if (timeFormat == "12") {
        int h12 = now.tm_hour % 12; if (h12 == 0) h12 = 12;
        snprintf(tbuf, sizeof(tbuf), "%d:%02d", h12, now.tm_min);
    } else {
        snprintf(tbuf, sizeof(tbuf), "%02d:%02d", now.tm_hour, now.tm_min);
    }

    char dbuf[32];
    strftime(dbuf, sizeof(dbuf), "%a %d %b", &now);

    // Top band: clock + date.
    tft.fillRect(0, 0, W, 70, TFT_BLACK);
    tft.setTextColor(TFT_WHITE, TFT_BLACK);
    tft.setTextDatum(TL_DATUM);
    tft.drawString(tbuf, 8, 6, 7);
    tft.setTextDatum(TR_DATUM);
    tft.drawString(dbuf, W - 8, 10, 4);
    if (timeFormat == "12") {
        tft.setTextDatum(TR_DATUM);
        tft.drawString(now.tm_hour < 12 ? "AM" : "PM", W - 8, 44, 2);
    }

    // Bottom band: caption + next alarm.
    tft.fillRect(0, H - 40, W, 40, TFT_BLACK);
    tft.setTextColor(TFT_LIGHTGREY, TFT_BLACK);
    tft.setTextDatum(BL_DATUM);
    if (caption.length())  tft.drawString(caption, 8, H - 22, 2);
    if (nextAlarm.length()) {
        tft.setTextDatum(BR_DATUM);
        tft.setTextColor(TFT_CYAN, TFT_BLACK);
        tft.drawString("next " + nextAlarm, W - 8, H - 22, 2);
    }

    // Optional message banner just under the clock.
    if (dueMessage.length()) {
        tft.fillRect(0, 74, W, 30, TFT_MAROON);
        tft.setTextColor(TFT_WHITE, TFT_MAROON);
        tft.setTextDatum(ML_DATUM);
        tft.drawString(dueMessage, 8, 89, 2);
    }
}

void showRinging(const String& alarmTime, const String& caption) {
    tft.fillScreen(TFT_RED);
    tft.setTextColor(TFT_WHITE, TFT_RED);
    tft.setTextDatum(MC_DATUM);
    tft.drawString(alarmTime, tft.width() / 2, 90, 7);
    if (caption.length())
        tft.drawString(caption, tft.width() / 2, 150, 4);
    tft.drawString("press DISMISS to stop", tft.width() / 2, 200, 2);
    tft.drawString("SNOOZE = +9 min", tft.width() / 2, 222, 2);
}

} // namespace DisplayUI
