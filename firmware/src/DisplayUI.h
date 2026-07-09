#pragma once
#include <Arduino.h>
#include "types.h"

// All TFT drawing + backlight/buzzer control. Keeps TFT_eSPI and TJpg_Decoder
// behind a small surface so main.cpp stays about orchestration, not pixels.
namespace DisplayUI {
    void begin();

    // First-run / waiting screens.
    void showBooting(const String& line);
    void showWifiPortal(const String& apName);
    void showPairing(const String& code, const String& mac, long secondsLeft);

    // Decode + blit a JPEG (from heap) as the full-screen background. Returns
    // false if decoding failed (caller can keep the previous frame).
    bool drawPhoto(const uint8_t* jpeg, size_t len);

    // iOS-lock-screen style overlay: the photo stays clear in the background;
    // the clock/date/caption float over it in the lower-left with a light drop
    // shadow for legibility (no solid bars). `jpeg` may be null (no program) →
    // the text strips fall back to black.
    //
    // A plain minute tick repaints ONLY the hero clock (composited off-screen in
    // a small sprite → no flicker, the rest of the photo is untouched). Pass
    // `full = true` to force a complete repaint of the overlay — required after
    // the background was redrawn (new photo, ringing/OTA screen restored). The
    // call also promotes itself to a full repaint automatically when the date,
    // AM/PM, caption, next-alarm or message text changes.
    //
    // `nextAlarm` is the "HH:MM" time and `nextAlarmDate` a friendly day label
    // ("Today" / "Tomorrow" / "Thu 26 Jun"); the date is drawn above the time in
    // the bottom-right corner. Both empty when no alarm is upcoming.
    void drawClockOverlay(const uint8_t* jpeg, size_t len,
                          const struct tm& now, const String& timeFormat,
                          const String& caption, const String& nextAlarm,
                          const String& nextAlarmDate, const String& dueMessage,
                          bool full = true);

    // Full-screen ringing overlay shown while an alarm is firing.
    void showRinging(const String& alarmTime, const String& caption);

    // Backlight brightness 0..100 (mapped to PWM duty).
    void setBrightness(int pct);

    // Real panel power-down / wake. Unlike setBrightness(0) — which only kills the
    // LED backlight while the controller keeps driving the pixels (a static DC bias
    // that causes LCD image sticking over hours/days) — these send the ILI9341
    // SLPIN/SLPOUT + DISPOFF/DISPON commands so the panel actually stops driving.
    // Use them for night/idle instead of dimming to 0. displayOn() leaves the
    // backlight off (call setBrightness after) and GRAM may be cleared, so force a
    // full repaint of the current frame afterwards.
    void displayOff();
    void displayOn();

    // Anti burn-in: shift the whole UI (photo + overlay) by a few px. After
    // changing it, repaint the frame (drawPhoto + a full drawClockOverlay). The
    // photo is panel-sized so a non-zero shift leaves a small black edge.
    void setPixelShift(int dx, int dy);

    // Buzzer control for the alarm (volume 0..100).
    void buzzerOn(int volumePct);
    void buzzerOff();
}
