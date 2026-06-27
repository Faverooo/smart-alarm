#pragma once
#include <Arduino.h>
#include <vector>

// Mirrors the /sync payload documented in docs/IMPLEMENTATION_GUIDE.md §2.3.
// All time fields are LOCAL wall-clock strings — no timezone math on device.

struct DisplaySettings {
    int  brightness      = 80;     // 0..100
    int  nightBrightness = 10;     // 0..100
    String nightStart    = "22:00";
    String nightEnd      = "07:00";
    int  volume          = 70;     // 0..100
    String timeFormat    = "24";   // "12" | "24"
};

struct Alarm {
    long   id = 0;
    String time;                   // "HH:MM"
    String scheduledDate;          // "YYYY-MM-DD" or "" for recurring
    std::vector<String> days;      // English weekday names, empty for one-time
};

struct ProgramPhoto {
    long   id = 0;
    String renderUrl;
    String caption;
    String scheduledFor;           // "YYYY-MM-DD" for daily, else ""
    String hash;                   // cache key — re-download only when it changes
};

struct Program {
    String type;                   // "fixed" | "rotation" | "daily"
    int    intervalMinutes = 0;    // rotation only
    std::vector<ProgramPhoto> photos;
};

struct Message {
    long   id = 0;
    String text;
    String scheduledAt;            // "YYYY-MM-DDTHH:MM:SS" local
};

struct SyncData {
    DisplaySettings settings;
    std::vector<Alarm>   alarms;
    Program              program;
    std::vector<Message> messages;
};
