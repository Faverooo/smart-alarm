# Come funziona il firmware (ESP32)

Guida didattica al firmware in `firmware/`, gemella di `docs/COME_FUNZIONA.md`
(che spiega il backend). Presuppone che tu sappia programmare ma non che conosca
l'embedded: i vincoli di un microcontrollore (poca RAM, niente OS, un solo loop)
sono spiegati man mano, perché sono LORO a giustificare quasi tutte le scelte.

---

## 1. Il contesto: cosa significa "girare su un ESP32"

Numeri che cambiano il modo di programmare rispetto a un server:

| Risorsa | ESP32 (questo progetto) | Conseguenza |
|---|---|---|
| RAM | ~320 KB totali, ~250 KB usabili | niente framebuffer dello schermo (150 KB!), una sola foto in memoria |
| Flash | 4 MB, di cui ~1.9 MB per slot app | il binario deve starci DUE volte (OTA, §8) |
| CPU | 240 MHz dual core | TLS e JPEG decode si fanno, ma costano |
| OS | nessuno (FreeRTOS sotto Arduino, ma per noi: un `loop()`) | niente thread "comodi": tutto è cooperativo |
| Storage | NVS (key-value su flash) + LittleFS | scritture flash limitate → si persiste il minimo |

E il modello di esecuzione Arduino è due funzioni:
```cpp
void setup();   // una volta, all'accensione
void loop();    // per sempre, più volte al secondo
```
Tutto ciò che "succede nel tempo" (sveglie, sync, rotazione foto) è implementato
controllando timestamp dentro `loop()` — non esistono cron o timer "di sistema"
comodi come su un server.

---

## 2. Mappa dei file

```
firmware/
├── platformio.ini      # board, librerie, PIN del TFT (build flags), partizioni
├── data/clock.vlw      # font anti-aliased per l'ora (opzionale, va su LittleFS)
└── src/
    ├── config.h        # TUTTE le costanti: URL server, TZ, pin, intervalli, cap
    ├── types.h         # struct che rispecchiano il payload di /sync
    ├── Storage.{h,cpp} # persistenza NVS: SOLO il token (§5.3 spiega perché)
    ├── ApiClient.{h,cpp} # tutto l'HTTP verso Laravel
    ├── DisplayUI.{h,cpp} # disegno su TFT, decode JPEG, buzzer, retroilluminazione
    └── main.cpp        # la macchina a stati + il loop
```

Filosofia: `main.cpp` decide COSA fare e QUANDO; `ApiClient` sa COME parlare col
server; `DisplayUI` sa COME disegnare. Nessuno dei tre conosce gli interni degli
altri due.

**PlatformIO** (al posto dell'IDE Arduino): le librerie sono dichiarate in
`platformio.ini` con versione bloccata, e i pin del TFT sono **build flags**
(`-DTFT_MOSI=23` ecc.) perché la libreria TFT_eSPI si configura a compile time.
Cambi i pin lì, non nel codice.

---

## 3. La macchina a stati (`main.cpp`)

```
        ┌─────────┐  WiFi ok   ┌──────────┐  umano fa il claim  ┌─────────┐
BOOT ──►│  WiFi   ├───────────►│ PAIRING  ├────────────────────►│ RUNNING │
        │ portal  │            │ (codice  │   dal pannello      │ (clock) │
        └─────────┘            │ a video) │                     └────┬────┘
                               └──────────┘                          │ 401 su /sync
                                     ▲                               │ (token morto)
                                     └───────── reboot ◄─────────────┘
```

- **BOOT**: WiFiManager apre un captive portal (`SmartAlarm-Setup`) se non ha
  credenziali salvate; poi NTP per l'orologio (con fallback dal server, §6.1).
- **PAIRING**: il device si è registrato (`POST /pair/init`), ha un token e un
  codice; mostra il codice e fa polling su `/pair/status` ogni 5 s finché un umano
  non lo reclama dal pannello. Se all'avvio il token c'è già ed è valido, questo
  stato viene **saltato** (niente flash della schermata di pairing).
- **RUNNING**: il regime. Il loop fa girare i timer (§4), disegna orologio+foto,
  controlla le sveglie ogni secondo.
- **Uscita d'emergenza**: se `/sync` risponde 401 il token non vale più (device
  cancellato/resettato lato server) → si butta il token e si riavvia: si ricomincia
  dal pairing. Semplice e impossibile da sbagliare.
