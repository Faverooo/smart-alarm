# Come funziona il firmware (ESP32)

Guida didattica al firmware in `firmware/`, gemella di `docs/COME_FUNZIONA.md`
(che spiega il backend). Presuppone che tu sappia programmare ma non che conosca
l'embedded: i vincoli di un microcontrollore (poca RAM, niente OS, un solo loop)
sono spiegati man mano, perché sono LORO a giustificare quasi tutte le scelte.

> Vuoi solo la mappa pratica (build, flash, pin, comandi)? Salta al **§11**.
> Vuoi capire l'orologio e il font? **§7**. Le API che il device chiama? **§5**.

---

## 1. Il contesto: cosa significa "girare su un ESP32"

Numeri che cambiano il modo di programmare rispetto a un server:

| Risorsa | ESP32 (questo progetto) | Conseguenza |
|---|---|---|
| RAM | ~320 KB totali, ~250 KB usabili | niente framebuffer dello schermo (150 KB!), una sola foto in memoria |
| Flash | 4 MB, ~1.9 MB per slot app | il binario deve starci DUE volte (OTA, §8) |
| CPU | 240 MHz dual core | TLS e JPEG decode si fanno, ma costano |
| OS | nessuno (FreeRTOS sotto Arduino, ma per noi: un `loop()`) | niente thread "comodi": tutto è cooperativo |
| Storage | NVS (key-value su flash) + LittleFS | scritture flash limitate → si persiste il minimo |
| PSRAM | **assente** (board `esp32dev` liscia) | niente buffer grandi extra: lo dimostra la sprite dell'orologio (§7.2) |

E il modello di esecuzione Arduino è due funzioni:
```cpp
void setup();   // una volta, all'accensione
void loop();    // per sempre, più volte al secondo
```
Tutto ciò che "succede nel tempo" (sveglie, sync, rotazione foto) è implementato
controllando timestamp dentro `loop()` — non esistono cron o timer "di sistema"
comodi come su un server.

---

## 2. Mappa dei file e dell'hardware

