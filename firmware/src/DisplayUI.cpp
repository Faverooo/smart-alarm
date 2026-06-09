#include "DisplayUI.h"
#include "config.h"
#include <TFT_eSPI.h>
#include <TJpg_Decoder.h>
#include <FS.h>
#include <LittleFS.h>

static TFT_eSPI tft;

// Optional anti-aliased clock font. If data/clock.vlw is present on LittleFS we
// load it for the hero time (true iPhone-style numerals); otherwise we fall back
// to the built-in 7-seg font 7 so the firmware still works with no asset.
static const char* CLOCK_FONT = "clock";   // → /clock.vlw
static bool clockFontAvailable = false;

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

    // Mount the font filesystem (format if blank) and look for the clock font.
    if (LittleFS.begin(true))
        clockFontAvailable = LittleFS.exists("/" + String(CLOCK_FONT) + ".vlw");
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

// Draw `s` with a dark outline halo so it stays legible over ANY photo, then
// the fill colour on top. `r` is the halo radius in px (1 = subtle, 2 = bold).
// Text background is transparent, so the photo shows through around the glyphs.
static void drawHalo(const String& s, int x, int y, uint8_t font,
                     uint8_t datum, uint16_t fill, int r) {
    if (!s.length()) return;
    tft.setTextDatum(datum);
    tft.setTextColor(TFT_BLACK);            // transparent bg → halo only
    for (int dx = -r; dx <= r; dx++)
        for (int dy = -r; dy <= r; dy++)
            if (dx || dy) tft.drawString(s, x + dx, y + dy, font);
    tft.setTextColor(fill);
    tft.drawString(s, x, y, font);
}

// Hero clock string with the same outline-halo treatment. Uses the loaded VLW
// smooth font when available (drawn transparent so the photo shows through),
// else the built-in 7-seg font 7. Halo radius `r` keeps it readable over photos.
static void drawHeroTime(const String& s, int x, int y,
                         uint8_t datum, uint16_t fill, int r) {
    if (!clockFontAvailable) { drawHalo(s, x, y, 7, datum, fill, r); return; }

    tft.loadFont(CLOCK_FONT, LittleFS);    // smooth font ignores font-number args
    tft.setTextDatum(datum);
    tft.setTextColor(TFT_BLACK);
    for (int dx = -r; dx <= r; dx++)
        for (int dy = -r; dy <= r; dy++)
            if (dx || dy) tft.drawString(s, x + dx, y + dy);
    tft.setTextColor(fill);
    tft.drawString(s, x, y);
    tft.unloadFont();                      // restore the numbered fonts
}

// Repaint just the strip [0, sy]..[W, sy+sh] from the cached photo (or black),
// clipped via a viewport so the rest of the screen is left untouched. Using
// vpDatum=false keeps TJpgDec's pushImage coordinates absolute.
static void repaintStrip(const uint8_t* jpeg, size_t len, int sy, int sh) {
    tft.setViewport(0, sy, tft.width(), sh, false);
    if (jpeg && len) TJpgDec.drawJpg(0, 0, jpeg, len);
    else             tft.fillRect(0, sy, tft.width(), sh, TFT_BLACK);
    tft.resetViewport();
}

void drawClockOverlay(const uint8_t* jpeg, size_t len,
                      const struct tm& now, const String& timeFormat,
                      const String& caption, const String& nextAlarm,
                      const String& dueMessage) {
    const int W = tft.width(), H = tft.height();

    // ---- bottom block: date + hero time + caption / next alarm --------------
    // Strip is sized for the taller VLW font; the built-in fallback just leaves
    // a little extra clear space above the digits.
    const int sy = 104, sh = H - 104;
    repaintStrip(jpeg, len, sy, sh);

    char dbuf[40];
    strftime(dbuf, sizeof(dbuf), "%A %e %b", &now);   // e.g. "Monday  9 Jun"
    drawHalo(dbuf, 18, 112, 4, TL_DATUM, TFT_WHITE, 1);

    char tbuf[8];
    bool h12 = (timeFormat == "12");
    int hh = now.tm_hour;
    if (h12) { hh %= 12; if (hh == 0) hh = 12; }
    snprintf(tbuf, sizeof(tbuf), h12 ? "%d:%02d" : "%02d:%02d", hh, now.tm_min);
    // Hero clock — VLW smooth font if installed, else 7-seg. iOS-lock-screen feel.
    drawHeroTime(tbuf, 14, 138, TL_DATUM, TFT_WHITE, 2);

    if (h12)  // small AM/PM under the time
        drawHalo(now.tm_hour < 12 ? "AM" : "PM", 16, 216, 2, TL_DATUM, TFT_WHITE, 1);

    if (caption.length())
        drawHalo(caption, 18, H - 6, 2, BL_DATUM, TFT_WHITE, 1);
    if (nextAlarm.length())
        drawHalo("next " + nextAlarm, W - 14, H - 6, 2, BR_DATUM, TFT_CYAN, 1);

    // ---- top: optional message ----------------------------------------------
    // Repaint while a message is showing (so a full photo redraw can't wipe it),
    // and wipe the strip once when the message clears.
    static String lastMsg = "\x01";   // sentinel so the first call always paints
    if (dueMessage.length()) {
        repaintStrip(jpeg, len, 0, 40);
        drawHalo(dueMessage, W / 2, 12, 4, TC_DATUM, TFT_YELLOW, 1);
    } else if (dueMessage != lastMsg) {
        repaintStrip(jpeg, len, 0, 40);
    }
    lastMsg = dueMessage;
}

void showRinging(const String& alarmTime, const String& caption) {
    tft.fillScreen(TFT_RED);
    tft.setTextColor(TFT_WHITE, TFT_RED);
    tft.setTextDatum(MC_DATUM);
    tft.drawString(alarmTime, tft.width() / 2, 90, 7);
    if (caption.length())
        tft.drawString(caption, tft.width() / 2, 150, 4);
    tft.drawString("hold button = stop", tft.width() / 2, 200, 2);
    tft.drawString("press = snooze 9 min", tft.width() / 2, 222, 2);
}

} // namespace DisplayUI
