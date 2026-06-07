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

## Phase 2 — REST API for the ESP32  ✅ IMPLEMENTED

Goal: a tiny JSON API the ESP polls. Auth = per-device Sanctum token issued at
pairing. All times sent as **local** strings (no timezone math on the device).

> **Built:** `php artisan install:api` (Sanctum + `routes/api.php` + tokens table).
> Controllers in `app/Http/Controllers/Api/V1/` (`PairController`, `SyncController`,
> `InteractionController`). Routes in `routes/api.php`. Tests:
> `tests/Feature/Api/DeviceApiTest.php` (9 passing). Device model gained
> `paired_at`, `last_synced_at`, `isOwned()`, `isClaimable()`, `isPairingExpired()`,
> `scopeStalePairing()`, and `PAIRING_TTL_MINUTES = 60`.
>
> **Pairing security (as built):**
> - `POST /api/v1/pair/init {mac}` → `201 {device_token, pairing_code, name, expires_in:3600}`.
>   Sets `paired_at = now()`, issues a fresh token (revokes old).
> - If the device is **already owned** (a user is linked) → **409** (no re-pair /
>   hijack). The owner must "Forget" it first.
> - The pairing window is **1 hour**: `App\Console\Commands\PruneUnpairedDevices`
>   (`devices:prune-unpaired`, scheduled every 15 min in `routes/console.php`)
>   deletes unowned devices past the window + revokes their tokens. The ESP then
>   gets 401 on next sync and re-pairs for a fresh code.
> - **Filament claim flow** rewritten (`ListDevices`): users claim by the **pairing
>   code shown on the device** — "Add Device" → photo manager (primary),
>   "Join as Alarm Manager" → alarm manager. Claiming an unowned device only works
>   while `isClaimable()` (within the window); a taken role can't be stolen.
>
> **Sync optimizations (as built):** see §2.6.

### 2.1 Pairing / auth model
1. ESP boots, connects to WiFi, reads its own MAC.
2. ESP calls `POST /api/v1/pair/init` with its MAC. Server **upserts** the device,
   (re)generates a `pairing_code`, issues a Sanctum **device token**, returns both.
3. ESP **stores the token** in NVS/flash and **displays the pairing_code** (and
   optionally the MAC) on the TFT.
4. Humans claim ownership in the Filament UI: both roles use the **same**
   `pairing_code`. "Add Device" → becomes `photo_user`; "Join as Alarm Manager"
   → becomes `alarm_user`. The 1-hour window only gates the FIRST (unowned) claim;
   once one owner exists, the second can join any time.
5. From then on the ESP authenticates every request with
   `Authorization: Bearer <device_token>`.

> **Pairing-code lifecycle (invariant, enforced by a `Device::saving` hook):** the
> code is non-null **exactly when an ownership slot is open** — unowned (the window
> code) or one of the two owners set (so the other can join). Once **both** owners
> are set the code is **nulled** (no standing join secret). Reopening a slot
> (e.g. "Remove Alarm Manager") mints a **fresh** code, so a removed manager's old
> code is dead. `/sync` + `/device/status` use `saveQuietly()` (no events) and never
> touch ownership, so they don't trigger it.

