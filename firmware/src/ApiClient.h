#pragma once
#include <Arduino.h>
#include "types.h"

// All HTTP talk to the Laravel API. Holds the bearer token and a reusable TLS
// client. Methods return success/failure; richer state goes through out-params.

enum class SyncResult { Updated, NotModified, Unauthorized, Error };

struct PairInfo {
    bool   ok = false;
    String pairingCode;
    String name;
    long   expiresIn = 0;
};

struct PairStatus {
    bool ok = false;          // request itself succeeded
    bool paired = false;      // a human has claimed at least one role
    bool unauthorized = false;// token rejected (window expired / pruned)
    String name;
    String pairingCode;
    long   expiresIn = 0;
};

class ApiClient {
public:
    void begin();                       // configure the TLS client once
    void setToken(const String& t)      { _token = t; }

    // --- pairing ---
    PairInfo   pairInit(const String& mac);
    PairStatus pairStatus();
    PairInfo   forget();                // device-initiated factory reset

    // --- data ---
    SyncResult sync(SyncData& out);     // honours stored ETag → 304
    bool       fetchTime(String& serverTimeOut, String& tzOut);

    // --- photo: downloads JPEG bytes (token-gated render_url) into a heap buf.
    //     Caller owns *buf and must free() it. Returns false on any failure.
    bool       fetchPhoto(const String& renderUrl, uint8_t** buf, size_t* len);

    // --- telemetry & interaction callbacks ---
    bool postStatus();
    bool alarmDismissed(long id);
    bool messageRead(long id);
    bool photoShown(long id);

private:
    String _token;
    bool   beginRequest(class HTTPClient& http, const String& url);
};
