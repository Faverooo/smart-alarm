#pragma once
#include <Arduino.h>
#include "types.h"

// All HTTP talk to the Laravel API. Holds the bearer token and a reusable TLS
// client. Methods return success/failure; richer state goes through out-params.

enum class SyncResult { Updated, NotModified, Unauthorized, Error };

struct PairInfo {
    bool   ok = false;
    bool   alreadyOwned = false;  // server returned 409: device claimed, needs "forget" first
    String pairingCode;
    long   expiresIn = 0;
};

struct PairStatus {
    bool ok = false;          // request itself succeeded
    bool paired = false;      // a human has claimed at least one role
    bool unauthorized = false;// token rejected (window expired / pruned)
    String pairingCode;
    long   expiresIn = 0;
};

class ApiClient {
public:
    void begin();                       // configure the TLS client once
    void setToken(const String& t) {
        if (t != _token) _etag = "";    // new identity → old cached payload is moot
        _token = t;
    }

    // --- pairing ---
    PairInfo   pairInit(const String& mac);
    PairStatus pairStatus();
    PairInfo   forget();                // device-initiated factory reset

    // --- data ---
    // Honours the in-RAM ETag → 304. The ETag is RAM-only on purpose: after a
    // reboot the payload is gone, so the first sync must always be a full 200.
    SyncResult sync(SyncData& out);
    bool       fetchTime(String& serverTimeOut, String& tzOut);

    // --- photo: downloads JPEG bytes (token-gated render_url) into a heap buf.
    //     Caller owns *buf and must free() it. Returns false on any failure.
    //     Handles both Content-Length and chunked responses (proxies re-chunk).
    bool       fetchPhoto(const String& renderUrl, uint8_t** buf, size_t* len);

    // --- OTA ---
    // true + url (and the .bin's MD5, when the server publishes one) when an
    // update is available.
    bool checkFirmware(String& urlOut, String& md5Out);
    // Downloads and flashes the image, verifying its MD5 first when given one
    // (rejects truncated/corrupted/foreign binaries). Reboots on success;
    // returns false on any failure and the running build carries on.
    bool otaUpdate(const String& url, const String& md5);

    // --- telemetry & interaction callbacks ---
    bool postStatus();
    bool alarmDismissed(long id);
    bool messageRead(long id);
    bool photoShown(long id);

private:
    String _token;
    String _etag;                       // last /sync ETag we hold a payload for
    bool   beginRequest(class HTTPClient& http, const String& url);
    bool   simplePost(const String& url);
};