> Token scoping: issue with an ability like `->createToken('device', ['device'])`
> and resolve the device from `$request->user()` (the token's tokenable = Device).

> **Rate limiting:** `pair/init` → `throttle:pair` (20/min per IP, it's unauthed and
> creates a row+token); the authed group → `throttle:device` (120/min). These are
> NAMED limiters (AppServiceProvider) keyed by token/IP — the default `throttle:n,m`
> 500s here because it keys by `$user->getAuthIdentifier()` and Device isn't an
> Authenticatable. Unauthenticated `api/*` requests always get a 401 JSON (never a
> 302 to the HTML login) via `shouldRenderJsonWhen` in bootstrap/app.php.

### 2.2 Endpoints (prefix `/api/v1`)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/pair/init` | none | Register/refresh device, return token + pairing_code |
| GET | `/pair/status` | device | Pairing-screen poll: has a human claimed it yet? |
| GET | `/sync` | device | Everything the device needs in one payload (ETag/304) |
| GET | `/time` | device | Cheap, frequent clock sync (local wall-clock + UTC offset) |
| POST | `/device/status` | device | Heartbeat + telemetry (firmware, heap, rssi, ip) |
| POST | `/device/forget` | device | Device-initiated factory reset (physical button) |
| GET | `/photos/{photo}/render` | device | Photo resized to the TFT (exact-size JPEG, ETag/304) |
| GET | `/firmware/latest?current=x.y.z` | device | OTA check; returns `.bin` URL if a newer build exists |
| POST | `/alarms/{alarm}/dismissed` | device | Deactivate a fired one-time alarm |
| POST | `/messages/{message}/read` | device | Mark a message read (sets `is_read`) |
| POST | `/programs/photos/{photo}/shown` | device | Mark a program photo shown (pivot `is_shown`) |

**Snooze** is intentionally **not** an endpoint — it's a local 9-minute timer on the
device; nothing needs to round-trip to the server.

#### New-endpoint payloads

- `GET /pair/status` → `{ paired, alarm_user, photo_user, name, pairing_code, expires_in }`.
  The ESP shows the code after `pair/init`, then polls this every few seconds; when
  `paired` flips true it leaves the setup screen. `expires_in` (seconds) counts the
  device toward the 1-hour prune so it can warn the user.
- `GET /time` → `{ server_time, timezone, utc_offset, is_dst, unix }`. `server_time`
  is the resolved **Europe/Rome wall-clock** — the device compares alarm `time`
  strings against it directly and never does its own DST math.
- `POST /device/status` (all fields optional) `{ firmware_version, free_heap, rssi, ip }`
  → `{ ok: true }`. Drives the "online / last seen" dot and diagnostics in the panel.
- `POST /device/forget` → same shape as `pair/init` (`{ device_token, pairing_code,
  name, expires_in }`). Releases both owners, detaches the program, revokes the old
  token, reopens the pairing window. Authed by the device's own token, so only the
  physical device can self-reset.
- `GET /photos/{photo}/render?w=&h=` → a JPEG body (default 320×240, `cover`). Honors
  `If-None-Match` → **304**. Only renders photos in the device's current program.
- `GET /firmware/latest?current=` → `{ version, url, update_available, mandatory }`.
  Server-driven via `config/services.php` → `FIRMWARE_VERSION` / `FIRMWARE_URL`.

### 2.3 `/sync` response shape (example)
```json
{
  "server_time": "2026-06-05T14:30:00",
  "timezone": "Europe/Rome",
  "revision": "a1b2c3d4e5f6a7b8",
  "device": { "name": "Bedroom Alarm" },
  "settings": {
    "brightness": 80, "night_brightness": 10,
    "night_start": "22:00", "night_end": "07:00",
    "volume": 70, "time_format": "24"
  },
  "alarms": [
    { "id": 12, "time": "07:00", "scheduled_date": null,
      "days_of_week": ["Monday","Tuesday","Wednesday","Thursday","Friday"] },
    { "id": 18, "time": "09:30", "scheduled_date": "2026-06-08", "days_of_week": [] }
  ],
  "program": {
    "type": "daily",
    "interval_minutes": null,
    "photos": [
      { "id": 5,
        "render_url": "https://host/api/v1/photos/5/render",
        "caption": "Buongiorno!", "scheduled_for": "2026-06-05",
        "hash": "abc123def456" }
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
- `settings` → display/sound config the device applies (brightness, night-mode
  window + dim level, alarm volume, 12/24h). Editable per-device in the panel.
- **Photos are private** — stored on the non-web-served `local` disk, NOT the
  public `/storage` symlink. So there is no public `url`; the device downloads each
  photo ONLY via its token-gated `render_url` (`/api/v1/photos/{id}/render`, exact
  size, ETag-cached). Use the `hash` as the cache key. (The panel views the same
  files through a separate session-cookie route, `photos.serve` → `/photo-file`.)

### 2.4 Implementation steps (Laravel)
1. Ensure API routing exists: `php artisan install:api` (publishes `routes/api.php`,
   wires Sanctum). Sanctum is already required.
2. `routes/api.php`:
   ```php
   use App\Http\Controllers\Api\V1\{PairController, SyncController, TimeController,
       DeviceController, ImageController, FirmwareController, InteractionController};
   Route::prefix('v1')->group(function () {
       Route::post('pair/init', [PairController::class, 'init']);
       Route::middleware('auth:sanctum')->group(function () {
           Route::get('pair/status', [PairController::class, 'status']);
           Route::get('sync', SyncController::class);
           Route::get('time', TimeController::class);
           Route::post('device/status', [DeviceController::class, 'status']);
           Route::post('device/forget', [DeviceController::class, 'forget']);
           Route::get('photos/{photo}/render', [ImageController::class, 'render']);
           Route::get('firmware/latest', [FirmwareController::class, 'latest']);
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

### 2.6 Bandwidth optimizations (as built)
The ESP polls `/sync` often, so the payload is kept minimal and cacheable:
- **ETag + 304:** `/sync` sets an `ETag` = hash of the stable content (alarms +
  program + messages, excluding `server_time`). The ESP sends back
  `If-None-Match: "<etag>"`; if nothing changed it gets an empty **304** and keeps
  its cached data — no JSON, no photo re-download. This is the big win.
- **Daily / fixed → exactly ONE photo** in `program.photos` (daily resolves the
  photo pinned to today, else any unpinned). **Rotation →** up to `MAX_PHOTOS`.
- **Alarms:** only `is_active = true`, trimmed fields, `time` as `HH:MM`.
- **Messages:** only `is_read = false` AND `scheduled_at <= now()+24h`.
- **Per-photo `hash`** (`sha1(image_path|updated_at)`): the ESP caches the decoded
  image by hash and only re-downloads when the hash changes (photo replaced).
- Photos are already 320×240 server-side (resized on BOTH create and edit,
  idempotently), so `render_url` returns a small, ready-to-blit JPEG with little
  work. Photos are private (token-gated render only — no public file URL).
- Further options if needed: gzip (nginx), a `?since=` delta param, or a separate
  HEAD request for the photo hash before downloading.

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

## Phase 3 — Raspberry Pi 3B+ deployment + Cloudflare Tunnel

Goal: Laravel on the Pi behind nginx + PHP-FPM, reachable on the public internet via
a Cloudflare Tunnel — **no port forwarding, no public IP, no inbound firewall holes**.
The ESP32 and the panel both talk to `https://your-domain`; the Pi itself stays
private (nginx listens on localhost only, and `cloudflared` reaches it locally).

> **Pi 3B+ reality check.** Quad Cortex-A53, **1 GB RAM**, microSD I/O. That RAM is
> the constraint that shapes everything below: **do NOT run `npm run build` / Vite on
> the Pi** (it routinely OOM-kills on 1 GB), keep PHP-FPM workers low, and lean on the
> fact that the app uses **SQLite** (no separate DB server to feed). A 64-bit
> Raspberry Pi OS (Bookworm) is recommended — it ships PHP 8.3 and an arm64
> `cloudflared` package. Everything here assumes that.

### 3.0 One-time OS prep
```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Europe/Rome     # alarms are local wall-clock — match it
sudo raspi-config nonint do_change_locale en_GB.UTF-8     # or your locale

# A little swap so apt/composer/opcache don't get OOM-killed (1 GB is tight).
sudo dphys-swapfile swapoff
sudo sed -i 's/^CONF_SWAPSIZE=.*/CONF_SWAPSIZE=1024/' /etc/dphys-swapfile
sudo dphys-swapfile setup && sudo dphys-swapfile swapon
```

### 3.1 System packages (note: **no Node** — see 3.3)
```bash
sudo apt install -y nginx \
  php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-gd php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl \
  git unzip rsync
# Composer
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
```
`php8.3-gd` is required for the 320×240 photo resize; `php8.3-sqlite3` for the DB;
`php8.3-intl` for Filament. (No MySQL/Postgres — the app is SQLite.)

### 3.2 PHP-FPM tuning for 1 GB RAM
Edit `/etc/php/8.3/fpm/php.ini`:
```ini
; uploads (phone photos): app caps at 10 MB, leave headroom
upload_max_filesize = 16M
post_max_size = 20M
memory_limit = 256M

; opcache — big win on a slow SD card; disable timestamp checks in prod
opcache.enable = 1
opcache.memory_consumption = 64
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0      ; => you MUST reload FPM on every deploy
```
Edit `/etc/php/8.3/fpm/pool.d/www.conf` — cap workers so 4 of them can't eat the RAM:
```ini
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
pm.max_requests = 500
```
`sudo systemctl restart php8.3-fpm`. (The dev `php artisan serve` ignores php.ini —
this only matters under FPM. See the `dev-server-no-reload` memory note.)

### 3.3 Build assets on your DEV machine, copy them over
Vite + the custom Filament theme need Node; building on the Pi is slow and OOM-prone.
Build on your laptop/WSL and ship only the compiled output:
```bash
# on the DEV machine, in smart-alarm-laravel/
npm ci && npm run build           # produces public/build/ (app + Filament theme)
rsync -az public/build/  pi@raspberrypi.local:/var/www/smart-alarm/smart-alarm-laravel/public/build/
```
*(Fallback if you must build on the Pi: install Node 20 via NodeSource, ensure the
1 GB swap from 3.0 is on, then `npm ci && npm run build` — expect it to be slow.)*

### 3.4 Deploy the app
```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <repo> smart-alarm
cd smart-alarm/smart-alarm-laravel

composer install --no-dev --optimize-autoloader      # NO scripts need Node
cp .env.example .env                                  # edit per below
php artisan key:generate

touch database/database.sqlite
php artisan migrate --force          # creates users/sessions/cache/jobs/app tables
# (add --seed ONLY for a throwaway demo box; never on real data)

php artisan storage:link             # harmless; photos are private (local disk) regardless
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components # OK in production (NEVER in dev)
```
`.env` essentials (the **APP_URL line is load-bearing** — `render_url` and every
photo/asset URL the ESP receives are built from it):
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain          # the Cloudflare hostname, https
APP_TIMEZONE=Europe/Rome             # must match the alarm wall-clock design
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database                 # rate limiter + caches live here
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local                # photos on the PRIVATE disk (storage/app/private)
# Optional OTA (Phase 2): FIRMWARE_VERSION / FIRMWARE_URL
```

### 3.5 Permissions
www-data must own the writable trees — including `storage/app/private` where the
private photos live, and the SQLite file's directory (SQLite needs to write the
`-wal`/`-shm` siblings, so the **directory** must be writable, not just the file):
```bash
sudo chown -R www-data:www-data storage bootstrap/cache database
sudo chmod -R 775 storage bootstrap/cache database
```

### 3.6 nginx — listen on localhost only
Because `cloudflared` connects to nginx locally, nginx never needs a public/LAN
port. Binding to `127.0.0.1` means the Pi is unreachable except through the tunnel.
`/etc/nginx/sites-available/smart-alarm`:
```nginx
server {
    listen 127.0.0.1:80;                      # localhost ONLY — tunnel reaches it here
    server_name your-domain;
    root /var/www/smart-alarm/smart-alarm-laravel/public;
    index index.php;

    client_max_body_size 20M;                 # must be >= post_max_size

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }   # hide dotfiles
}
```
```bash
sudo ln -s /etc/nginx/sites-available/smart-alarm /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 3.7 The scheduler (required) + queue worker (optional)
`devices:prune-unpaired` runs on the Laravel scheduler, so the system cron must tick
it every minute, or expired-but-unclaimed devices never get pruned:
```bash
sudo crontab -u www-data -e
# add:
* * * * * cd /var/www/smart-alarm/smart-alarm-laravel && php artisan schedule:run >> /dev/null 2>&1
```
The app doesn't queue jobs today, so no worker is needed. If that changes, add a
systemd service (preferred over supervisor on Bookworm):
`/etc/systemd/system/smartalarm-worker.service`
```ini
[Unit]
Description=Smart Alarm queue worker
After=network.target
[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/smart-alarm/smart-alarm-laravel/artisan queue:work --sleep=3 --tries=3 --max-time=3600
[Install]
WantedBy=multi-user.target
```
`sudo systemctl enable --now smartalarm-worker`.

### 3.8 Cloudflare Tunnel
Prereqs: a domain on Cloudflare (free plan is fine), the DNS managed by Cloudflare.

**Install `cloudflared` (arm64 Bookworm) from Cloudflare's apt repo:**
```bash
sudo mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
  | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared bookworm main" \
  | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt update && sudo apt install -y cloudflared
```
*(32-bit Raspberry Pi OS instead? Grab the `cloudflared-linux-arm.deb` from the
Cloudflare GitHub releases and `sudo dpkg -i` it.)*

**Authenticate, create the tunnel, point a hostname at local nginx:**
```bash
cloudflared tunnel login                       # opens a browser; authorize your zone
cloudflared tunnel create smart-alarm          # prints a Tunnel ID + creds json path
```
Create `/etc/cloudflared/config.yml`:
```yaml
tunnel: <TUNNEL-ID>
credentials-file: /root/.cloudflared/<TUNNEL-ID>.json
ingress:
  - hostname: your-domain
    service: http://127.0.0.1:80        # exactly where nginx listens (3.6)
  - service: http_status:404            # catch-all (required, must be last)
```
**Route DNS and run it as a service:**
```bash
cloudflared tunnel route dns smart-alarm your-domain   # creates the proxied CNAME
sudo cloudflared service install                       # installs + starts systemd unit
sudo systemctl status cloudflared                      # should be active (running)
```
Cloudflare now terminates TLS at the edge and forwards to the tunnel, so the ESP and
the panel get real HTTPS to `your-domain` with no certs to manage on the Pi.

### 3.9 Trust the proxy so Laravel knows it's HTTPS
`cloudflared` forwards `X-Forwarded-Proto: https`. nginx is bound to localhost, so
only the tunnel can reach it — trusting all proxies is safe here. In
`bootstrap/app.php`, inside `->withMiddleware(...)`:
```php
use Illuminate\Http\Request;

$middleware->trustProxies(at: '*', headers:
    Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
```
(`APP_URL=https://...` already makes generated URLs correct; this fixes
`$request->isSecure()` and secure-cookie flags.) Then `php artisan config:cache`.

### 3.10 Smoke test from OUTSIDE the network
From a phone on cellular (not your WiFi), or any external host:
```bash
# the panel login should load over HTTPS:
curl -sI https://your-domain/login | head -1                 # HTTP/2 200

# the device pairing endpoint should mint a token:
curl -s -X POST https://your-domain/api/v1/pair/init \
  -H 'Accept: application/json' -d 'mac_address=AA:BB:CC:DD:EE:99'
# → {"device_token":"...","pairing_code":"XXXXXX","name":"...","expires_in":3600}
```
Then log into the panel, "Add Device" with that `pairing_code`, attach a program,
and confirm `GET https://your-domain/api/v1/sync` (with the token) returns it. That
exercises the exact path the ESP will use — including the HTTPS/`APP_URL` assumptions
dev can't.

### 3.11 Redeploy checklist (each update)
```bash
# on DEV: npm run build && rsync public/build/ → Pi   (only if assets changed)
cd /var/www/smart-alarm/smart-alarm-laravel
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize            # re-caches config/routes/views
php artisan filament:cache-components
sudo systemctl reload php8.3-fpm   # REQUIRED: opcache.validate_timestamps=0 (3.2)
```

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
4. **Wait for a human to claim it:** poll `GET /api/v1/pair/status` every ~5s. While
   `paired == false`, keep showing the code (and a countdown from `expires_in`). When
   `paired` flips true → leave the setup screen and enter the main loop. If the token
   ever 401s (window expired → device pruned), restart from step 3 to get a new code.
5. Subsequent boots: load token from NVS, go straight to the sync loop.
6. **Factory reset** (hold a button on boot): `POST /api/v1/device/forget` with the
   stored token → returns a fresh token + code; store them and go back to step 3.

### 4.4 Main loop
```
on boot and every ~6h:
    GET /api/v1/firmware/latest?current=<fw>  → if update_available, OTA-flash url

every TIME_INTERVAL (e.g. 5 min, if no DS3231):
    GET /api/v1/time  → trust server_time (Europe/Rome wall-clock) for the clock

every SYNC_INTERVAL (e.g. 60s):
    GET /api/v1/sync  (Bearer token, send If-None-Match: "<last revision>")
    if 304: keep cached data, draw nothing new
    else: parse JSON → store settings, alarms[], program, messages[]; save revision
          for each program photo: if hash changed → GET render_url → decode → cache

every STATUS_INTERVAL (e.g. 5 min):
    POST /api/v1/device/status {firmware_version, free_heap, rssi, ip}  // heartbeat

every second:
    now = local time from RTC/NTP
    apply brightness (settings: night_brightness between night_start..night_end)
    for each alarm:
        if matches(now):  // see 4.5
            ring()  // buzzer at settings.volume + flash screen until dismissed
    render screen (clock format from settings.time_format):
        - background = current program photo (fixed / rotation by interval / today's daily)
        - overlay caption + any due message (scheduled_at <= now && !is_read)
        - clock + next alarm

on "dismiss" button while ringing:
    stop buzzer
    if alarm.scheduled_date != null:  // one-time
        POST /api/v1/alarms/{id}/dismissed
    // snooze button: just set a local 9-min re-ring timer — no server call
on "read/next" button:
    if a message is showing: POST /api/v1/messages/{id}/read
on photo shown (optional bookkeeping):
    POST /api/v1/programs/photos/{id}/shown
```

### 4.5 Alarm matching (local time, no TZ math)
```
matches(now, alarm):
    // /sync already returns only active alarms, so no is_active check needed
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
- Photos are already 320×240 server-side (create + edit), so no on-device scaling
  needed. Photos are private: fetch them only from the token-gated `render_url`
  (send the Bearer token on the image GET too); there is no public file URL.

---

## Phase reference / status
- **Phase 1 (DONE):** Filament backend — resources, role scoping, pairing-code
  device flow, daily calendar, lightbox, custom theme.
- **Phase 2 (DONE):** REST API — pair/init + pair/status (1h window, prune), sync
  (ETag/304, minimal payload, settings), time, device/status (telemetry),
  device/forget (factory reset), photos/{id}/render (TFT-sized JPEG), firmware/latest
  (OTA), dismiss/read/shown. 17 API tests passing (32 total).
- **Phase 3 (DOCUMENTED, not yet run):** full Pi 3B+ runbook in §3.0–3.11 — nginx
  (localhost-only) + PHP-FPM tuned for 1 GB, build assets on dev & rsync, SQLite,
  Cloudflare Tunnel, scheduler cron for `devices:prune-unpaired`, trust-proxy. Do it
  on real hardware to validate the `APP_URL`/HTTPS assumptions dev can't.
- **Phase 4 (NEXT):** ESP32 firmware.

## Test users (seeded)
`admin@smartalarm.local` (admin), `marco@smartalarm.local` (alarm),
`angelica@smartalarm.local` (photo) — all password `password`.
