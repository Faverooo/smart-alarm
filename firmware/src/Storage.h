#pragma once
#include <Arduino.h>

// Thin wrapper over NVS (Preferences) for the few values that must survive
// reboots: the device token and the last ETag we hold a /sync payload for.
namespace Storage {
    void   begin();

    String token();
    void   setToken(const String& t);
    bool   hasToken();
    void   clearToken();

    String etag();
    void   setEtag(const String& e);
}
