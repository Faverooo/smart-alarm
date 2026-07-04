#pragma once
#include <Arduino.h>

// Thin wrapper over NVS (Preferences) for what must survive reboots: the
// device token, plus a compact alarms+settings snapshot so the device still
// rings on schedule after a reboot even if the server is unreachable. The
// sync ETag deliberately lives in RAM only (ApiClient): a 304 is only useful
// while the matching payload is still in memory — persisting it across a
// reboot would leave the device data-less.
namespace Storage {
    void   begin();

    String token();
    void   setToken(const String& t);
    bool   hasToken();
    void   clearToken();               // also drops the sync cache (new identity)

    // Offline fallback: compact JSON of the last-synced alarms + settings
    // (photos/messages are NOT cached — they need the network anyway).
    String syncCache();
    void   setSyncCache(const String& json);
    void   clearSyncCache();
}
