<?php
// Usage: php examples/convert.php /path/to/artwork.png [/path/to/vtracer]
require dirname(__DIR__) . '/vendor/autoload.php';

use Sgs\Vectorizer\{VectorizerService, PaletteExtractor, ArtworkClassifier, PotraceTracer,
    VtracerTracer, PhpTracer, SvgAssembler, ImageMagick, PathTransformer, TraceOptions, PmsPalette};
use Sgs\Vectorizer\Palette\JsonPaletteProvider;
use Symfony\Component\Filesystem\Filesystem;

$vtracer = $argv[2] ?? 'vtracer';
$magick  = new ImageMagick('magick');
$palette = new PaletteExtractor($magick);
$paths   = new PathTransformer();

$svc = new VectorizerService(
    $magick, $palette, new ArtworkClassifier($magick, $palette),
    new PotraceTracer($magick, $paths, 'potrace'),
    new VtracerTracer($vtracer),
    new PhpTracer($magick),
    new SvgAssembler(),
    new PmsPalette(new JsonPaletteProvider()),   // bundled deck, no host config
    new Filesystem(),
    sys_get_temp_dir()
);

$src = $argv[1];
$detected = $svc->inspect($src);
$options  = TraceOptions::fromPreset($detected['preset']);
if ($detected['suggestedMaxColors']) {
    $options = $options->with(['maxColors' => $detected['suggestedMaxColors']]);
}

$t = microtime(true);
$r = $svc->convert($src, $options, $detected['engine'], $detected['preset']);
printf("preset=%s engine=%s  %d inks, %d shapes, %.1f KB, %.1fs\n",
    $detected['preset'], $r['engine'], count($r['layers']), $r['subpaths'],
    $r['bytes'] / 1024, microtime(true) - $t);
foreach ($r['layers'] as $l) {
    printf("   %s  %6.2f%%  %s\n", $l['hex'], $l['share'], $l['pms'] ?? '(no PMS)');
}
echo $r['compliance'] ? "COMPLIANCE: " . implode('; ', $r['compliance']) . "\n" : "SGS format: OK\n";
$out = getcwd() . '/' . pathinfo($src, PATHINFO_FILENAME) . '.svg';
file_put_contents($out, $r['svg']);
echo "wrote $out
";
