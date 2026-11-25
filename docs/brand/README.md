# smilir brand assets

**smilir** — *udara yang bergerak kecil* (a small movement of air, a breeze).
The mark is a single **S-shaped gust**: one continuous stroke that is heavy in
the two bowls and tapers to a point at each end, so the letter itself reads as
moving air rather than a static glyph.

| File | Use |
| --- | --- |
| `logo-white.svg` | mark for dark backgrounds (console sidebar, login) |
| `logo-mono.svg` | dark mark for light backgrounds (README light mode, print) |
| `app-icon.svg` | rounded-square icon: teal gradient gust on `#0E1013` |
| `../logo-white.png`, `../logo-mono.png` | README `<picture>` sources (256×256) |
| `../app-icon.png` | README app icon (180×180) |
| `../../public/assets/logo-white.png` | console sidebar + login (256×256, transparent) |
| `../../public/assets/app-icon-{32,180}.png` | favicon and login/apple-touch icon |

## Palette

| Token | Value | Note |
| --- | --- | --- |
| Ink | `#0E1013` | app icon background, mono mark |
| Breeze | `#7DF3DF` → `#14B8A6` | icon gradient (top-left → bottom-right) |
| Primary | `#0F766E` | existing UI primary (unchanged) |
| Link / run | `#2DD4BF` / `#14B8A6` | existing UI accents (unchanged) |

The teal UI palette already matched the name, so no CSS tokens changed.

## Re-rendering the PNGs

SVG is the source of truth. Any renderer works; with librsvg:

```bash
rsvg-convert -w 256 -h 256 docs/brand/logo-white.svg -o public/assets/logo-white.png
rsvg-convert -w 256 -h 256 docs/brand/logo-white.svg -o docs/logo-white.png
rsvg-convert -w 256 -h 256 docs/brand/logo-mono.svg  -o docs/logo-mono.png
rsvg-convert -w 180 -h 180 docs/brand/app-icon.svg   -o public/assets/app-icon-180.png
rsvg-convert -w  32 -h  32 docs/brand/app-icon.svg   -o public/assets/app-icon-32.png
```

## Renaming again

The display name lives in two places: `PN_BRAND` in `app/bootstrap.php`
(default) and `ui.title` in `app/config.php` (per installation). The console
reads it through `brand_name()` / `brand_logo()` / `brand_icon()` /
`brand_tagline()` in `public/lib.php`. Replace the SVGs above, re-render the
PNGs, and update those two values — no search-and-replace in views.