- **Reset di fabbrica**: tieni premuto DISMISS all'accensione → `POST /device/forget`
  (autenticato dal token stesso: solo chi ha il device in mano può farlo).

---

## 4. Il loop: timer cooperativi e l'idioma `millis()`

Non ci sono thread: ogni attività periodica è un confronto di timestamp.

```cpp
if (ms - lastSync >= SYNC_INTERVAL_MS) { lastSync = ms; doSync(); }
```

**Perché `ms - lastSync >= X` e non `ms >= lastSync + X`?** `millis()` è un
`unsigned long` a 32 bit: **trabocca dopo ~49,7 giorni** e riparte da 0. Una
sveglia da comodino accesa per mesi CI ARRIVA al rollover. La sottrazione tra
unsigned però funziona lo stesso (aritmetica modulare): `ms - lastSync` dà la
distanza giusta anche a cavallo del wrap. Tutti i timer del progetto usano questo
idioma; lo snooze, che è un confronto "siamo DOPO l'istante X?", usa la variante
con cast a signed: `(long)(ms - snoozeAt) >= 0`.

I ritmi (tutti in `config.h`):

| Timer | Ogni | Cosa fa |
|---|---|---|
| `lastSync` | 60 s | `GET /sync` (quasi sempre 304, §5) |
| `lastStatus` | 5 min | `POST /device/status` (heartbeat + telemetria) |
| `lastTime` | 5 min | se NTP non ha mai funzionato, ora dal server |
| `lastFwCheck` | 6 h (e 1 min dopo il boot) | check OTA (§8) |
| `lastSecond` | 1 s | luminosità, **match sveglie**, render foto, overlay orologio |
| `lastPairPoll` | 5 s | solo in PAIRING: `/pair/status` |

---

## 5. `ApiClient`: parlare con Laravel da 250 KB di RAM

### 5.1 Un solo client TLS, riusato

```cpp
static WiFiClientSecure tls;   // l'handshake TLS è la cosa più costosa: il client si riusa
```
Di default `USE_INSECURE_TLS=1`: il certificato del server **non viene validato**
(Cloudflare ruota i certificati edge e fissarne uno è fragile). Il traffico è
comunque cifrato, ma un attaccante in rete potrebbe impersonare il server. Per la
versione paranoica: `USE_INSECURE_TLS=0` + root CA incollata in `ApiClient.cpp`.

Ogni richiesta passa da `beginRequest()`: URL, timeout 8 s, header `Accept` e —
se presente — `Authorization: Bearer <token>`. Il token è il *personal access
token* Sanctum emesso da `pair/init` (vedi la guida backend, §9.1): il firmware
lo riceve UNA volta, lo salva in NVS e lo allega per sempre.

### 5.2 JSON in streaming

`deserializeJson(doc, http.getStream())` parsa **direttamente dallo stream TCP**,
senza mai materializzare la stringa JSON completa in RAM. I cap di `config.h`
(`MAX_ALARMS 16`, `MAX_PHOTOS 10`, `MAX_MESSAGES 8`) limitano quanto entra nelle
`std::vector` di `types.h` — e il server, dall'altra parte, non manda mai più di
così (i due limiti sono specchiati apposta).

### 5.3 L'ETag: perché vive SOLO in RAM (bug story importante)

`/sync` usa il caching HTTP: il device manda `If-None-Match: "<revision>"` e il
server risponde `304` vuoto se non è cambiato nulla. La revision è salvata in
`ApiClient::_etag` — **un membro in RAM, deliberatamente non persistito**.

