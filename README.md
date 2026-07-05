# Smart Alarm 📟

A DIY **smart bedside alarm clock with a photo screen**, built around an ESP32, that a
family member can manage **remotely from their phone**.

The idea: you build the little clock and give it to someone you love (a parent, a
grandparent, your partner). From anywhere, you upload photos that appear on their
nightstand, send short messages to their screen, and — if you want — manage their
wake-up alarms. The device itself has a single button — a short press snoozes an
alarm or marks a message read, holding it dismisses the alarm. No app to install
on their side, nothing for them to configure.

```
   You (and family), on any phone/PC          The nightstand
   ┌──────────────────────────────┐        ┌─────────────────────┐
   │  Web panel (Laravel+Filament)│ HTTPS  │  ESP32 + 320×240 TFT│
   │  photos · messages · alarms  │◄──────►│  clock + photo +    │
   │  on a Raspberry Pi or a VPS  │        │  alarm + 1 button   │
   └──────────────────────────────┘        └─────────────────────┘
```

## Features

- 🖼 **Photo programs** — show one fixed photo, rotate through up to 10, or pin a
  *different photo to each day* on a calendar ("photo of the day").
- 💬 **Messages** — schedule a text that appears on the screen at a chosen time
  ("Good morning! Doctor at 10:00"). A short press on the button marks it read.
- ⏰ **Alarms** — one-time or recurring per weekday, managed from the web. Snooze
  and dismiss on the device's single button. A compact snapshot is cached on the
  device, so alarms still ring on schedule even if WiFi/the server is briefly down.
- 👥 **Two-person model** — a *photo & message manager* and an *alarm manager* can
  be two different accounts (or the same person). Great when an adult child manages
  photos for a parent who manages their own alarms — or vice versa.
- 🌙 **Night mode** — dimmed screen on a schedule, brightness/volume set remotely.
- 🔄 **OTA updates** — publish new firmware on the server; devices update themselves.
- 🔒 **Private by design** — photos are never publicly reachable; the device
  authenticates with its own token, people with their own logins.

## What you need

**Hardware (~€20 total):**

| Part | Notes | ~Price |
|---|---|---|
| ESP32 DevKit (ESP32-WROOM-32) | any common 30/38-pin dev board | €5 |
| 3.2" or 2.8" ILI9341 SPI TFT, 320×240 | the common red SPI module | €8–12 |
| Buzzer | active 3-pin module by default (just drive it HIGH/LOW); a 2-pin passive buzzer also works — flip `BUZZER_ACTIVE` to `0` in `config.h` | €1 |
| 1 momentary push button | snooze / dismiss / mark read | €1 |
| Breadboard + jumper wires, 5V USB supply | a 3D-printed case is a nice bonus | €3 |
| *Optional:* LED (+ a BC547 NPN transistor) | lights up while the alarm rings, wired to the button | €1 |

**Server (one of):**

- A **Raspberry Pi** at home (any model that runs Raspberry Pi OS, even a Zero 2 W),
  exposed with a free **Cloudflare Tunnel** — no port forwarding, no public IP needed.