```
firmware/
├── platformio.ini      # board, librerie, PIN del TFT (build flags), partizioni
├── data/               # contenuto della partizione LittleFS
│   ├── README.md       # ← qui va clock.vlw (vedi §7.4); di default NON c'è ancora
│   └── clock.vlw       # font anti-aliased per l'ora (opzionale)
└── src/
    ├── config.h        # TUTTE le costanti: URL server, TZ, pin, intervalli, cap
    ├── types.h         # struct che rispecchiano il payload di /sync
    ├── Storage.{h,cpp} # persistenza NVS: SOLO il token (§5.5 spiega perché)
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

### 2.1 I pin (chi li guida)

Due gruppi distinti:

| Cosa | Pin | Dove si configura | Note |
|---|---|---|---|
| TFT MISO/MOSI/SCLK/CS/DC/RST | 19/23/18/15/2/4 | `platformio.ini` (build flags) | li legge TFT_eSPI a compile time |
| Retroilluminazione (BL) | 21 | `config.h` `BL_PIN` | PWM via LEDC (luminosità) |
| Buzzer | 25 | `config.h` `BUZZER_PIN` | modulo attivo di default (§7.3) |
| Pulsante (unico) | 32 | `config.h` `BTN_PIN` | `INPUT_PULLUP`, attivo-basso |
| LED nel pulsante | 26 | `config.h` `BTN_LED_PIN` | via transistor NPN BC547 |

Il pannello è un **ILI9341 320×240 SPI**, dichiarato **IPS** (`-DTFT_INVERSION_ON`:
senza, sull'IPS i neri diventano bianchi), `setRotation(1)` = landscape, SPI a
40 MHz.

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
- **Reset di fabbrica**: tieni premuto il pulsante all'accensione → `POST /device/forget`
  (autenticato dal token stesso: solo chi ha il device in mano può farlo).

### 3.1 Come si collega al WiFi (provisioning + reconnect)

Niente SSID/password hard-coded. Si usa **WiFiManager**:

```cpp
WiFiManager wm;
wm.setConfigPortalTimeout(300);            // 5 min poi reboot
if (!wm.autoConnect(WIFI_PORTAL_NAME)) {   // "SmartAlarm-Setup"
    DisplayUI::showBooting("WiFi failed, rebooting");
    delay(3000); ESP.restart();
}
```

1. **Primo avvio / credenziali perse**: `autoConnect` non trova una rete nota →
   l'ESP32 diventa un **access point** chiamato `SmartAlarm-Setup`. Sullo schermo
   compare la schermata "WiFi setup". Con il telefono ti connetti a quell'AP, si
   apre da solo il **captive portal**, scegli la tua rete di casa e digiti la
   password. WiFiManager **salva le credenziali** nella propria NVS.
2. **Avvii successivi**: trova la rete salvata e si connette in pochi secondi,
   senza portale.
3. **Caduta di rete a regime**: nel `loop()` c'è la guardia
   ```cpp
   if (WiFi.status() != WL_CONNECTED) { WiFi.reconnect(); delay(1000); return; }
   ```
   il device riprova all'infinito senza bloccare nulla (e senza suonare sveglie
   "a caso": senza dati aggiornati, vedi §6.1, meglio star fermi).

Le credenziali le cambi rifacendo il portale (reset di fabbrica, §9), non
ricompilando.

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
| `lastSync` | 60 s | `GET /sync` (quasi sempre 304, §5.3) |
| `lastStatus` | 5 min | `POST /device/status` (heartbeat + telemetria) |
| `lastTime` | 5 min | se NTP non ha mai funzionato, ora dal server |
| `lastFwCheck` | 6 h (e 1 min dopo il boot) | check OTA (§8) |
| `lastSecond` | 1 s | luminosità, **match sveglie**, render foto, overlay orologio |
| `lastPairPoll` | 5 s | solo in PAIRING: `/pair/status` |

A fine giro un `delay(20)` modesto tiene il loop reattivo (il pulsante viene letto
ad ogni giro, ~50 volte al secondo) senza scaldare la CPU inutilmente.

---

## 5. `ApiClient`: parlare con Laravel da 250 KB di RAM

### 5.0 Tutti i comandi che il device esegue verso le API

Tutto sotto `API_BASE = https://<server>/api/v1`. Tranne `pair/init`, ogni
richiesta porta l'header `Authorization: Bearer <token>`.

| Quando | Metodo + endpoint | Scopo |
|---|---|---|
| Pairing, all'inizio | `POST /pair/init` `{mac_address}` | registra il device → riceve `device_token` + `pairing_code` |
| Pairing, in attesa (5 s) | `GET /pair/status` | "qualcuno mi ha reclamato?" + codice/scadenza |
| Reset di fabbrica | `POST /device/forget` | il device si auto-dissocia, riceve nuovo token/codice |
| Regime (60 s) | `GET /sync` (`If-None-Match`) | sveglie + programma/foto + messaggi + settings; 304 se invariato |
| NTP fallito (5 min) | `GET /time` | ora wall-clock dal server (fallback orologio) |
| Quando cambia la foto | `GET <render_url>` | scarica il JPEG 320×240 della foto (token-gated) |
| Heartbeat (5 min) | `POST /device/status` | `firmware_version`, `free_heap`, `rssi`, `ip` → pallino "online" |
| Sveglia una-tantum spenta | `POST /alarms/{id}/dismissed` | il server la disattiva |
| Messaggio letto (pulsante) | `POST /messages/{id}/read` | marca letto |
| Foto mostrata | `POST /programs/photos/{id}/shown` | bookkeeping best-effort |
| OTA (6 h) | `GET /firmware/latest?current=<ver>` | c'è un aggiornamento? (§8) |

### 5.1 Un solo client TLS, riusato

