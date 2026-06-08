# Smart Alarm — ESP32 firmware (Phase 4)

Firmware for the bedside device: an **ESP32 + 3.2" ILI9341 320×240 SPI TFT** that
pairs with the Laravel backend, syncs alarms/photos/messages, shows a clock over
the active program photo, and rings alarms. Built with **PlatformIO**.

It implements the exact API contract in `../docs/IMPLEMENTATION_GUIDE.md` §2 and
the device flow in §4. All time handling is **local wall-clock (Europe/Rome)** —
no timezone math on the device, matching the backend's design.

## Layout
| File | Responsibility |
|---|---|
| `platformio.ini` | Board + libs; **TFT_eSPI panel/pins configured here via build flags** |
| `src/config.h` | Server URL, intervals, the GPIOs we drive (backlight, buzzer, buttons), TZ |
| `src/types.h` | Structs mirroring the `/sync` payload |
| `src/Storage.*` | NVS persistence of the device token + last ETag |
| `src/ApiClient.*` | All HTTP: pair/init, pair/status, sync (ETag/304), time, status, photo, callbacks |
| `src/DisplayUI.*` | TFT drawing, JPEG decode, backlight PWM, buzzer |
| `src/main.cpp` | State machine: Boot → WiFi → Pairing → Running; clock + alarm loop |

## Wiring (defaults — change in `platformio.ini` / `config.h`)
**TFT (SPI), in `platformio.ini`:** MISO 19, MOSI 23, SCLK 18, CS 15, DC 2, RST 4.
**Driven in `config.h`:** backlight `BL_PIN` 21 (PWM), buzzer 25, buttons —
dismiss 32, snooze 33, next 27 (all `INPUT_PULLUP`, wired to GND, active-low).

## Build & flash
```bash
# Install PlatformIO CLI (once):  pip install -U platformio
cd firmware
pio run                 # compile
pio run -t upload       # flash the firmware over USB
pio run -t uploadfs     # flash the LittleFS data partition (clock font) — optional
pio device monitor      # serial @115200
```

## Clock face
The home screen is an iOS-lock-screen style overlay: the photo stays clear and
full-bleed, with the date + large clock floating lower-left, kept legible by an
outline halo (no boxes). The hero time uses an anti-aliased **`clock.vlw`** font
if you install one (see `data/README.md`); otherwise it falls back automatically
to the built-in 7-segment font. Pixel positions live at the top of
`drawClockOverlay()` in `src/DisplayUI.cpp` and may want a nudge once you see it
on your panel.

## First-run flow
1. Boots into a WiFi captive portal (`SmartAlarm-Setup`); join it and enter your
   WiFi credentials.
2. Calls `POST /pair/init`, stores the device token in NVS, and shows a 6-char
   **pairing code** on screen.
3. In the web panel: **Add Device** (photo manager) / **Join as Alarm Manager**,
   entering that code. The device polls `/pair/status`; when claimed it starts.
4. **Factory reset:** hold the *dismiss* button while powering on → calls
   `/device/forget`, gets a fresh code, and re-pairs.

## Notes / TODO before shipping on real hardware
- **TLS:** defaults to `setInsecure()` (`USE_INSECURE_TLS=1`) because Cloudflare
  rotates edge certs. For real cert validation, set it to `0` and paste a root CA
  into `ApiClient.cpp`.
- Pin numbers are guesses for a generic dev board — **verify against your wiring**
  before the first flash (especially backlight/buzzer, to avoid driving the wrong
  pad).
- The custom `settings` block (brightness/night/volume/12-24h) is fully wired:
  editable per-device in the panel ("Display & Sound" section) and served from
  the device's own DB columns by `/sync` (covered by `test_sync_includes_settings`).
- OTA (`/firmware/latest`) is **not wired up yet** (the `FIRMWARE_INTERVAL_MS`
  timer exists in config but isn't polled, and there's no `Update.h` flash step);
  add it once you have a `.bin` hosting story.
- Rotation/daily caches only the *current* photo's JPEG (re-fetched on change) to
  stay within RAM on a non-PSRAM board.
