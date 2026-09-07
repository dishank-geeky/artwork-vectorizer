# Artwork Vectorizer

Turns raster artwork into a colour-separated, print-ready SVG: one editable
`<g>` per ink, plain hex fills, every colour snapped to its nearest Pantone
Coated match. Built for screen printing, where the number of separations is
the thing that costs money.

```
input:  a 900x400 PNG logo, 403 unique colours after anti-aliasing
output: 4 layers -> WHITE, PMS 186 C, PMS 282 C, PMS 1235 C
        15 subpaths, 3.9 KB, 1.4s, 1.6% off the original
```

## Quick start

Two commands and one line, in any Symfony project:

```bash
composer require sportsgearswag/artwork-vectorizer
apt-get install -y imagemagick potrace      # plus VTracer — see Requirements
```

```php
// config/bundles.php
Sgs\Vectorizer\ArtworkVectorizerBundle::class => ['all' => true],
```

Then inject it and go — no service wiring, no parameters, no configuration:

```php
public function __construct(private readonly VectorizerService $vectorizer) {}

$result = $this->vectorizer->convert($file, TraceOptions::fromPreset('logo'));
// $result['svg'], $result['layers'], ...
```

## Requirements

All three are **required** for production. The package technically runs on
ImageMagick alone, but only by falling back to a pure-PHP tracer that emits
straight segments with no curve fitting — correct, and wrong for a logo.

| | |
|---|---|
| PHP | 8.2+ |
| Symfony | 6.4, 7.x or 8.x (any, or none — the library works standalone) |
| **ImageMagick** | colour separation. 7 (`magick`) or 6.x (`convert`), both supported |
| **potrace** | highest edge accuracy — logos, text, hard edges |
| **VTracer** | fewer path segments, faster — busy and shaded artwork |

Install both tracers, not one. They are not interchangeable, and the `auto`
preset picks between them per artwork; with only one installed every job goes
through it whether it suits or not. On the same flat four-ink logo:

| engine | subpaths | size | time | difference from original |
|---|---|---|---|---|
| potrace | 15 | 3.8 KB | 0.75s | **1.61%** |
| VTracer | 14 | **2.6 KB** | **0.65s** | 2.69% |

potrace wins on fidelity, VTracer on file size and speed — and the gap widens
the other way on busy artwork, where potrace emits far more segments.

### ImageMagick and potrace

```bash
apt-get update && apt-get install -y imagemagick potrace   # debian / ubuntu
apk add --no-cache imagemagick potrace                     # alpine
brew install imagemagick potrace                           # macos
```

### VTracer

VTracer is a Rust binary and is **not in apt, apk or brew** — install the
static release binary and put it on `PATH`:

```bash
# linux x86_64
curl -sL https://github.com/visioncortex/vtracer/releases/latest/download/vtracer-x86_64-unknown-linux-musl.tar.gz \
  | tar xz -C /usr/local/bin vtracer

# linux arm64
curl -sL https://github.com/visioncortex/vtracer/releases/latest/download/vtracer-aarch64-unknown-linux-musl.tar.gz \
  | tar xz -C /usr/local/bin vtracer

# macos apple silicon  (use x86_64-apple-darwin on intel)
curl -sL https://github.com/visioncortex/vtracer/releases/latest/download/vtracer-aarch64-apple-darwin.tar.gz \
  | tar xz -C /usr/local/bin vtracer
```

The musl builds are static, so they need no runtime libraries and work on
Alpine as well as glibc distros. `cargo install vtracer` also works if Rust is
already available.

Both binaries must be on `PATH` on **every** machine that runs a conversion —
each developer's machine, CI, and every deployed image. This is the step teams
miss: it works locally and returns
`No tracing engine is installed on this server` in production.

Check what a given server actually has:

```php
foreach ($vectorizer->engines() as $name => $engine) {
    printf("%-8s %s\n", $name, $engine['available'] ? 'ready' : 'not installed');
}
```

## Install

