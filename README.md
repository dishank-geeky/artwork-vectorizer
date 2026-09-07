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

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| **ImageMagick** | **required** — 7 (`magick`) or 6.x (`convert`), both supported |
| potrace | optional, recommended — highest edge accuracy |
| VTracer | optional — fewer path segments, faster on busy artwork |

ImageMagick is the only hard dependency. With no tracer installed the package
falls back to its own pure-PHP tracer, which produces straight segments with
no curve fitting — correct, but not what you want for a logo. Install at least
potrace in production.

```bash
apt-get install imagemagick potrace     # debian / ubuntu
apk add imagemagick potrace             # alpine
brew install imagemagick potrace        # macos
```

Check what a given server actually has:

```php
foreach ($vectorizer->engines() as $name => $engine) {
    printf("%-8s %s\n", $name, $engine['available'] ? 'ready' : 'not installed');
}
```

## Install

```bash
composer require sportsgearswag/artwork-vectorizer
```

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
