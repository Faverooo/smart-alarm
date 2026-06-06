# Smart Alarm / Smart Clock — Implementation Guide

> Reusable, step-by-step guide to take the project from the current Filament backend
> to a working device: REST API → Raspberry Pi deployment → ESP32 firmware.
> Keep this file updated as phases complete.

---

## 0. System overview

A bedside **smart alarm clock**: an **ESP32 + 3.2" TFT (320×240)** device that
shows photos/messages and rings alarms. A **Laravel 11 + Filament 3** backend
(running on a **Raspberry Pi**, exposed via **Cloudflare Tunnel**) lets people
manage it from a browser. One device is shared by two roles ("two-key" model):

- **Alarm manager** (`devices.alarm_user_id`) — sets alarms.
- **Photo/Message manager** (`devices.photo_user_id`) — uploads photos, writes
  messages, picks the active program. Single-user mode = same user in both keys.

### Core design decisions (do NOT silently change these)
- **Local wall-clock time**, NOT UTC. `APP_TIMEZONE=Europe/Rome`; `alarms.time`
  and `messages.scheduled_at` store local time verbatim. Correct for a fixed
  location and DST-safe. The ESP keeps its RTC on the same local timezone.
- **Photos are resized to 320×240** on upload (`intervention/image` v4 + GD).
- **Program types:** `fixed` (1 photo, always shown), `rotation` (≤10 photos,
  cycle every `interval_minutes`), `daily` (one photo per calendar day, pinned via
  the `photo_program.scheduled_for` pivot date). Type is locked after creation.
- **Pairing** uses a 6-char `pairing_code` shown on the device, never a list of
  all users.

### Current schema (already migrated)
- `users`: + `is_admin` (bool)
- `devices`: `mac_address` (unique), `name`, `pairing_code`, `alarm_user_id`,
  `photo_user_id`, `program_id`
- `alarms`: `device_id`, `scheduled_date` (nullable → one-time), `time` (local),
  `days_of_week` (json), `is_active`
- `messages`: `device_id`, `text_content`, `scheduled_at` (local), `is_read`
- `photos`: `user_id`, `image_path`, `caption`
- `programs`: `user_id`, `name`, `type`, `interval_minutes`
- `photo_program` pivot: `photo_id`, `program_id`, `scheduled_for`, `is_shown`

`Device` already uses `Laravel\Sanctum\HasApiTokens` — device API auth is ready.

---

## Phase 2 — REST API for the ESP32

Goal: a tiny JSON API the ESP polls. Auth = per-device Sanctum token issued at
pairing. All times sent as **local** strings (no timezone math on the device).

### 2.1 Pairing / auth model
1. ESP boots, connects to WiFi, reads its own MAC.
2. ESP calls `POST /api/v1/pair/init` with its MAC. Server **upserts** the device,
   (re)generates a `pairing_code`, issues a Sanctum **device token**, returns both.
3. ESP **stores the token** in NVS/flash and **displays the pairing_code** (and
   optionally the MAC) on the TFT.
4. Humans claim ownership in the Filament UI (existing flow): the photo manager
   registers by MAC → becomes `photo_user`; the alarm manager joins with the
   `pairing_code` → becomes `alarm_user`.
5. From then on the ESP authenticates every request with
   `Authorization: Bearer <device_token>`.