Published on [Packagist](https://packagist.org/packages/sportsgearswag/artwork-vectorizer),
so there is no `repositories` block and no token to configure:

```bash
composer require sportsgearswag/artwork-vectorizer
```

Use `^1.1` or newer if you pin a constraint. The bundle does not exist in 1.0,
so a fresh install of 1.0.0 fails at container compile with no obvious cause.
Symfony 8 needs `^1.1.1`; earlier versions cap Symfony at 7.

### Symfony

Register the bundle (Flex does this for you):

```php
// config/bundles.php
return [
    // ...
    Sgs\Vectorizer\ArtworkVectorizerBundle::class => ['all' => true],
];
```

That is the whole integration — every service is wired for you. Inject
`VectorizerService` anywhere:

```php
use Sgs\Vectorizer\TraceOptions;
use Sgs\Vectorizer\VectorizerService;

public function __construct(private readonly VectorizerService $vectorizer) {}

public function convert(string $uploadedFile): array
{
    $detection = $this->vectorizer->inspect($uploadedFile);
    $options   = TraceOptions::fromPreset($detection['preset']);

    return $this->vectorizer->convert($uploadedFile, $options);
}
```

Configuration is optional — these are the defaults:

```yaml
# config/packages/artwork_vectorizer.yaml
artwork_vectorizer:
    tmp_dir: '%kernel.project_dir%/var/tmp'   # wiped after every conversion
    palette: json                             # bundled Pantone Coated deck
    palette_file: ~                           # or point at your own json
    binaries:
        magick: magick                        # absolute paths are fine
        potrace: potrace
        vtracer: vtracer
    timeouts:
        magick: 120
        trace: 180                            # raise for very large artwork
```

To feed inks from your own source — a database table, an API — implement
`PaletteProviderInterface` and name your service:

```yaml
artwork_vectorizer:
    palette: App\Vectorizer\DoctrinePmsProvider
```

### Without Symfony

Construct it by hand; see [`examples/convert.php`](examples/convert.php).

## Handing this to another team

It is on Packagist and the repository is public, so there is nothing to grant
and nothing to configure. Send them these three steps.

**1. Require it:**

```bash
composer require sportsgearswag/artwork-vectorizer
```

**2. Register the bundle** — one line, and then every service is wired:

```php
// config/bundles.php
Sgs\Vectorizer\ArtworkVectorizerBundle::class => ['all' => true],
```

**3. Install the binaries on every machine that runs it** — their local
machines, CI, and each deployed image. This is the step teams forget, and the
failure mode is a conversion that "works locally" and returns
`No tracing engine is installed on this server` in production. See
[Requirements](#requirements); the short version is ImageMagick is mandatory
and both tracers are required — note VTracer is not in apt/apk/brew and needs
its release binary.

**4. Confirm the licence with the copyright holder.** This package is
proprietary (see [LICENSE](LICENSE)) — being able to clone a public repository
is not the same as being licensed to use it. The owner saying yes is all that
is needed, but get it in writing.

### What they do *not* need

- Any access token, deploy key or `auth.json` — the repository is public
- A `repositories` block in `composer.json` — it resolves from Packagist
- A Packagist account
- Any service configuration — the defaults work; see [Symfony](#symfony)
- To copy any service definitions. If they find themselves writing
  `Sgs\Vectorizer\...` entries in `services.yaml`, they have missed step 2.

### Worked example

`sgs-designer` is the reference integration. In full it is:

| File | Change |
|---|---|
| `composer.json` | one `require` line |
| `config/bundles.php` | one line |
| `src/Controller/.../ArtworkVectorizerController.php` | inject `VectorizerService`, one upload route, one convert route returning JSON |
| `templates/.../index.html.twig` | the upload form and the result view |

No service wiring, no parameters, no compiler passes.

### What `convert()` returns

The whole contract for building a UI on top of it:

```php
[
    'svg'              => '<svg ...>',   // the finished markup
    'layers'           => [              // one entry per ink, largest area first
        ['hex' => '#C8102E', 'share' => 12.7, 'pms' => 'PMS 186 C', 'pmsDelta' => 0.0, 'subpaths' => 2],
    ],
    'palette'          => [['hex' => '#C8102E', 'share' => 12.7]],
    'detectedInks'     => 4,      // found before the max-colour cap was applied
    'paletteTruncated' => false,  // true when detectedInks exceeded the cap
    'width'            => 900,
    'height'           => 400,
    'bytes'            => 3930,
    'subpaths'         => 15,
    'engine'           => 'potrace',  // which one actually ran, after fallback
    'compliance'       => [],         // non-empty means the SVG broke a format rule
    'seconds'          => 1.55,
]
```

Two of those are easy to miss and worth surfacing in any UI. `engine` is what
*actually* ran — ask for `vtracer` on a server without it and this comes back
`potrace`, silently. And `paletteTruncated` tells you the artwork had more inks
than the preset allowed, which is the honest reason a result looks flat.

## Presets

Pick by artwork type. `inspect()` will choose for you.

| Preset | Max inks | For |
|---|---|---|
| `logo` | 6 | Spot-colour logos. Fewest layers, exact brand colours. |
| `detailed` | 10 | Keeps small accents and thin outlines. |
| `illustration` | 24 | Mascots, sticker and tee art. |
| `photo` | 24 | Posterised likeness. Not print-ready. |
| `max_detail` | 48 | Complex artwork. Slow, and the SVG gets large. |

## Output

```xml
<svg xmlns="http://www.w3.org/2000/svg" width="900" height="400" viewBox="0 0 900 400" fill="none">
  <g id="color-1" data-color="#C8102E" data-area="12.7" data-pms="PMS 186 C" data-pms-delta="0" fill="#C8102E">
    <path d="..."/>
  </g>
</svg>
```

Paths with plain hex fills only — no gradients, filters, clip paths or embedded
raster — so it loads into any design editor. `SvgAssembler::complianceIssues()`
asserts that.

## Measuring quality

`convert()` is cheap. The two checks below re-render the SVG and compare it to
the source, which costs 20-30 seconds, so call them only when you want the
number:

```php
$deviation = $vectorizer->measureDeviation($file, $result['svg'], true);   // % off the original
$coverage  = $vectorizer->measureCoverage($file, $result['svg']);          // % of ink area drawn

$verdict = ConversionAssessment::assess($deviation, $coverage, $preset, count($result['layers']));
// ['verdict' => 'good', 'headline' => 'Good — 1.61% off the original across 4 layers', 'detail' => '...']
```

A flat logo should land under ~3%. Gradients and photographs will not: they
have no exact answer in flat inks, and `assess()` returns `escalate` rather
than pretending otherwise.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite converts a real four-ink fixture and asserts the ink count, the
Pantone matches, SVG compliance and a deviation ceiling. It skips if
ImageMagick is missing, so CI runs `--fail-on-skipped`.

## Licence

Proprietary — see [LICENSE](LICENSE), which also carries the Pantone
trademark notice and the licences of the external tools this package invokes.
