#include "DisplayUI.h"
#include "config.h"
#include <TFT_eSPI.h>
#include <TJpg_Decoder.h>

// Anti-aliased fonts embedded as flash arrays (no asset upload, always smooth):
// NotoSans Bold at 36 px (headings) and 15 px (labels), plus a big 60 px clock
// face (Arial Bold, digits + ':' only — see tools/genclock.py) for the hero time.
#include "NotoSansBold15.h"
#include "NotoSansBold36.h"
#include "ClockFont.h"

static TFT_eSPI tft;

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
}

void setBrightness(int pct) {
    pct = constrain(pct, 0, 100);
    ledcWrite(BL_LEDC_CHANNEL, map(pct, 0, 100, 0, 255));
}

// ILI9341 power commands. Sending SLPIN stops the panel from driving the pixels
// (no more static DC bias on the liquid crystals → no image sticking); DISPOFF
// blanks the output. We kill the backlight first so the DISPOFF transition isn't
// visible as a flash.
void displayOff() {
    setBrightness(0);
    tft.writecommand(0x28);   // DISPOFF
    tft.writecommand(0x10);   // SLPIN — controller stops refreshing the panel
    // Datasheet: after SLPIN wait 120 ms before a SLPOUT is allowed. An alarm can
    // fire on the very tick brightness hits 0 and immediately call displayOn(), so
    // pay the full 120 ms here rather than assume the next wake is far off.
    delay(120);
}

