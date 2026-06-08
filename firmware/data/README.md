# LittleFS data partition

Files here are flashed to the device's LittleFS partition with:

```bash
pio run -t uploadfs
```

## clock.vlw — the hero clock font (optional)

Drop a **`clock.vlw`** smooth font here for true iPhone-style anti-aliased
numerals on the clock. Without it, the firmware automatically falls back to the
built-in 7-segment font (no error) — so this is purely a visual upgrade.

### How to generate `clock.vlw`
1. Install **Processing** (https://processing.org).
2. Open the font tool that ships with TFT_eSPI:
   `TFT_eSPI/Tools/Create_Smooth_Font/Create_font/Create_font.pde`
   (after a build, it's under `.pio/libdeps/esp32dev/TFT_eSPI/Tools/...`, or grab
   it from the TFT_eSPI GitHub repo).
3. In that sketch set:
   - `fontName` → a clean rounded sans (e.g. an SF-Pro / Helvetica / Inter `.ttf`
     placed in the sketch's `data/` folder, or a system font name)
   - `fontSize = 72`  (the hero size; ~64–80 looks right on 320×240)
   - keep the default Latin character range (full ASCII ≈ 35–45 KB — fine on
     LittleFS), or trim to `0-9 : space A M P` to shrink it.
4. Run the sketch → it writes `<FontName>72.vlw` into its `FontFiles/` folder.
5. Rename that file to **`clock.vlw`** and copy it here (`firmware/data/clock.vlw`).
6. Flash it: `pio run -t uploadfs`, then `pio run -t upload` for the code.

The display layer looks for `/clock.vlw` at boot (`DisplayUI::begin`) and switches
to it automatically. If the anti-aliased edges ever look noisy over a photo (an
ILI9341 read-back quirk with transparent smooth fonts), the fix is to render the
time into a small sprite first — see `drawHeroTime()` in `src/DisplayUI.cpp`.
