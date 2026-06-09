#pragma once
#include <Arduino.h>

// Thin wrapper over NVS (Preferences) for the one value that must survive
// reboots: the device token. The sync ETag deliberately lives in RAM only
// (ApiClient): a 304 is only useful while the matching payload is still in
// memory — persisting it across a reboot would leave the device data-less.
namespace Storage {
    void   begin();

    String token();
    void   setToken(const String& t);
    bool   hasToken();
    void   clearToken();
}