// Wake the panel. Datasheet: after SLPOUT wait 120 ms before DISPON / drawing.
// GRAM contents are undefined after sleep, so the caller must repaint the frame.
// Backlight is left at 0 here — call setBrightness() after to bring it back.
void displayOn() {
    tft.writecommand(0x11);   // SLPOUT
    delay(120);
    tft.writecommand(0x29);   // DISPON
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

// ---- Anti-aliased type sizes -------------------------------------------------
// Largest → smallest: fitVlw() picks the biggest that fits the width, else
// ellipsizes. 36 px for the clock & headings, 15 px for labels.
static const uint8_t* const LADDER_BIG[]  = { NotoSansBold36, NotoSansBold15 };
static const uint8_t* const LADDER_TEXT[] = { NotoSansBold15 };

// ---- Hero clock geometry (shared by drawHeroTime + drawClockOverlay) ---------
static const int CLOCK_X = 16, CLOCK_Y = 162;   // top-left of the big time

// ---- Burn-in pixel shift -----------------------------------------------------
// A tiny offset added to the whole UI — photo AND overlay together — so no pixel
// is driven identically for long. Kept small (a few px) so overlay elements stay
// well inside the strips repaintStrip() clears and the photo's uncovered edge is
// just a thin black margin. main.cpp cycles it every so often.
static int gShiftX = 0, gShiftY = 0;

// Set the anti-burn-in offset applied to the whole UI (photo + overlay). The
// caller must then repaint the frame (drawPhoto + a full drawClockOverlay) so
// the new offset takes effect. Kept small by convention (a few px).
void setPixelShift(int dx, int dy) {
    gShiftX = dx;
    gShiftY = dy;
}

// ---- Drop shadow --------------------------------------------------------------
// One light stamp of the glyphs 1 px down-right in black, then the fill on top.
// The AA font keeps it soft — a subtle lift off the photo, no halo or box.
static const int SHADOW_DX = 1, SHADOW_DY = 1, SHADOW_PAD = 2;

// Background the text floats over: a photo (decoded into the work sprite) when
// `jpeg` is set, otherwise a solid colour fill.
struct Bg { const uint8_t* jpeg = nullptr; size_t len = 0; uint16_t solid = TFT_BLACK; };

// Pick the largest font on `ladder` whose render of `s` fits within `maxW`; if
// even the smallest overflows, ellipsize `s` ("..."). Returns the chosen font.
static const uint8_t* fitVlw(String& s, const uint8_t* const* ladder, int n, int maxW) {
    for (int i = 0; i < n; i++) {
        tft.loadFont(ladder[i]);
        int w = tft.textWidth(s);
        tft.unloadFont();
        if (w <= maxW) return ladder[i];
    }
    tft.loadFont(ladder[n - 1]);
    while (s.length() > 1 && tft.textWidth(s + "...") > maxW) s.remove(s.length() - 1);
    tft.unloadFont();
    s += "...";
    return ladder[n - 1];
}

// Render `s` at (ax,ay)/`datum` in `font` with the light drop shadow. AA text
// needs a clean background to blend its edges against, and ILI9341 read-back is
// unreliable, so we composite in an off-screen sprite holding the real photo (or
// solid colour) and blit once — smooth, no flicker, rest of the screen untouched.
// `minW` forces the composited/erased band to be at least this wide (the text
// stays left-anchored inside it). Only the left-aligned hero clock uses it, so a
// shrinking string (12h "12:59" -> "1:00") still clears the wider previous glyphs
// on the per-minute redraw. 0 (default) = size the band exactly to the text.
static void shadowText(const String& s, int ax, int ay, uint8_t datum,
                       uint16_t fill, const uint8_t* font, const Bg& bg,
                       int minW = 0) {
    if (!s.length()) return;

    tft.loadFont(font);
    int tw = tft.textWidth(s), th = tft.fontHeight();
    tft.unloadFont();
    if (tw <= 0 || th <= 0) return;

    const int bandW = tw > minW ? tw : minW;   // erase at least `minW` px wide
    const int padTL = 1, padBR = SHADOW_PAD + 1;
    const int W = bandW + padTL + padBR, H = th + padTL + padBR;

    // datum → screen top-left of the text box (col: 0=L 1=C 2=R, row: 0=T 1=M 2=B).
    int col = datum % 3, row = (datum / 3) % 3;
    int tx = ax - (col == 1 ? tw / 2 : col == 2 ? tw : 0);
    int ty = ay - (row == 1 ? th / 2 : row == 2 ? th : 0);
    const int ox = tx - padTL, oy = ty - padTL;    // sprite origin on screen

    TFT_eSprite spr(&tft);
    spr.setColorDepth(16);
    if (!spr.createSprite(W, H)) {                 // out of RAM → draw straight
        tft.loadFont(font);
        tft.setTextDatum(datum);
        tft.setTextColor(fill);
        tft.drawString(s, ax, ay);
        tft.unloadFont();
        return;
    }
    if (bg.jpeg && bg.len) {
        g_capture = &spr; g_capX = ox; g_capY = oy;
        // Photo is shifted on screen by (gShiftX,gShiftY); decode it at that same
        // offset so the AA text blends over the correct (shifted) photo pixels.
        TJpgDec.drawJpg(gShiftX, gShiftY, bg.jpeg, bg.len);
        g_capture = nullptr;
    } else {
        spr.fillSprite(bg.solid);
    }

    spr.loadFont(font);
    spr.setTextDatum(TL_DATUM);
    spr.setTextColor(TFT_BLACK);                   // light cast shadow, down-right
    spr.drawString(s, padTL + SHADOW_DX, padTL + SHADOW_DY);
    spr.setTextColor(fill);                        // crisp AA fill on top
    spr.drawString(s, padTL, padTL);
    spr.unloadFont();

    spr.pushSprite(ox, oy);
    spr.deleteSprite();
}

// Auto-fit a label to `maxW`, then draw it with the drop shadow. `s` is taken by
// value because fitVlw may ellipsize it.
static void drawFit(String s, int x, int y, uint8_t datum, uint16_t fill,
                    const uint8_t* const* ladder, int n, int maxW, const Bg& bg) {
    if (!s.length()) return;
    shadowText(s, x, y, datum, fill, fitVlw(s, ladder, n, maxW), bg);
}

void showBooting(const String& line) {
    const int W = tft.width(), H = tft.height();
    tft.fillScreen(TFT_BLACK);
    Bg bg{nullptr, 0, TFT_BLACK};
    drawFit("Smart Alarm", W / 2, H / 2 - 30, MC_DATUM, TFT_WHITE,
            LADDER_BIG, 2, W - 28, bg);
    drawFit(line, W / 2, H / 2 + 22, MC_DATUM, TFT_LIGHTGREY,
            LADDER_TEXT, 1, W - 28, bg);
}

void showWifiPortal(const String& apName) {
    const int W = tft.width();
    tft.fillScreen(TFT_NAVY);
    Bg bg{nullptr, 0, TFT_NAVY};
    drawFit("Wi-Fi setup", W / 2, 44, MC_DATUM, TFT_WHITE,
            LADDER_BIG, 2, W - 28, bg);
    drawFit("Connect to this network:", W / 2, 102, MC_DATUM, TFT_LIGHTGREY,
            LADDER_TEXT, 1, W - 28, bg);
    drawFit(apName, W / 2, 138, MC_DATUM, TFT_GREENYELLOW,
            LADDER_BIG, 2, W - 28, bg);
    drawFit("then open the portal page", W / 2, 186, MC_DATUM, TFT_LIGHTGREY,
            LADDER_TEXT, 1, W - 28, bg);
}

void showPairing(const String& code, const String& mac, long secondsLeft) {
    const int W = tft.width();
    tft.fillScreen(TFT_BLACK);
    Bg bg{nullptr, 0, TFT_BLACK};
    drawFit("Pair this device", W / 2, 28, MC_DATUM, TFT_CYAN,
            LADDER_BIG, 2, W - 28, bg);
    drawFit("Enter this code in the app", W / 2, 74, MC_DATUM, TFT_LIGHTGREY,
            LADDER_TEXT, 1, W - 28, bg);
    drawFit(code, W / 2, 126, MC_DATUM, TFT_GREENYELLOW,
            LADDER_BIG, 2, W - 24, bg);
    drawFit(mac, W / 2, 184, MC_DATUM, TFT_DARKGREY,
            LADDER_TEXT, 1, W - 24, bg);
    if (secondsLeft > 0) {
        long m = secondsLeft / 60, s = secondsLeft % 60;
        char buf[32];
        snprintf(buf, sizeof(buf), "expires in %ld:%02ld", m, s);
        drawFit(buf, W / 2, 216, MC_DATUM, TFT_LIGHTGREY,
                LADDER_TEXT, 1, W - 24, bg);
    }
}

bool drawPhoto(const uint8_t* jpeg, size_t len) {
    // Drawn at the anti-burn-in offset. The photo is exactly panel-sized, so a
    // non-zero shift leaves a few-px strip uncovered on one/two edges — clear to
    // black first so no stale content shows there.
    if (gShiftX || gShiftY) tft.fillScreen(TFT_BLACK);
    return TJpgDec.drawJpg(gShiftX, gShiftY, jpeg, len) == JDR_OK;
}

// Repaint just the strip [0, sy]..[W, sy+sh] from the cached photo (or black),
// clipped via a viewport so the rest of the screen is left untouched. Using
// vpDatum=false keeps TJpgDec's pushImage coordinates absolute. The photo is
// drawn at the pixel-shift offset to match drawPhoto(); the ≤few-px uncovered
// edge only ever falls in the screen margin (no text lives there).
static void repaintStrip(const uint8_t* jpeg, size_t len, int sy, int sh) {
    tft.setViewport(0, sy, tft.width(), sh, false);
    if (jpeg && len) TJpgDec.drawJpg(gShiftX, gShiftY, jpeg, len);
    else             tft.fillRect(0, sy, tft.width(), sh, TFT_BLACK);
    tft.resetViewport();
}

// Width/height of the hero "00:00" band, to size the sprite and place the AM/PM
// tag beside the digits.
static void measureHero(int& w, int& h) {
    tft.loadFont(ClockFont);
    w = tft.textWidth("00:00"); h = tft.fontHeight();
    tft.unloadFont();
}

// Draw the hero clock over the photo background with the drop shadow. shadowText()
// composites in an off-screen sprite, so the per-minute redraw neither flickers
// nor touches the rest of the photo.
static void drawHeroTime(const uint8_t* jpeg, size_t len, const String& s) {
    // Always erase a full "00:00"-wide band so a per-minute redraw that shrinks
    // the string (12h "12:59" -> "1:00") leaves no ghost of the wider digits.
    int cw, ch; measureHero(cw, ch);
    shadowText(s, CLOCK_X + gShiftX, CLOCK_Y + gShiftY, TL_DATUM, TFT_WHITE, ClockFont,
               {jpeg, len, TFT_BLACK}, cw);
}

void drawClockOverlay(const uint8_t* jpeg, size_t len,
                      const struct tm& now, const String& timeFormat,
                      const String& caption, const String& nextAlarm,
                      const String& nextAlarmDate, const String& dueMessage,
                      bool full) {
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
    static String pDate, pCaption, pNext, pNextDate, pMsg, pAmpm;

    // A full repaint is only needed when something OTHER than H:MM changed: the
    // background was just redrawn (`full`), the day rolled over, AM/PM flipped, or
    // the caption / next-alarm / message text changed. Otherwise we update only
    // the hero clock — no flicker, the rest of the photo is left untouched.
    bool heavy = full || date != pDate || ampm != pAmpm || caption != pCaption
              || nextAlarm != pNext || nextAlarmDate != pNextDate || dueMessage != pMsg;

    Bg photo{jpeg, len, TFT_BLACK};

    if (heavy) {
        // No program/photo → solid black behind everything (the strip repaints
        // below only cover top + bottom, so clear the whole panel here).
        if (!jpeg) tft.fillScreen(TFT_BLACK);
        else       repaintStrip(jpeg, len, 104, H - 104);   // restore photo, bottom

        // Compact, bottom-anchored cluster: caption hugs the bottom edge (same
        // baseline as the next-alarm corner), with the clock just above it and the
        // date just above that. The clock font is cropped tight to the digits
        // (band height `ch`) so the three lines stack close without the clock's
        // opaque sprite — repainted every minute — overlapping and erasing them.
        int cw, ch; measureHero(cw, ch);

        // Date just above the digits.
        drawFit(date, CLOCK_X + 2 + gShiftX, CLOCK_Y - 26 + gShiftY, TL_DATUM,
                TFT_WHITE, LADDER_TEXT, 1, W - 2 * CLOCK_X, photo);

        // Hero clock — 60 px clock face, composited over the photo.
        drawHeroTime(jpeg, len, timeStr);

        // AM/PM tag riding to the right of the digits (12h only).
        if (h12)
            drawFit(ampm, CLOCK_X + cw + 12 + gShiftX, CLOCK_Y + ch + gShiftY,
                    BL_DATUM, TFT_WHITE, LADDER_TEXT, 1, 64, photo);

        // Caption pinned to the bottom-left, aligned with the next-alarm corner.
        if (caption.length())
            drawFit(caption, CLOCK_X + gShiftX, H - 6 + gShiftY, BL_DATUM,
                    TFT_WHITE, LADDER_TEXT, 1, 168, photo);

        // Next alarm in the bottom-right: date line stacked above the time line.
        if (nextAlarm.length()) {
            if (nextAlarmDate.length())
                drawFit(nextAlarmDate, W - 14 + gShiftX, H - 28 + gShiftY, BR_DATUM,
                        TFT_WHITE, LADDER_TEXT, 1, 150, photo);
            drawFit("next " + nextAlarm, W - 14 + gShiftX, H - 6 + gShiftY, BR_DATUM,
                    TFT_CYAN, LADDER_TEXT, 1, 150, photo);
        }

        // ---- top: optional message -----------------------------------------
        if (jpeg) repaintStrip(jpeg, len, 0, 42);
        if (dueMessage.length())
            drawFit(dueMessage, W / 2 + gShiftX, 6 + gShiftY, TC_DATUM, TFT_YELLOW,
                    LADDER_TEXT, 1, W - 20, photo);
    } else {
        // Only the minute advanced → repaint just the clock digits, no flicker.
        drawHeroTime(jpeg, len, timeStr);
    }

    pDate = date; pCaption = caption; pNext = nextAlarm; pNextDate = nextAlarmDate;
    pMsg = dueMessage; pAmpm = ampm;
}

void showRinging(const String& alarmTime, const String& caption) {
    const int W = tft.width();
    tft.fillScreen(TFT_RED);
    Bg bg{nullptr, 0, TFT_RED};
    drawFit(alarmTime, W / 2, 78, MC_DATUM, TFT_WHITE,
            LADDER_BIG, 2, W - 20, bg);
    if (caption.length())
        drawFit(caption, W / 2, 138, MC_DATUM, TFT_WHITE,
                LADDER_BIG, 2, W - 24, bg);
    drawFit("hold button = stop", W / 2, 196, MC_DATUM, TFT_WHITE,
            LADDER_TEXT, 1, W - 24, bg);
    drawFit("press = snooze 9 min", W / 2, 222, MC_DATUM, TFT_WHITE,
            LADDER_TEXT, 1, W - 24, bg);
}

} // namespace DisplayUI