La prima versione lo salvava in NVS, ed era un bug grave: dopo un riavvio i DATI
(sveglie, foto) sono persi (stavano in RAM), ma l'ETag sopravviveva → il primo
`/sync` diceva "ho già la revision X", il server rispondeva "ok, niente da
mandare" → il device restava **senza sveglie per sempre**, in silenzio. Regola
generale che ne esce: *un validatore di cache deve vivere ESATTAMENTE quanto la
cache che valida*. Riavvio → ETag vuoto → primo sync sempre completo.
(`Storage::begin()` ripulisce anche la vecchia chiave NVS di chi aveva il firmware
precedente; `setToken()` azzera l'ETag se il token cambia: identità nuova, cache vecchia non valida.)

### 5.4 Scaricare una foto senza sapere quanto è grande

`fetchPhoto()` deve gestire un caso subdolo: dietro Cloudflare la risposta può
arrivare **chunked** (senza `Content-Length`). Leggere lo stream "grezzo" non va
bene (conterrebbe i delimitatori dei chunk); la decodifica corretta la fa
`HTTPClient::writeToStream()`. Quindi il firmware definisce un mini-`Stream`
che è solo un buffer che cresce:

```cpp
class JpegBuffer : public Stream {
    size_t write(const uint8_t* data, size_t n) override {
        // raddoppia il buffer con realloc, con tetto a 200 KB (sanity cap)
    }
    // read()/available() → vuoti: è write-only
};
// ...
JpegBuffer body;
http.writeToStream(&body);   // gestisce LUI Content-Length E chunked
```
Pattern da ricordare: quando una libreria Arduino "scrive su uno Stream", puoi
dargli QUALSIASI cosa implementi `write()` — un file, un buffer, una seriale.

I fallimenti hanno un **backoff**: se il download fallisce, si riprova dopo 10 s
(`PHOTO_RETRY_MS`), non a ogni giro del loop da 1 s — altrimenti un blackout di
rete diventerebbe un martellamento di handshake TLS.

### 5.5 Cosa persiste davvero (`Storage`)

**Solo il token**, in NVS (libreria `Preferences`, namespace `smartalarm`). Tutto
il resto (payload di sync, ETag, stato sveglie) è ricostruibile dal server al
primo sync, quindi tenerlo su flash sarebbe solo usura e bug in più. Le
credenziali WiFi le salva WiFiManager per conto suo.

---

## 6. Il tempo: wall-clock locale, due sorgenti

### 6.1 Da dove arriva l'ora

1. **NTP** con `configTzTime(TZ_INFO, ...)`: il TZ è una stringa **POSIX**
   (`"CET-1CEST,M3.5.0,M10.5.0/3"` = Europe/Rome con regole DST europee) così
   `localtime_r()` restituisce direttamente l'ora legale giusta, senza che il
   codice sappia nulla di fusi orari.
2. **Fallback dal server** (`GET /time`): se NTP non risponde (firewall, DNS…),
   si prende la stringa wall-clock del server e si setta l'orologio di sistema
   con `settimeofday()`. Il server è autorevole per definizione: è LUI che ha
   deciso gli orari delle sveglie.

`getLocalTm()` rifiuta l'ora finché non è plausibile (`time() < 1700000000` = "il
clock non è ancora stato impostato"): meglio NESSUNA sveglia che sveglie
all'epoca-zero del 1970.

### 6.2 Il match delle sveglie (ogni secondo)

Tutto a stringhe e wall-clock, coerente col backend:

```cpp
bool alarmMatches(const Alarm& a, const struct tm& now) {
    // 1. l'orario coincide? ("07:30" == HH:MM di adesso)
    // 2. se ha scheduled_date → è oggi?
    // 3. altrimenti → il giorno della settimana è nella lista?
}
```

**Il de-dupe** è la parte delicata: il match resta vero per tutti i 60 secondi del
minuto, ma la sveglia deve partire UNA volta. Lo stato è:

```cpp
static String firedMinute;  // "2026-06-10T07:30" → a che minuto si riferisce la lista
static String firedIds;     // ",12,15," → id già suonati in QUEL minuto
```

Perché una lista e non "l'ultimo id suonato"? Per il caso limite di **due sveglie
allo stesso orario**: la prima suona, l'utente la spegne, la seconda deve suonare
*comunque* — e spegnendo la seconda, la prima non deve ripartire. Una sola
variabile "ultima sveglia" non basta a ricordare entrambe.

**Snooze**: spegne il buzzer e arma `snoozeAt = millis() + 9 min`. Allo scadere,
la sveglia viene ri-cercata **nei dati correnti** (`data.alarms`) prima di
risuonare: se nel frattempo è stata disattivata/cancellata dal pannello, lo snooze
muore in silenzio (i dati del sync sono la verità, non lo stato salvato al
momento dello snooze).

**Dismiss** di una sveglia una-tantum → `POST /alarms/{id}/dismissed` → il server
la disattiva → al sync successivo sparisce anche localmente.

---

## 7. `DisplayUI`: disegnare 320×240 senza framebuffer

### 7.1 Il problema RAM e la soluzione "a blocchi"

Un framebuffer 320×240 a 16 bit = **150 KB**: più di metà della RAM usabile. Non
esiste. Quindi:

- **TJpg_Decoder** decodifica il JPEG **a blocchi 16×16**, e ogni blocco viene
  spedito subito al pannello via SPI (callback `tftBlit`). In RAM vive solo il
  JPEG compresso (~20–30 KB) + un blocco alla volta.
- Il JPEG della foto corrente resta **residente in heap** (`photoJpeg`): serve per
  ridisegnare lo sfondo dietro l'orologio senza riscaricarlo.

### 7.2 L'overlay dell'orologio: ridisegnare solo una striscia

Ogni minuto cambia solo la parte bassa dello schermo (data+ora) ed eventualmente
la striscia alta (messaggio). Ridisegnare tutta la foto sarebbe lento e
"lampeggerebbe". Trucco: il **viewport** di TFT_eSPI.

```cpp
tft.setViewport(0, sy, larghezza, altezza);  // clip: si disegna solo qui dentro
TJpgDec.drawJpg(0, 0, jpeg, len);            // ri-decodifica la foto, ma i pixel
tft.resetViewport();                         // fuori dal viewport vengono scartati
```
Si paga la CPU della decodifica ma si tocca solo la striscia → niente flicker.

Il testo sopra la foto usa un **alone (halo)**: la stringa viene disegnata 8 volte
in nero attorno alla posizione vera e una volta in bianco sopra — leggibile su
QUALSIASI foto senza riquadri. L'ora grande usa il font `.vlw` da LittleFS se
presente (anti-aliased, stile lock-screen), altrimenti il 7-segmenti integrato:
il firmware funziona anche senza l'asset.

### 7.3 Buzzer e retroilluminazione

Entrambi in PWM (LEDC): la retroilluminazione per la luminosità giorno/notte
(i valori arrivano dal pannello via `/sync` → `applyBrightness()` ogni secondo),
il buzzer come tono ~2,7 kHz il cui duty "approssima" il volume.

---

## 8. OTA: come si aggiorna da solo

La flash è partizionata (`min_spiffs.csv`) con **due slot applicazione** da
~1.9 MB: si esegue da uno, si scrive l'aggiornamento nell'altro, e il bootloader
scambia gli slot al riavvio. Se la scrittura fallisce a metà → si riavvia nello
slot vecchio: l'OTA non può "brickare" il device.

Flusso (`checkFirmwareUpdate()` in `main.cpp`, ogni 6 h + 1 min dopo il boot):

```
GET /firmware/latest?current=0.1.0
  → { update_available: true, url: "https://…/smart-alarm-0.1.1.bin" }
  → httpUpdate.update(tls, url)     // scarica nello slot inattivo, verifica, REBOOT
```

Dettagli che contano:
- Mai durante una sveglia che suona (guardia `!ringing`).
- Il confronto versioni lo fa il SERVER (`version_compare`): il device esegue e basta.
- Se dimentichi di alzare `FIRMWARE_VERSION` in `config.h` prima di pubblicare il
  `.bin`, il device si aggiorna, riferisce la versione vecchia, e si ri-aggiorna
  in loop ogni 6 ore. La versione nel binario E quella in `.env` sul server devono
  avanzare insieme.
- Il binario attuale occupa ~59% dello slot: c'è margine, ma tienilo d'occhio
  (`pio run` stampa la percentuale).

---

## 9. I bottoni (e il debounce)

Tre bottoni, attivi-bassi con pull-up interno (un capo al GPIO, l'altro a GND):

| Bottone | A riposo | Mentre suona |
|---|---|---|
| DISMISS (32) | — (al boot: factory reset) | spegne la sveglia |
| SNOOZE (33) | — | +9 minuti |
| NEXT (27) | marca letto il messaggio A SCHERMO; foto successiva (rotation) | — |

Il **debounce** è il minimo indispensabile: lettura LOW → aspetta 25 ms → rileggi
(i contatti meccanici "rimbalzano" per qualche ms). Poi un `while (pressed(...))`
aspetta il rilascio, così una pressione lunga non si autorepete.

Dettaglio di correttezza su NEXT: il messaggio mostrato è *l'ultimo* tra quelli
scaduti (`dueMessageIndex()`), e il bottone marca letto **quello stesso indice**.
Display e acknowledge devono condividere la stessa funzione di scelta — la prima
versione usava due criteri diversi e marcava letto un messaggio mai visto.

---

## 10. Vincoli e idiomi da portarsi a casa

Riassunto dei pattern embedded visti, riutilizzabili ovunque:

1. **Timer cooperativi** con `ms - last >= intervallo` (wrap-safe) — mai `delay()`
   lunghi nel loop, o i bottoni smettono di rispondere.
2. **Il validatore di cache vive quanto la cache** (ETag in RAM, §5.3).
3. **Backoff sui retry** che stanno dentro loop veloci (§5.4).
4. **Streaming, mai bufferizzare l'intero payload** (JSON e JPEG a blocchi).
5. **Lo stato remoto è la verità**: dopo ogni decisione differita (snooze), si
   ri-verifica contro i dati correnti del sync, non contro una copia salvata.
6. **Persistere il minimo**: tutto ciò che è ricostruibile dal server resta in RAM.
7. **Fallire verso uno stato sicuro**: 401 → re-pair; OTA fallito → slot vecchio;
   NTP morto → ora dal server; nessun dato → meglio non suonare che suonare a caso.

---

## 11. Lavorarci: build, flash, debug

```bash
cd firmware
pio run                  # compila (stampa uso RAM/flash)
pio run -t upload        # flash via USB
pio run -t uploadfs      # carica data/ (il font) su LittleFS
pio device monitor       # seriale a 115200 baud
```

Per il debug: `Serial.printf(...)` + monitor seriale è il tuo `dd()`. Il livello
di log del core (`-DCORE_DEBUG_LEVEL` in `platformio.ini`) è a 0 per la release;
alzalo a 3 in sviluppo per vedere cosa fanno WiFi/TLS/HTTP sotto al cofano
(occhio: le stringhe di log occupano flash).

Primo test consigliato con l'hardware vero, in quest'ordine: schermo (boot
screen) → WiFi portal → pairing code a video → claim dal pannello → foto →
sveglia (suona? si spegne? snooze?) → messaggio → OTA. È lo stesso ordine della
macchina a stati: ogni passo verde sblocca il successivo.

---

## 12. Percorso di lettura consigliato

1. `config.h` → tutte le costanti: leggendole, conosci già metà del design.
2. `types.h` → il payload di `/sync` visto dal C++ (confrontalo col JSON del backend).
3. `main.cpp` da `setup()` in giù → la macchina a stati e il loop (con §3–§4 a fianco).
4. `ApiClient.cpp`: prima `sync()` (ETag), poi `fetchPhoto()` (JpegBuffer), poi
   `pairInit()`/`forget()` (il giro del token).
5. `DisplayUI.cpp`: `tftBlit` + `drawClockOverlay` (viewport e halo).
6. Rileggi il §9.3 della guida backend: vedere ENTRAMBI i lati dell'ETag è il
   momento in cui il design "fa click".
