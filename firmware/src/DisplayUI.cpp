#include "DisplayUI.h"
#include "config.h"
#include <TFT_eSPI.h>
#include <TJpg_Decoder.h>
#include <FS.h>
#include <LittleFS.h>

static TFT_eSPI tft;

// Optional anti-aliased clock font. If data/clock.vlw is present on LittleFS we
// load it for the hero time (true iPhone-style numerals); otherwise we fall back
// to the built-in font 6 so the firmware still works with no asset.
static const char* CLOCK_FONT = "clock";   // → /clock.vlw
static bool clockFontAvailable = false;

// When set, the JPEG decoder's output is redirected into this sprite instead of
// the panel — used to composite the clock over the photo background off-screen
// (no on-panel flicker, and smooth-font anti-aliasing blends against the real
// pixels rather than unreliable ILI9341 read-backs). g_capX/Y is the sprite's
// top-left in screen coordinates, so a screen block at (x,y) lands at (x-g_capX,
// y-g_capY); blocks outside the sprite are clipped by TFT_eSprite::pushImage.
static TFT_eSprite* g_capture = nullptr;
static int g_capX = 0, g_capY = 0;

// TJpg_Decoder pushes decoded 16x16/8x8 blocks here; we blit them straight to
// the panel — no full framebuffer, so a 320x240 photo fits in tiny RAM — unless
// a capture sprite is active, in which case they go there (clipped to its area).
static bool tftBlit(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t* bitmap) {
    if (g_capture) {
        g_capture->pushImage(x - g_capX, y - g_capY, w, h, bitmap);
        return true;                       // keep decoding; out-of-band blocks clip
    }
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

    pinMode(BTN_LED_PIN, OUTPUT);
    digitalWrite(BTN_LED_PIN, LOW);

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
    digitalWrite(BTN_LED_PIN, HIGH);
#if BUZZER_ACTIVE
    // Active 3-pin module: it has its own oscillator. We just trigger it.
    // No volume control possible — the tone/level is fixed in the module.
    (void)volumePct;
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, BUZZER_ACTIVE_HIGH ? HIGH : LOW);
#else
    // Passive buzzer: a ~2.7kHz tone. "Volume" loosely maps to PWM duty.
    ledcSetup(BUZZER_LEDC_CHANNEL, 2700, BUZZER_LEDC_RESOLUTION);
    ledcAttachPin(BUZZER_PIN, BUZZER_LEDC_CHANNEL);
    ledcWrite(BUZZER_LEDC_CHANNEL, map(constrain(volumePct, 0, 100), 0, 100, 0, 200));
#endif
}
void buzzerOff() {
    digitalWrite(BTN_LED_PIN, LOW);
#if BUZZER_ACTIVE
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, BUZZER_ACTIVE_HIGH ? LOW : HIGH);  // idle = not triggered
#else
    ledcWrite(BUZZER_LEDC_CHANNEL, 0);
    ledcDetachPin(BUZZER_PIN);
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);
#endif
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
    tft.drawString(code, tft.width() / 2, 140, 4);

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