> Token scoping: issue with an ability like `->createToken('device', ['device'])`
> and resolve the device from `$request->user()` (the token's tokenable = Device).

### 2.2 Endpoints (prefix `/api/v1`)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/pair/init` | none | Register/refresh device, return token + pairing_code |
| GET | `/sync` | device | Everything the device needs in one payload |
| POST | `/alarms/{alarm}/dismissed` | device | Deactivate a fired one-time alarm |
| POST | `/messages/{message}/read` | device | Mark a message read (sets `is_read`) |
| POST | `/programs/photos/{photo}/shown` | device | Mark a program photo shown (pivot `is_shown`) |
| GET | `/photos/{photo}/raw` | device | Stream the 320×240 JPEG (optional; can use `/storage` URL) |

### 2.3 `/sync` response shape (example)
```json
{
  "server_time": "2026-06-05T14:30:00",
  "timezone": "Europe/Rome",
  "device": { "name": "Bedroom Alarm" },
  "alarms": [
    { "id": 12, "time": "07:00", "scheduled_date": null,
      "days_of_week": ["Monday","Tuesday","Wednesday","Thursday","Friday"], "is_active": true },
    { "id": 18, "time": "09:30", "scheduled_date": "2026-06-08", "days_of_week": [], "is_active": true }
  ],
  "program": {
    "type": "daily",
    "interval_minutes": null,
    "photos": [
      { "id": 5, "url": "https://host/storage/photos/abc.jpg", "caption": "Buongiorno!", "scheduled_for": "2026-06-05" }
    ]
  },
  "messages": [
    { "id": 3, "text": "Ti voglio bene!", "scheduled_at": "2026-06-05T20:00:00" }
  ]
}
```
- `fixed`/`rotation` → `program.photos` is the full list; device cycles every
  `interval_minutes` (rotation) or shows `[0]` (fixed).
- `daily` → device shows the photo whose `scheduled_for == today`, else falls back
  to any unpinned one (server can pre-resolve "today's photo" to simplify the ESP).

### 2.4 Implementation steps (Laravel)
1. Ensure API routing exists: `php artisan install:api` (publishes `routes/api.php`,
   wires Sanctum). Sanctum is already required.
2. `routes/api.php`:
   ```php
   use App\Http\Controllers\Api\V1\{PairController, SyncController, InteractionController};
   Route::prefix('v1')->group(function () {
       Route::post('pair/init', [PairController::class, 'init']);
       Route::middleware('auth:sanctum')->group(function () {
           Route::get('sync', SyncController::class);
           Route::post('alarms/{alarm}/dismissed', [InteractionController::class, 'alarmDismissed']);
           Route::post('messages/{message}/read', [InteractionController::class, 'messageRead']);
           Route::post('programs/photos/{photo}/shown', [InteractionController::class, 'photoShown']);
       });
   });
   ```
3. `PairController::init(Request $r)`:
   - validate `mac_address` (regex `^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$`)
   - `Device::firstOrCreate(['mac_address' => strtoupper($mac)])`
   - set/refresh `pairing_code = strtoupper(Str::random(6))`
   - `$token = $device->createToken('device', ['device'])->plainTextToken;`
   - return `{ device_token, pairing_code, name }`
4. `SyncController` (single `__invoke`): `$device = $request->user();` then build the
   JSON above from `$device->alarms`, `$device->program` (+ photos), `$device->messages`
   (e.g. `where('is_read', false)` and due soon). Use absolute photo URLs
   (`Storage::disk('public')->url(...)` — `config/filesystems.php` `url` is relative
   `/storage`, so prefix with `config('app.url')` for the device, OR set an absolute
   URL just for the API responses).
5. `InteractionController`: scope by the authenticated device
   (`abort_unless($alarm->device_id === $device->id, 403)`), then update
   `is_active=false` / `is_read=true` / pivot `is_shown=true`.
6. **Important — authorize the resolved models belong to the calling device.** Never
   trust the path id alone.

### 2.5 Testing the API (before hardware)
```bash
# pair
curl -X POST host/api/v1/pair/init -H 'Accept: application/json' \
  -d 'mac_address=AA:BB:CC:DD:EE:FF'
# sync (use returned token)
curl host/api/v1/sync -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```
Add a `tests/Feature/Api/SyncTest.php` (actingAs device token) mirroring the
existing `ProgramPageTest` style.

---

## Phase 3 — Raspberry Pi deployment

Target: Laravel on the Pi behind nginx + PHP-FPM, public via Cloudflare Tunnel.

### 3.1 System packages
```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath \
  git unzip
# Composer
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
# Node (for the asset build) — use NodeSource or nvm for Node 20+
```

### 3.2 PHP limits (so phone photos upload)
Edit `/etc/php/8.3/fpm/php.ini`:
```
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
```
`sudo systemctl restart php8.3-fpm`. (The dev `php artisan serve` ignores these —
only matters under FPM. See note in `dev-server-no-reload` memory.)

### 3.3 App setup
```bash
cd /var/www
sudo git clone <repo> smart-alarm && cd smart-alarm/smart-alarm-laravel
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # builds app + the custom Filament theme
cp .env.example .env              # then edit (below)
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force       # add --seed only for a demo box
php artisan storage:link
php artisan config:cache route:cache view:cache
php artisan filament:cache-components   # OK in production (NOT in dev)
```
`.env` essentials:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain
APP_TIMEZONE=Europe/Rome
DB_CONNECTION=sqlite
SESSION_DRIVER=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

### 3.4 Permissions
```bash
sudo chown -R www-data:www-data storage bootstrap/cache database
sudo chmod -R 775 storage bootstrap/cache
```

### 3.5 nginx (server block)
```nginx
server {
    listen 80;
    server_name your-domain;
    root /var/www/smart-alarm/smart-alarm-laravel/public;
    index index.php;
    client_max_body_size 25M;     # must match post_max_size
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```
`sudo ln -s` into `sites-enabled`, `sudo nginx -t && sudo systemctl reload nginx`.

### 3.6 Queue worker (if/when jobs are queued)
`/etc/supervisor/conf.d/smartalarm-worker.conf`:
```
[program:smartalarm-worker]
command=php /var/www/smart-alarm/smart-alarm-laravel/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
```
`sudo supervisorctl reread && sudo supervisorctl update`.

### 3.7 Cloudflare Tunnel
```bash
# install cloudflared, then:
cloudflared tunnel login
cloudflared tunnel create smart-alarm
# ~/.cloudflared/config.yml:
#   tunnel: <tunnel-id>
#   credentials-file: /home/pi/.cloudflared/<tunnel-id>.json
#   ingress:
#     - hostname: your-domain
#       service: http://localhost:80
#     - service: http_status:404
cloudflared tunnel route dns smart-alarm your-domain
sudo cloudflared service install     # run as a service
```
Set `APP_URL=https://your-domain` and (Laravel 11) trust proxies in
`bootstrap/app.php` (`$middleware->trustProxies(at: '*')`) so HTTPS URLs/cookies work.

### 3.8 Redeploy checklist (each update)
`git pull` → `composer install --no-dev` → `npm run build` →
`php artisan migrate --force` → `php artisan optimize` →
`php artisan filament:cache-components` → reload FPM.

---

## Phase 4 — ESP32 firmware

### 4.1 Hardware
- **ESP32** dev board (WiFi).
- **3.2" TFT 320×240**, typically **ILI9341** SPI (check your panel's driver).
- **Buzzer** (passive, PWM) or small speaker for the alarm.
- **1–2 push buttons**: "dismiss alarm" and "mark message read / next".
- Optional **RTC (DS3231)** for accurate time without constant NTP. (Can also use
  NTP + keep time in RAM; DS3231 survives reboots/outages better.)
- Optional light sensor for auto-brightness.

### 4.2 Arduino libraries
- `TFT_eSPI` (configure `User_Setup.h` for your panel + pins) for the display.
- `WiFi` + `HTTPClient` (built-in) for networking; `WiFiClientSecure` for HTTPS.
- `ArduinoJson` for parsing `/sync`.
- `Preferences` (NVS) to persist the device token.
- `WiFiManager` (tzapu) for first-run WiFi provisioning (captive portal).
- `TJpg_Decoder` (or `JPEGDecoder`) to draw the JPEG photo on the TFT.
- Time: `configTzTime("CET-1CEST,M3.5.0,M10.5.0/3", "pool.ntp.org")` to keep the
  ESP clock on **Europe/Rome local time** (matches the server). If using DS3231,
  sync it from NTP periodically.

### 4.3 Boot / pairing flow
1. First boot: `WiFiManager` captive portal → user enters WiFi creds.
2. Read MAC: `WiFi.macAddress()`.
3. If no stored token: `POST /api/v1/pair/init {mac_address}` → store `device_token`
   in NVS; show the returned `pairing_code` (big) + MAC on the TFT with a hint
   ("Enter this code in the app").
4. Subsequent boots: load token from NVS, go straight to the sync loop.

### 4.4 Main loop
```
every SYNC_INTERVAL (e.g. 60s):
    GET /api/v1/sync  (Bearer token)
    parse JSON → store alarms[], program, messages[] in RAM
    download the current photo if its URL changed → cache decoded/draw to TFT

every second:
    now = local time from RTC/NTP
    for each active alarm:
        if matches(now):  // see 4.5
            ring()  // buzzer + flash screen until dismissed
    render screen:
        - background = current program photo (fixed / rotation by interval / today's daily)
        - overlay caption + any due message (scheduled_at <= now && !is_read)
        - clock + next alarm

on "dismiss" button while ringing:
    stop buzzer
    if alarm.scheduled_date != null:  // one-time
        POST /api/v1/alarms/{id}/dismissed
on "read/next" button:
    if a message is showing: POST /api/v1/messages/{id}/read
```

### 4.5 Alarm matching (local time, no TZ math)
```
matches(now, alarm):
    if !alarm.is_active: return false
    if alarm.scheduled_date != null:        // one-time
        return now.date == alarm.scheduled_date && now.hh:mm == alarm.time
    else:                                    // recurring
        return alarm.days_of_week contains now.weekday_name && now.hh:mm == alarm.time
```
Compare to the minute; debounce so it fires once per minute. `time` is `"HH:MM"`
local — compare directly to the ESP's local clock.

### 4.6 Program rendering
- `fixed`: draw `photos[0]` always.
- `rotation`: advance index every `interval_minutes`.
- `daily`: pick `photos[i].scheduled_for == today`; if none, keep last/show a default.
  After drawing, optionally `POST /programs/photos/{id}/shown`.

### 4.7 Robustness
- Cache last good `/sync` payload; keep working if WiFi/server is down.
- Backoff on HTTP errors; re-pair only if the token is rejected (401).
- Photos are already 320×240 server-side, so no on-device scaling needed.

---

## Phase reference / status
- **Phase 1 (DONE):** Filament backend — resources, role scoping, pairing-code
  device flow, daily calendar, lightbox, custom theme.
- **Phase 2 (NEXT):** REST API above.
- **Phase 3:** Raspberry Pi + Cloudflare Tunnel.
- **Phase 4:** ESP32 firmware.

## Test users (seeded)
`admin@smartalarm.local` (admin), `marco@smartalarm.local` (alarm),
`angelica@smartalarm.local` (photo) — all password `password`.
