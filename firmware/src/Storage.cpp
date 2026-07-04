#include "Storage.h"
#include <Preferences.h>

static Preferences prefs;
static const char* NS = "smartalarm";

// NVS caps a single string entry at ~4000 bytes; the alarm cache is ~2KB worst
// case (16 alarms + settings) but guard anyway so a put can never fail loudly.
static const size_t SYNC_CACHE_MAX = 3900;

namespace Storage {

void begin() {
    prefs.begin(NS, false);
    prefs.remove("etag");   // scrub the key older firmware persisted
}

String token()            { return prefs.getString("token", ""); }
void   setToken(const String& t) { prefs.putString("token", t); }
bool   hasToken()         { return token().length() > 0; }

void clearToken() {
    prefs.remove("token");
    // A cleared token means re-pair (new identity / factory reset): the cached
    // alarms belong to the old pairing and must not ring for the next owner.
    clearSyncCache();
}

String syncCache() { return prefs.getString("synccache", ""); }

void setSyncCache(const String& json) {
    if (json.length() > SYNC_CACHE_MAX) return;          // never exceed an NVS entry
    if (json == prefs.getString("synccache", "")) return; // spare the flash
    prefs.putString("synccache", json);
}

void clearSyncCache() { prefs.remove("synccache"); }

} // namespace Storage