// Draw `s` with a dark outline halo so it stays legible over ANY photo, then the
// fill colour on top. `r` is the halo radius in px (1 = subtle, 2 = bold). Text
// background is transparent, so the photo shows through around the glyphs. Works
// on the panel or a sprite (`g`). `font` >= 0 selects a built-in numbered font;
// `font` < 0 uses the smooth (VLW) font the caller has already loaded on `g`.
static void halo(TFT_eSPI& g, const String& s, int x, int y, int font,
                 uint8_t datum, uint16_t fill, int r) {
    if (!s.length()) return;
    g.setTextDatum(datum);
    g.setTextColor(TFT_BLACK);              // transparent bg → halo only
    for (int dx = -r; dx <= r; dx++)
        for (int dy = -r; dy <= r; dy++)
            if (dx || dy) {
                if (font >= 0) g.drawString(s, x + dx, y + dy, font);
                else           g.drawString(s, x + dx, y + dy);
            }
    g.setTextColor(fill);
    if (font >= 0) g.drawString(s, x, y, font);
    else           g.drawString(s, x, y);
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

// Composite the hero clock string over the photo background in a small
// off-screen sprite, then blit it in one shot. The sprite is sized to the
// digits ("00:00" is the widest H:MM) for the active font, so RAM stays tiny
// (~13KB with the built-in font, ~40KB with a 72px VLW) and a per-minute redraw
// neither flickers nor repaints the rest of the screen. Smooth-font AA blends
// against the real photo pixels held in the sprite — no flaky ILI9341 reads.
static void drawHeroTime(const uint8_t* jpeg, size_t len, const String& s) {
    const int x = 16, y = 164, r = 2;      // screen position (top-left) + halo radius

    // Measure the band for whichever font is active.
    int bw, bh;
    if (clockFontAvailable) {
        tft.loadFont(CLOCK_FONT, LittleFS);
        bw = tft.textWidth("00:00"); bh = tft.fontHeight();
        tft.unloadFont();
    } else {
        bw = tft.textWidth("00:00", 6); bh = tft.fontHeight(6);
    }
    const int bx = x - r, by = y - r;
    const int w  = bw + 2 * r + 2, h = bh + 2 * r + 2;

    TFT_eSprite spr(&tft);
    spr.setColorDepth(16);
    if (spr.createSprite(w, h)) {
        // Fill the sprite with the photo pixels under the band (or black).
        if (jpeg && len) {
            g_capture = &spr; g_capX = bx; g_capY = by;
            TJpgDec.drawJpg(0, 0, jpeg, len);
            g_capture = nullptr;
        } else {
            spr.fillSprite(TFT_BLACK);
        }
        if (clockFontAvailable) spr.loadFont(CLOCK_FONT, LittleFS);
        halo(spr, s, x - bx, y - by, clockFontAvailable ? -1 : 6, TL_DATUM, TFT_WHITE, r);
        if (clockFontAvailable) spr.unloadFont();
        spr.pushSprite(bx, by);
        spr.deleteSprite();
    } else {
        // Out of RAM: composite straight on the panel (one small flash, but the
        // clock never stalls). repaintStrip clips to the band via a viewport.
        repaintStrip(jpeg, len, by, h);
        if (clockFontAvailable) tft.loadFont(CLOCK_FONT, LittleFS);
        halo(tft, s, x, y, clockFontAvailable ? -1 : 6, TL_DATUM, TFT_WHITE, r);
        if (clockFontAvailable) tft.unloadFont();
    }
}

void drawClockOverlay(const uint8_t* jpeg, size_t len,
                      const struct tm& now, const String& timeFormat,
                      const String& caption, const String& nextAlarm,
                      const String& dueMessage, bool full) {
    const int W = tft.width(), H = tft.height();

    // Build the strings up front so we can tell what actually changed.
    char dbuf[40];
    strftime(dbuf, sizeof(dbuf), "%A %e %b", &now);   // e.g. "Monday  9 Jun"
    String date = dbuf;

    char tbuf[8];
    bool h12 = (timeFormat == "12");
    int hh = now.tm_hour;
    if (h12) { hh %= 12; if (hh == 0) hh = 12; }
    snprintf(tbuf, sizeof(tbuf), h12 ? "%d:%02d" : "%02d:%02d", hh, now.tm_min);
    String timeStr = tbuf;
    String ampm = h12 ? (now.tm_hour < 12 ? "AM" : "PM") : String();

    // What was on screen last time, so a plain minute tick only redraws the time.
    static String pDate, pCaption, pNext, pMsg, pAmpm;

    // A full repaint is only needed when something OTHER than H:MM changed: the
    // background was just redrawn (`full`), the day rolled over, AM/PM flipped, or
    // the caption / next-alarm / message text changed. Otherwise we update only
    // the hero clock — no flicker, the rest of the photo is left untouched.
    bool heavy = full || date != pDate || ampm != pAmpm || caption != pCaption
              || nextAlarm != pNext || dueMessage != pMsg;

    if (heavy) {
        // ---- bottom block: date + hero time + caption / next alarm ----------
        const int sy = 104, sh = H - 104;
        repaintStrip(jpeg, len, sy, sh);

        // Date sits just above the clock band (drawHeroTime owns y=162 down).
        halo(tft, date, 18, 136, 4, TL_DATUM, TFT_WHITE, 1);
        // Hero clock — VLW smooth font if installed, else font 6. iOS feel.
        drawHeroTime(jpeg, len, timeStr);

        if (h12)  // small AM/PM under the time
            halo(tft, ampm, 18, 220, 2, TL_DATUM, TFT_WHITE, 1);
        if (caption.length())
            halo(tft, caption, 18, H - 6, 2, BL_DATUM, TFT_WHITE, 1);
        if (nextAlarm.length())
            halo(tft, "next " + nextAlarm, W - 14, H - 6, 2, BR_DATUM, TFT_CYAN, 1);

        // ---- top: optional message -----------------------------------------
        repaintStrip(jpeg, len, 0, 40);
        if (dueMessage.length())
            halo(tft, dueMessage, W / 2, 12, 4, TC_DATUM, TFT_YELLOW, 1);
    } else {
        // Only the minute advanced → repaint just the clock digits, no flicker.
        drawHeroTime(jpeg, len, timeStr);
    }

    pDate = date; pCaption = caption; pNext = nextAlarm; pMsg = dueMessage; pAmpm = ampm;
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
