#include "Storage.h"
#include <Preferences.h>

static Preferences prefs;
static const char* NS = "smartalarm";

namespace Storage {

void begin() { prefs.begin(NS, false); }

String token()            { return prefs.getString("token", ""); }
void   setToken(const String& t) { prefs.putString("token", t); }
bool   hasToken()         { return token().length() > 0; }
void   clearToken()       { prefs.remove("token"); prefs.remove("etag"); }

String etag()             { return prefs.getString("etag", ""); }
void   setEtag(const String& e) { prefs.putString("etag", e); }

} // namespace Storage