- A **free cloud VPS** — e.g. [Oracle Cloud Always Free](https://www.oracle.com/cloud/free/)
  (their free ARM machines are more than enough), or any €3/month VPS.

**Plus:** a **domain name** (a cheap ~€5/year one is fine). HTTPS is mandatory —
the device refuses plain HTTP.

> **Skill level:** if you can solder nothing, wire a breadboard from a table,
> copy-paste commands into a terminal, and follow instructions exactly, you can
> build this. No programming needed.

---

## Step 1 — Install the server

Works the same on a Raspberry Pi (Raspberry Pi OS / Debian 12) or an Ubuntu/Debian VPS.

```bash
# 1. PHP 8.2+, composer, git, node (node builds the panel theme once)
sudo apt update
sudo apt install -y php php-cli php-fpm php-sqlite3 php-gd php-xml php-curl \
                    php-mbstring php-zip composer git nodejs npm

# 2. Get the code
git clone https://github.com/Faverooo/smart-alarm.git
cd smart-alarm/smart-alarm-laravel

# 3. Install dependencies and build the panel theme
composer install --no-dev
npm install && npm run build

# 4. Configure
cp .env.example .env
php artisan key:generate
nano .env
```

In `.env`, set at least:

```ini
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Europe/Rome        # ⚠ YOUR timezone — must match the firmware (Step 3)
APP_URL=https://alarm.example.com   # the public https URL you'll set up in Step 2
```

Then create the database and the scheduled jobs:

```bash
touch database/database.sqlite
php artisan migrate

# The scheduler runs cleanup jobs (expired pairings, orphaned photo files).
# Add this line with:  crontab -e
* * * * * cd /home/YOU/smart-alarm/smart-alarm-laravel && php artisan schedule:run >> /dev/null 2>&1
```

### Create the accounts

There is no public sign-up (it's your private family server). Create users from the
terminal:

```bash
php artisan tinker
```

```php
// You — the admin (sees everything):
\App\Models\User::create(['name' => 'Marco', 'email' => 'you@example.com',
    'password' => bcrypt('pick-a-strong-password'), 'is_admin' => true]);

// Each family member — a normal account:
\App\Models\User::create(['name' => 'Mum', 'email' => 'mum@example.com',
    'password' => bcrypt('another-password'), 'is_admin' => false]);
```

Type `exit` to leave tinker. (Don't run `php artisan db:seed` in production — the
seeder creates demo users with the password `password`.)

Finally cache the config: `php artisan config:cache`
(re-run this after every `.env` change).

## Step 2 — Put it on the internet (HTTPS)

The panel and the device both talk to one https URL. Pick the option that matches
your server:

### Option A — Raspberry Pi at home + Cloudflare Tunnel (recommended, free)

Your Pi stays safely behind your home router; Cloudflare carries the traffic. You
need a domain added to a (free) Cloudflare account.

```bash
# Serve the app locally with Caddy (simple, reliable):
sudo apt install -y caddy
sudo nano /etc/caddy/Caddyfile
```

```caddyfile
:8080 {
    root * /home/YOU/smart-alarm/smart-alarm-laravel/public
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

```bash
sudo systemctl restart caddy

# Install cloudflared and create the tunnel (follow the prompts / docs):
# https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/
cloudflared tunnel login
cloudflared tunnel create smart-alarm
cloudflared tunnel route dns smart-alarm alarm.example.com
# point the tunnel at the local Caddy:
#   ingress: alarm.example.com → http://localhost:8080
cloudflared tunnel run smart-alarm     # then install it as a service
```

### Option B — VPS (Oracle free tier or similar)

On a VPS you have a public IP, so Caddy can do HTTPS by itself. Point your domain's
DNS (an `A` record) at the VPS IP, open ports 80/443 in the cloud firewall, then:

```caddyfile
alarm.example.com {
    root * /home/YOU/smart-alarm/smart-alarm-laravel/public
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

Caddy fetches and renews the certificate automatically. That's the whole HTTPS setup.

**Check it works:** open `https://alarm.example.com` in a browser — you should see
the login page. Log in with the admin account from Step 1.

## Step 3 — Wire and flash the device

### Wiring

| TFT pin | ESP32 | | Other parts | ESP32 |
|---|---|---|---|---|
| VCC | 3V3 | | Buzzer + | GPIO 25 |
| GND | GND | | Buzzer − | GND |
| CS  | GPIO 15 | | Button | GPIO 32 → GND |
| RESET | GPIO 4 | | Button LED (optional, via transistor) | GPIO 26 |
| DC  | GPIO 2 | | | |
| MOSI (SDI) | GPIO 23 | | | |
| SCK | GPIO 18 | | | |
| LED (backlight) | GPIO 21 | | | |
| MISO (SDO) | GPIO 19 | | | |

The button needs no resistor (internal pull-up). The optional LED lights up while
an alarm rings; drive it through an NPN transistor (e.g. BC547) as a low-side
switch: LED anode → 3V3 through a current-limiting resistor, LED cathode →
transistor collector, transistor emitter → GND, transistor base → GPIO 26 through
a ~1kΩ resistor. Skip it entirely and GPIO 26 just stays unconnected — no
downside. Different wiring? Change the pins in `firmware/platformio.ini` (TFT) and
`firmware/src/config.h` (everything else).

### Flash

1. Install [VS Code](https://code.visualstudio.com/) and the **PlatformIO** extension
   (or `pip install platformio` for the CLI).
2. Open the `firmware/` folder and edit `src/config.h` — the two **⚠ CHANGE ME**
   blocks at the top:
   - `SERVER_URL` → your `https://alarm.example.com`
   - `TZ_INFO` → your timezone's POSIX string (must match `APP_TIMEZONE` from Step 1;
     the file links a lookup table).
3. Connect the ESP32 by USB and run:

```bash
cd firmware
pio run -t upload      # build + flash (all fonts are baked into the binary)
```

### First boot

1. The screen shows **"WiFi setup"** — on your phone, join the WiFi network
   `SmartAlarm-Setup` and a page opens where you pick your home WiFi and enter its
   password. (Only needed once.)
2. The device registers itself and shows a **6-character pairing code**, valid for
   1 hour (rebooting generates a fresh one).

## Step 4 — Pair it from the panel

1. The person managing **photos & messages** logs into the panel → *Devices* →
   **Add Device** → enters the code from the screen. They can tick *"I will also
   manage alarms"* if they manage everything themselves.
2. Otherwise, a second person logs in → *Devices* → **Join as Alarm Manager** →
   enters the same code (shared by the first person; it stays visible on the
   device page while the alarm slot is open).
3. Within a few seconds the device leaves the pairing screen and shows the clock.

That's it. From now on everything is managed from the panel:

| Panel section | What it does |
|---|---|
| **Photos** | Upload and crop photos (they're auto-resized for the screen) |
| **Programs** | *Fixed* (one photo), *Rotation* (cycle every N minutes), *Daily* (a calendar — pin a photo to each day) |
| **Devices** | Pick the active program, brightness, night mode hours, alarm volume, 12/24h clock; see online status, WiFi signal, firmware version |
| **Alarms** | One-time (a date) or recurring (weekdays) |
| **Messages** | Text + when it should appear on the screen |

The single button does everything: while an alarm rings, a **short press snoozes**
(+9 min) and **holding it for a second stops it** (one-time alarms auto-disable);
when idle, a short press **marks the on-screen message as read**. Hold it **while
powering on** to factory-reset (forgets the pairing, shows a fresh code).
Rotation photos advance on their own, on the interval set in the panel.

## Updating the firmware later (OTA)

Once a device is deployed you never need the USB cable again:

1. Raise the version in `firmware/src/config.h` (e.g. `0.1.1`) and `pio run`.
2. Copy `.pio/build/esp32dev/firmware.bin` to
   `smart-alarm-laravel/public/firmware/smart-alarm-0.1.1.bin` on the server.
3. In the server's `.env`:
   `FIRMWARE_VERSION=0.1.1`,
   `FIRMWARE_URL=https://alarm.example.com/firmware/smart-alarm-0.1.1.bin` and
   `FIRMWARE_MD5=<output of md5sum firmware.bin>`,
   then `php artisan config:cache`.
4. Devices check every 6 hours (and a minute after every reboot), download, flash
   and restart on their own. The panel shows the new version once it's done.

`FIRMWARE_MD5` is optional but recommended: the device refuses to flash a
download whose checksum doesn't match, so a truncated or corrupted binary can
never brick it.

Use a new filename for every version, and never reuse a version number.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Stuck on "WiFi setup" | Join `SmartAlarm-Setup` from a phone and configure WiFi |
| Pairing code expired | Reboot the device — a fresh code appears |
| "Connecting to server..." forever | Wrong `SERVER_URL`, or the tunnel/Caddy isn't running — check `https://YOUR-URL/up` answers `OK` |
| Clock shows wrong hour | `TZ_INFO` (firmware) and `APP_TIMEZONE` (server) disagree |
| Photos don't appear | Is a **program** assigned to the device, with photos in it? |
| Device shows offline in panel | It pings every minute; check its WiFi signal on the device page |
| OTA never triggers | `FIRMWARE_VERSION` must be **higher** than the running one; re-run `php artisan config:cache` |

## Security notes

- Photos live on the server's private disk — there is no public URL to them, ever.
  The device fetches them with its own token; people see them only logged in.
- The unauthenticated pairing endpoint is rate-limited; pairing codes are
  single-use, expire after 1 hour, and a claimed device can't be hijacked.
- By default the firmware does **not** validate the server's TLS certificate
  (`USE_INSECURE_TLS 1` in `config.h`) — traffic is encrypted but, in theory, an
  attacker on the same network could impersonate your server. Acceptable for most
  home builds; for the paranoid, set it to `0` and paste your CA in `ApiClient.cpp`.

## Repository layout

```
smart-alarm-laravel/   The web panel + API (Laravel 11 + Filament 5, SQLite)
firmware/              ESP32 firmware (PlatformIO, Arduino framework)
docs/                  IMPLEMENTATION_GUIDE.md (API contract) + two in-depth
                       code walkthroughs in Italian: COME_FUNZIONA.md (backend)
                       and COME_FUNZIONA_FIRMWARE.md (firmware)
```

Tests: `cd smart-alarm-laravel && php artisan test`.

## License

[MIT](LICENSE) — do whatever you like, no warranty. If you build one, that's the
whole point: enjoy! 🎁
