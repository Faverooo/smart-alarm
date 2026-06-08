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

    // Main clock face drawn over (or instead of) the photo: time, date, caption,
    // next-alarm hint and an optional due message banner.
    void drawClock(const struct tm& now, const String& timeFormat,
                   const String& caption, const String& nextAlarm,
                   const String& dueMessage);

    // Full-screen ringing overlay shown while an alarm is firing.
    void showRinging(const String& alarmTime, const String& caption);

    // Backlight brightness 0..100 (mapped to PWM duty).
    void setBrightness(int pct);

    // Buzzer control for the alarm (volume 0..100).
    void buzzerOn(int volumePct);
    void buzzerOff();
}