```cpp
static WiFiClientSecure tls;   // l'handshake TLS è la cosa più costosa: il client si riusa
```
Di default `USE_INSECURE_TLS=1`: il certificato del server **non viene validato**
(Cloudflare ruota i certificati edge e fissarne uno è fragile). Il traffico è
comunque cifrato, ma un attaccante in rete potrebbe impersonare il server. Per la
versione paranoica: `USE_INSECURE_TLS=0` + root CA incollata in `ApiClient.cpp`.

Ogni richiesta passa da `beginRequest()`: controlla il WiFi, apre l'URL sul client
TLS, **timeout 15 s**, header `Accept: application/json` e — se presente —
`Authorization: Bearer <token>`. Il token è il *personal access token* Sanctum
emesso da `pair/init`: il firmware lo riceve UNA volta, lo salva in NVS e lo
allega per sempre.

### 5.2 JSON: si bufferizza il corpo, poi si parsa

```cpp
String body = http.getString();          // legge tutto il corpo (gestisce anche il chunked)
JsonDocument doc;
deserializeJson(doc, body);
```

Si legge l'intero corpo in una `String` e poi lo si parsa. Perché non parsare
direttamente dallo stream TCP? Perché dietro Cloudflare la risposta può arrivare
**chunked** (a pezzi, senza `Content-Length`): `getString()` ricompone i chunk in
modo affidabile, mentre il parsing dallo stream grezzo è più fragile. I payload di
`/sync` sono piccoli (poche KB) grazie ai **cap** lato server, quindi tenerli un
istante in RAM non è un problema.

I cap di `config.h` (`MAX_ALARMS 16`, `MAX_PHOTOS 10`, `MAX_MESSAGES 8`) limitano
quanti elementi finiscono nelle `std::vector` di `types.h` durante il parse — e il
server, dall'altra parte, non manda mai più di così (i due limiti sono specchiati
apposta, così il device non può mai sforare la heap).

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

Un framebuffer 320×240 a 16 bit = **150 KB**: più di metà della RAM usabile, e
senza PSRAM non c'è. Quindi:

- **TJpg_Decoder** decodifica il JPEG **a blocchi 16×16**, e ogni blocco viene
  spedito subito al pannello via SPI (callback `tftBlit`). In RAM vive solo il
  JPEG compresso (~20–30 KB) + un blocco alla volta.
- Il JPEG della foto corrente resta **residente in heap** (`photoJpeg`): serve per
  ridisegnare lo sfondo dietro l'orologio senza riscaricarlo.

Quale foto mostrare la decide `pickPhotoIndex()` in base al tipo di programma:
`fixed` → sempre la prima; `rotation` → avanza ogni `interval_minutes`; `daily` →
quella con `scheduled_for == oggi`. Una nuova foto si scarica solo se il suo
`hash` è cambiato (`renderPhoto`), così un sync che cambia solo un messaggio non
riscarica l'immagine.

### 7.2 L'orologio: aggiornare SOLO le cifre, in una sprite off-screen

Sul display galleggiano, sopra la foto: in alto un eventuale **messaggio**, in
basso **data**, **ora grande** (hero clock), **AM/PM** (solo formato 12h),
**caption** della foto e la **prossima sveglia**. Ogni minuto cambia, di norma,
**solo l'ora**.

La versione vecchia ridisegnava ogni minuto tutta la fascia bassa (≈57% dello
schermo) ri-decodificando il JPEG: si vedeva "lampeggiare" mezza pagina. Ora
`drawClockOverlay` lavora a due velocità:

- **Tick di minuto normale → si ridisegna solo l'ora**, composta **off-screen in
  una piccola `TFT_eSprite`**: si decodifica nella sprite il pezzo di foto sotto le
  cifre, ci si scrive sopra l'ora, e si fa **un solo `pushSprite`** sul pannello.
  Niente lampeggio, il resto della foto non viene toccato.
