# Artwork Vectorizer

Converts raster artwork into a colour-separated, print-ready SVG: **one editable
layer per ink**, each snapped to a PMS colour.

```
<svg width="1200" height="453" viewBox="0 0 1200 453" fill="none">
  <g id="color-1" data-color="#E4002B" data-area="25.88" data-pms="PMS 185 C" fill="#E4002B">
    <path d="M304 452 ..."/>
  </g>
  ...
</svg>
``` 

Output is `<path>` elements with plain hex fills only — no gradients, strokes, CSS
classes or `<text>` — so it survives editors that rebuild SVGs from paths.

## Install

```bash
composer config repositories.artwork-vectorizer vcs git@github.com:SportsGearSwag/artwork-vectorizer.git
composer require sportsgearswag/artwork-vectorizer:^1.0
```

## System requirements

The tracing itself runs in external programs. **These are not installed by
Composer** — add them to your image:

| binary | needed for | licence |
|---|---|---|
| `imagemagick` | required — all raster work | Apache-2.0 |
| `potrace` | best on flat/logo artwork | **GPLv2** |
| `vtracer` | best on shaded and photographic artwork | MIT |
| `librsvg2-bin` | optional — accuracy measurement | LGPL |

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
        imagemagick potrace librsvg2-bin

RUN curl -fsSL "https://github.com/visioncortex/vtracer/releases/download/1.0.0-alpha.2/vtracer-$(uname -m)-unknown-linux-musl.tar.gz" \
      | tar -xz -C /usr/local/bin vtracer \
    && chmod +x /usr/local/bin/vtracer \
    || echo "vtracer unavailable; falling back to potrace"
```

Neither tracer is mandatory. With both missing, `PhpTracer` takes over — it needs
nothing but ImageMagick, and emits polygons instead of fitted curves. On flat
artwork it measures within ~0.5% of potrace; on shaded artwork it is noticeably
worse. `potrace` is GPLv2, so if that is a problem, vtracer alone (or the PHP
fallback) works.

## Usage

```php
use Sgs\Vectorizer\{VectorizerService, PaletteExtractor, ArtworkClassifier, PotraceTracer,
    VtracerTracer, PhpTracer, SvgAssembler, ImageMagick, PathTransformer, TraceOptions, PmsPalette};
use Sgs\Vectorizer\Palette\JsonPaletteProvider;
use Symfony\Component\Filesystem\Filesystem;

$magick  = new ImageMagick();
$palette = new PaletteExtractor($magick);
$paths   = new PathTransformer();

$vectorizer = new VectorizerService(
    $magick,
    $palette,
    new ArtworkClassifier($magick, $palette),
    new PotraceTracer($magick, $paths),
    new VtracerTracer(),
    new PhpTracer($magick),
    new SvgAssembler(),
    new PmsPalette(new JsonPaletteProvider()),   // bundled Pantone Coated deck
    new Filesystem(),
    sys_get_temp_dir(),
);

// Let it pick the settings for this artwork
$detected = $vectorizer->inspect('/path/to/logo.png');
$options  = TraceOptions::fromPreset($detected['preset']);

$result = $vectorizer->convert('/path/to/logo.png', $options, $detected['engine'], $detected['preset']);

file_put_contents('logo.svg', $result['svg']);

foreach ($result['layers'] as $layer) {
    echo $layer['pms'] ?? $layer['hex'], "  ", $layer['share'], "%\n";
}
```

See `examples/convert.php` for a runnable version.

## Symfony

Every class is constructor-injectable and autowires, except two things you must
configure:

```yaml
services:
    Sgs\Vectorizer\ImageMagick:
        arguments: { $magickBinary: 'magick' }      # 'convert' on ImageMagick 6
    Sgs\Vectorizer\PotraceTracer:
        arguments: { $potraceBinary: 'potrace' }
    Sgs\Vectorizer\VtracerTracer:
        arguments: { $vtracerBinary: 'vtracer' }
    Sgs\Vectorizer\VectorizerService:
        arguments: { $tmpDir: '%kernel.project_dir%/var/tmp' }

    # Where your ink list comes from - see "Palettes" below
    Sgs\Vectorizer\Palette\PaletteProviderInterface:
        alias: App\Vectorizer\MyPaletteProvider
```

## Palettes

Colours are snapped to the nearest orderable ink, so the emitted hex is always a
real one. Supply the list by implementing one method:

```php
use Sgs\Vectorizer\Palette\PaletteProviderInterface;

final class MyPaletteProvider implements PaletteProviderInterface
{
    /** @return list<array{code: string, hex: string}> */
    public function colours(): array
    {
        return [
            ['code' => 'PMS 185 C', 'hex' => '#E4002B'],
            // ...
        ];
    }
}
```

`JsonPaletteProvider` reads a JSON array of `{code, hex}` (also accepts
`{pmsCode, hexCode}`) and defaults to the 1,247-entry Pantone Coated deck bundled
in `resources/pms-colors.json`.

**WHITE and BLACK are added automatically.** The Coated deck contains neither —
its L\* only spans 9.3–91.9 — so without them black snaps to PMS 296 C, a navy, at
ten times the error of any other ink. If your palette already defines an ink at
`#FFFFFF` or `#000000`, yours wins.

## Presets

`TraceOptions::fromPreset()` accepts:

| preset | for | engine |
|---|---|---|
| `logo` | flat spot-colour artwork | potrace |
| `detailed` | flat artwork with small accents and thin outlines | potrace |
| `illustration` | shaded mascot / sticker / tee art | vtracer |
| `photo` | continuous tone — posterised likeness, not print-ready | vtracer |
| `max_detail` | complex painted artwork; slow, large output | vtracer |

`inspect()` picks one by measuring how much of the artwork sits on a small ink
list. Use it rather than guessing — running flat artwork through `photo` softens
every edge, and running shaded artwork through `logo` bands it.

`max_detail` is **not** "highest quality everywhere": on flat logo artwork it
measures worse than `logo` while producing hundreds of times more paths, because
the extra colours go on anti-aliasing rather than detail.

## Measuring the result

```php
$deviation = $vectorizer->measureDeviation($src, $result['svg']);  // % vs original
$coverage  = $vectorizer->measureCoverage($src, $result['svg']);   // % of artwork drawn

$verdict = ConversionAssessment::assess($deviation, $coverage, $preset, count($result['layers']));
```

Both are **expensive** — together they often cost more than the conversion — so
call them only when you need the number. Coverage is the more important one: it
catches a silently dropped layer, which otherwise produces a plausible-looking SVG.

Rough scale: under 3% is as close as tracing gets, 3–6% is usable but worth a
look, over 6% means tracing is the wrong tool and the job needs the customer's
vector original or a redraw.

## Notes

- **Concurrency**: per-ink traces run 4 at a time. Each slot can be an ImageMagick
  process, so this is also a memory bound. Override with `VECTORIZER_CONCURRENCY`.
- **Gradients and soft shadows** cannot be represented as flat separations. They
  come back as visible bands. Detect and route those to a designer.
- **Vector input** (`.ai`, `.eps`, `.pdf`, `.svg`) is rasterised then re-traced,
  which discards exact geometry it already has. Prefer converting those directly.