- **Repaint completo** solo quando cambia qualcosa di diverso da `HH:MM`: nuova
  foto, mezzanotte (cambia la data), flip AM/PM, o cambio caption / prossima
  sveglia / messaggio. `main.cpp` forza il full passando `full=true` quando lo
  sfondo è stato riscritto (`lastClockMinute == -1`); per il resto è la funzione
  stessa a capire cosa è cambiato confrontando con i valori dell'ultima volta.

Perché la sprite è "piccola" e non a schermo intero? Perché **non c'è PSRAM**: una
sprite 320×240 sarebbe 150 KB. Quella dell'ora è dimensionata sulle sole cifre
(la stringa più larga è `"00:00"`) per il font attivo → **~13 KB** col font
interno, **~40 KB** con un VLW da 72px. La sprite si crea e si distrugge ad ogni
aggiornamento (transitoria), così non resta occupata mentre girano TLS/JPEG.

Come fa il pezzo di foto a finire dentro la sprite? Un puntatore globale
(`g_capture`) dice al callback del decoder JPEG di **redirigere i blocchi nella
sprite** invece che sul pannello (con un offset, e i blocchi fuori dalla banda
vengono scartati dal clipping). Bonus: scrivere il testo *nella sprite* fa sì che
l'anti-aliasing del font si fonda contro i **pixel reali della foto** lì presenti
— niente più bordi "sporchi" dovuti alle letture inaffidabili del pannello ILI9341.

Il testo usa un **alone (halo)**: la stringa viene disegnata più volte in nero
attorno alla posizione vera (raggio 1–2 px) e una volta nel colore pieno sopra —
leggibile su QUALSIASI foto senza barre piene di sfondo.

### 7.3 Font dell'orologio (perché "scalettato" o "liscio")

- L'**ora grande** usa il font smooth `/clock.vlw` da LittleFS **se presente**
  (anti-aliased, stile lock-screen). `DisplayUI::begin()` controlla al boot se il
  file c'è e imposta `clockFontAvailable`.
- Se **non c'è** (è il caso di default: in `data/` c'è solo il README), si
  ripiega sul **font 6 integrato**: pulito ma bitmap, quindi a bordi netti
  ("scalettato"). Il firmware funziona comunque, è solo meno bello.
- Data, AM/PM, caption, messaggio usano i font bitmap integrati (2 e 4): non
  hanno bisogno del VLW.

Per avere i numeri lisci devi **generare `clock.vlw`** → §7.4.

### 7.4 Generare e installare il font `clock.vlw`

Il `.vlw` è un formato di font di **Processing**; si crea una volta sola con il
tool che arriva dentro TFT_eSPI. Si fa su Windows/Mac (serve l'interfaccia
grafica), non dentro WSL.

1. **Installa Processing** da <https://processing.org/download> (è uno zip da
   estrarre, niente installazione).
2. **Procurati un .ttf** rotondo (Inter, SF Pro, Helvetica, Roboto…). Puoi usare
   un font di sistema per nome, oppure copiare il `.ttf` nella cartella `data/`
   dello sketch.
3. **Apri lo sketch** `Create_font.pde`:
   `firmware/.pio/libdeps/esp32dev/TFT_eSPI/Tools/Create_Smooth_Font/Create_font/Create_font.pde`
4. In cima al file modifica i parametri:
   - `fontName = "Inter";`  (il nome del tuo font/ttf)
   - `fontSize = 72;`       (la dimensione "hero"; 64–80 va bene su 320×240)
   - *(opzionale, per un file piccolo)* attiva il set "characters for showing
     time": cambia il `/*` in `//*` sopra la riga con `0x0030…0x003A` (cifre e `:`).
     Se non lo tocchi, genera tutto l'alfabeto Latin (~40 KB): funziona uguale.
5. Premi **▶ Run**. A fine esecuzione si apre la cartella `FontFiles/` con dentro
   `Inter72.vlw`.
6. **Rinominalo** in **`clock.vlw`** e copialo in **`firmware/data/clock.vlw`**
   (da Windows il path WSL è tipo `\\wsl$\...\smart-alarm\firmware\data\`).
7. **Flasha la partizione** dei file (separata dal codice!):
   ```bash
   cd firmware
   ~/.platformio/penv/bin/pio run -t uploadfs
   ```
   Al reboot `DisplayUI::begin()` trova `/clock.vlw` da solo e passa ai numeri
   lisci — nessuna modifica al codice.

> Per l'orologio servono solo cifre e `:`; AM/PM lo disegno col font bitmap
> interno, quindi un font con sole cifre basta. Se installi un VLW molto alto
> (72px) verifica a video che le cifre non tocchino la caption in basso: la banda
> della sprite si auto-dimensiona sull'altezza del font, ma il layout a 240px è
> denso.

### 7.5 Buzzer e retroilluminazione

- **Retroilluminazione**: PWM via LEDC su `BL_PIN`. La luminosità giorno/notte
  arriva dal pannello via `/sync` e viene applicata ogni secondo
  (`applyBrightness()` → finestra notturna `night_start`/`night_end`).
- **Buzzer**: di default `BUZZER_ACTIVE=1` → **modulo attivo a 3 pin** con
  oscillatore proprio: lo si pilota solo HIGH/LOW, niente controllo volume. Se
  invece monti un buzzer **passivo** (`BUZZER_ACTIVE=0`) il firmware genera un tono
  ~2,7 kHz in PWM il cui duty "approssima" il volume. In entrambi i casi si accende
  anche il **LED del pulsante** mentre suona.

---

## 8. OTA: come si aggiorna da solo

La flash è partizionata (`min_spiffs.csv`) con **due slot applicazione** da
~1.9 MB + una piccola partizione LittleFS (~190 KB, dove sta il font): si esegue
da uno slot, si scrive l'aggiornamento nell'altro, e il bootloader scambia gli
slot al riavvio. Se la scrittura fallisce a metà → si riavvia nello slot vecchio:
l'OTA non può "brickare" il device.

Flusso (`checkFirmwareUpdate()` in `main.cpp`, ogni 6 h + 1 min dopo il boot):

```
GET /firmware/latest?current=0.1.0
  → { update_available: true, url: "https://…/smart-alarm-0.1.1.bin" }
  → httpUpdate.update(tls, url)     // scarica nello slot inattivo, verifica, REBOOT
```

Dettagli che contano:
- Mai durante una sveglia che suona (guardia `!ringing`).
- Il confronto versioni lo fa il SERVER: il device esegue e basta.
- Se dimentichi di alzare `FIRMWARE_VERSION` in `config.h` prima di pubblicare il
  `.bin`, il device si aggiorna, riferisce la versione vecchia, e si ri-aggiorna
  in loop ogni 6 ore. La versione nel binario E quella sul server devono avanzare
  insieme.
- Il binario attuale occupa ~60% dello slot: c'è margine, ma tienilo d'occhio
  (`pio run` stampa la percentuale).

---

## 9. Il pulsante (uno solo: pressione breve vs lunga)

UN solo bottone fisico (`BTN_PIN` 32, attivo-basso con pull-up interno: un capo al
GPIO, l'altro a GND). Le funzioni si distinguono per **stato** e **durata**:

| Stato | Pressione breve | Tenuto ≥ `LONG_PRESS_MS` (1 s) |
|---|---|---|
| Sveglia che suona | snooze (+9 min) | **spegne** — scatta mentre è ancora premuto |
| A riposo | marca letto il messaggio a schermo | — |
| All'accensione | — | factory reset |

Le scelte UX dietro la tabella:
- **Snooze = il gesto facile** (tocco al volo da assonnati), **dismiss = il gesto
  deliberato** (devi tenere premuto). E il dismiss scatta *appena* la soglia è
  raggiunta, senza aspettare il rilascio: "tieni premuto finché smette" funziona
  d'istinto.
- **Niente "foto successiva" manuale**: nei programmi rotation le foto avanzano da
  sole con `interval_minutes` — un'azione in meno da spiegare a chi usa la sveglia.

L'implementazione (`handleButton()` in `main.cpp`) è una mini macchina a stati su
tre variabili: `btnDown` (premuto, già debounced), `btnDownAt` (da quando),
`btnLongFired` (l'azione lunga è già scattata → ignora il rilascio). Il
**debounce** è il minimo indispensabile: alla prima lettura LOW si aspetta 25 ms e
si rilegge (i contatti meccanici "rimbalzano" per qualche millisecondo).

Dettaglio di correttezza sul "marca letto": il messaggio mostrato è *l'ultimo* tra
quelli scaduti (`dueMessageIndex()`), e il bottone marca letto **quello stesso
indice**. Display e acknowledge devono condividere la stessa funzione di scelta —
la prima versione usava due criteri diversi e marcava letto un messaggio mai visto.

---

## 10. Vincoli e idiomi da portarsi a casa

Riassunto dei pattern embedded visti, riutilizzabili ovunque:

1. **Timer cooperativi** con `ms - last >= intervallo` (wrap-safe) — mai `delay()`
   lunghi nel loop, o i bottoni smettono di rispondere.
2. **Il validatore di cache vive quanto la cache** (ETag in RAM, §5.3).
3. **Backoff sui retry** che stanno dentro loop veloci (§5.4).
4. **Niente buffer enormi**: JPEG a blocchi, sprite piccola e transitoria, perché
   senza PSRAM la RAM grande non c'è (§7).
5. **Lo stato remoto è la verità**: dopo ogni decisione differita (snooze), si
   ri-verifica contro i dati correnti del sync, non contro una copia salvata.
6. **Persistere il minimo**: tutto ciò che è ricostruibile dal server resta in RAM.
7. **Fallire verso uno stato sicuro**: 401 → re-pair; OTA fallito → slot vecchio;
   NTP morto → ora dal server; nessun dato → meglio non suonare che suonare a caso.

---

## 11. Lavorarci: build, flash, debug

```bash
cd firmware
~/.platformio/penv/bin/pio run                  # compila (stampa uso RAM/flash)
~/.platformio/penv/bin/pio run -t upload        # flash del CODICE via USB
~/.platformio/penv/bin/pio run -t uploadfs      # flash dei FILE (data/ → il font) su LittleFS
~/.platformio/penv/bin/pio device monitor       # seriale a 115200 baud
```

> `upload` e `uploadfs` sono **due cose diverse**: il primo scrive il programma,
> il secondo il contenuto di `data/` (il font) in una partizione separata. Dopo
> aver aggiunto `clock.vlw` serve `uploadfs`, altrimenti il device non lo vede.

Dove sono i pin / l'URL del server / il fuso? Tutto in `config.h` (e i pin del TFT
in `platformio.ini`). L'URL si può anche passare come build flag
`-DSERVER_URL='"https://..."'` senza editare il file.

Per il debug: `Serial.printf(...)` + monitor seriale è il tuo `dd()`. Il livello
di log del core (`-DCORE_DEBUG_LEVEL` in `platformio.ini`) è a 0 per la release;
alzalo a 3 in sviluppo per vedere cosa fanno WiFi/TLS/HTTP sotto al cofano
(occhio: le stringhe di log occupano flash).

Nota IDE: se VS Code segnala `cannot open source file "Arduino.h"`, è solo
l'estensione C/C++ che non conosce gli include-path di PlatformIO — il codice
compila lo stesso con `pio run`. Usa l'estensione **PlatformIO IDE** per far
sparire gli squiggle.

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
5. `DisplayUI.cpp`: `tftBlit` + `drawHeroTime` (la sprite) + `drawClockOverlay`
   (full vs solo-ora) + `halo`.
6. Rileggi la parte ETag della guida backend: vedere ENTRAMBI i lati dell'ETag è
   il momento in cui il design "fa click".
