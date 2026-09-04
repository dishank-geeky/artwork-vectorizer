<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Symfony\Component\Filesystem\Filesystem;

final class VectorizerService
{
    public function __construct(
        private readonly ImageMagick $magick,
        private readonly PaletteExtractor $paletteExtractor,
        private readonly ArtworkClassifier $classifier,
        private readonly PotraceTracer $potraceTracer,
        private readonly VtracerTracer $vtracerTracer,
        private readonly PhpTracer $phpTracer,
        private readonly SvgAssembler $assembler,
        private readonly PmsPalette $pmsPalette,
        private readonly Filesystem $filesystem,
        private readonly string $tmpDir = '/tmp',
    ) {
    }

    /**
     * @return array{preset: string, engine: string, detectedInks: int,
     *               suggestedMaxColors: int|null, coverage8: float, coverage24: float}
     */
    public function inspect(string $sourceFile): array
    {
        $workDir = $this->makeWorkDir();

        try {
            $normalised = $this->normalise($sourceFile, $workDir, new TraceOptions());

            return $this->classifier->classify($normalised, $workDir);
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * @return array<string, array{available: bool, label: string}>
     */
    public function engines(): array
    {
        return [
            'potrace' => [
                'available' => $this->potraceTracer->isAvailable(),
                'label' => 'Potrace — highest edge accuracy (recommended for logos)',
            ],
            'vtracer' => [
                'available' => $this->vtracerTracer->isAvailable(),
                'label' => 'VTracer — fewer path segments, faster (better for busy artwork)',
            ],
            'php' => [
                'available' => true,
                'label' => 'Built-in (no external tracer) — straight segments, no curve fitting',
            ],
        ];
    }

    /**
     * @return array{
     *     svg: string,
     *     layers: list<array{hex: string, share: float, subpaths: int}>,
     *     palette: list<array{hex: string, share: float}>,
     *     detectedInks: int,
     *     paletteTruncated: bool,
     *     width: int,
     *     height: int,
     *     bytes: int,
     *     subpaths: int,
     *     engine: string,
     *     compliance: list<string>,
     *     seconds: float
     * }
     */
    public function convert(
        string $sourceFile,
        TraceOptions $options,
        string $engine = 'potrace',
        string $preset = TraceOptions::PRESET_LOGO,
    ): array {
        $startedAt = microtime(true);
        $workDir = $this->makeWorkDir();

        try {
            $normalised = $this->normalise($sourceFile, $workDir, $options);
            [$width, $height] = $this->magick->dimensions($normalised);

            $analysis = $this->paletteExtractor->analyse(
                $normalised,
                $workDir,
                $options->maxColors,
                $options->minAreaPct,
                $options->mergeDistance,
                TraceOptions::interiorPaletteFor($preset),
            );
            $palette = $analysis['inks'];
            if ($options->snapToPms) {
                $palette = $this->snapPaletteToPms($palette);
            }

            $tracer = $this->tracer($engine);
            $traceSource = $this->prepareTraceInput($tracer, $normalised, $palette, $workDir, $options);
            $layers = $tracer->trace($traceSource, $palette, $workDir, $options);

            if ([] === $layers) {
                throw new \RuntimeException(sprintf(
                    'The %s engine produced no layers from this artwork. It may be blank, or the '
                    . 'engine may have failed - check the server logs.',
                    $tracer->name()
                ));
            }

            $svg = $this->assembler->assemble($layers, $width, $height);

            return [
                'svg' => $svg,
                'layers' => array_map(
                    static fn (array $l): array => [
                        'hex' => $l['hex'],
                        'share' => $l['share'],
                        'pms' => $l['pms'] ?? null,
                        'pmsDelta' => $l['pmsDelta'] ?? null,
                        'subpaths' => preg_match_all('/[Mm]/', $l['d']) ?: 0,
                    ],
                    $layers,
                ),
                'palette' => array_map(
                    static fn (array $p): array => ['hex' => $p['hex'], 'share' => $p['share']],
                    $palette,
                ),
                'detectedInks' => $analysis['detected'],
                'paletteTruncated' => $analysis['truncated'],
                'width' => $width,
                'height' => $height,
                'bytes' => strlen($svg),
                'subpaths' => preg_match_all('/[Mm]/', implode('', array_column($layers, 'd'))) ?: 0,
                'engine' => $tracer->name(),
                'compliance' => SvgAssembler::complianceIssues($svg),
                'seconds' => round(microtime(true) - $startedAt, 2),
            ];
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * @return float|null percentage difference, or null if comparison is unavailable
     */
    public function measureDeviation(string $sourceFile, string $svg, bool $backgroundRemoved = true): ?float
    {
        $workDir = $this->makeWorkDir();

        try {
            $svgFile = $workDir . '/out.svg';
            file_put_contents($svgFile, $svg);

            [$w, $h] = $this->magick->dimensions($sourceFile);
            $rendered = $workDir . '/out.png';
            $reference = $workDir . '/ref.png';

            $this->magick->run([
                '-background', 'white', '-density', '384', $svgFile,
                '-flatten', '-resize', sprintf('%dx%d!', $w, $h), $rendered,
            ]);
            $this->magick->run([
                $sourceFile, '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
                '-resize', sprintf('%dx%d!', $w, $h), $reference,
            ]);

            if ($backgroundRemoved) {
                $corner = $this->magick->pixelAt($reference, 0, 0);
                $this->magick->run([
                    $reference,
                    '-bordercolor', $corner, '-border', '1',
                    '-fill', 'white', '-draw', 'color 0,0 floodfill',
                    '-shave', '1x1', $reference,
                ]);
            }

            $compare = new \Symfony\Component\Process\Process([
                'compare', '-metric', 'RMSE', $reference, $rendered, 'null:',
            ]);
            $compare->run();

            if (preg_match('/\(([\d.]+)\)/', $compare->getErrorOutput(), $m)) {
                return round((float) $m[1] * 100, 2);
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * @param list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int}}> $palette
     */
    private function prepareTraceInput(
        TracerInterface $tracer,
        string $normalised,
        array $palette,
        string $workDir,
        TraceOptions $options,
    ): string {
        if (TracerInterface::INPUT_NORMALISED === $tracer->inputPreference()) {
            return $normalised;
        }

        $snapped = $this->paletteExtractor->snapToPalette($normalised, $palette, $workDir);

        return $options->removeBackground
            ? $this->removeOuterBackground($snapped, $workDir)
            : $snapped;
    }

    public function measureCoverage(string $sourceFile, string $svg): ?float
    {
        $workDir = $this->makeWorkDir();

        try {
            $svgFile = $workDir . '/out.svg';
            file_put_contents($svgFile, $svg);

            [$w, $h] = $this->magick->dimensions($sourceFile);
            $size = sprintf('%dx%d!', $w, $h);

            $flattened = $workDir . '/src.png';
            $this->magick->run([
                $sourceFile . '[0]', '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
                '-resize', $size, $flattened,
            ]);

            $subjectMask = $workDir . '/subject.png';
            $corner = $this->magick->pixelAt($flattened, 0, 0);
            $this->magick->run([
                $flattened,
                '-bordercolor', $corner, '-border', '1',
                '-fill', TraceOptions::BACKGROUND_SENTINEL, '-draw', 'color 0,0 floodfill',
                '-shave', '1x1',
                '-fill', 'white', '-opaque', TraceOptions::BACKGROUND_SENTINEL,
                '-fill', 'black', '+opaque', 'white',
                '-colorspace', 'Gray', '-threshold', '50%', '-negate',
                $subjectMask,
            ]);
            $subject = $this->magick->meanLevel($subjectMask);

            if ($subject <= 0.0) {
                return null;
            }

            $painted = $workDir . '/painted.png';
            $this->magick->run([
                '-background', 'none', '-density', '200', $svgFile,
                '-resize', $size, '-alpha', 'extract', '-threshold', '25%', $painted,
            ]);

            $overlap = $workDir . '/overlap.png';
            $this->magick->run([$painted, $subjectMask, '-compose', 'multiply', '-composite', $overlap]);

            return min(100.0, round($this->magick->meanLevel($overlap) / $subject * 100, 1));
        } catch (\Throwable) {
            return null;
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * @param list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int}}> $palette
     * @return list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int},
     *                    pms?: string, pmsDelta?: float}>
     */
    private function snapPaletteToPms(array $palette): array
    {
        if ($this->pmsPalette->isEmpty()) {
            return $palette;
        }

        $merged = [];
        foreach ($palette as $ink) {
            $match = $this->pmsPalette->nearest($ink['rgb']);
            if (null === $match) {
                $merged[$ink['hex']] = $ink;
                continue;
            }

            $hex = $match['hex'];
            if (isset($merged[$hex])) {
                $merged[$hex]['share'] = round($merged[$hex]['share'] + $ink['share'], 2);
                continue;
            }

            $merged[$hex] = [
                'hex' => $hex,
                'share' => $ink['share'],
                'rgb' => $match['rgb'],
                'pms' => $match['code'],
                'pmsDelta' => $match['delta'],
            ];
        }

        $out = array_values($merged);
        usort($out, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        return $out;
    }

    /**
     * @throws \RuntimeException when no tracing engine is installed
     */
    private function tracer(string $engine): TracerInterface
    {
        if (!$this->potraceTracer->isAvailable() && !$this->vtracerTracer->isAvailable()) {
            return $this->phpTracer;
        }

        return match ($engine) {
            'php' => $this->phpTracer,
            'vtracer' => $this->vtracerTracer->isAvailable() ? $this->vtracerTracer : $this->potraceTracer,
            default => $this->potraceTracer->isAvailable() ? $this->potraceTracer : $this->vtracerTracer,
        };
    }

    private function normalise(string $source, string $workDir, TraceOptions $options): string
    {
        $normalised = $workDir . '/normalised.png';
        [$w, $h] = $this->magick->dimensions($source);
        $longEdge = max($w, $h);

        $floor = min($options->minWorkingEdge, $options->maxWorkingEdge);

        $resize = [];
        if ($longEdge > $options->maxWorkingEdge) {
            $resize = ['-filter', 'Lanczos', '-resize', sprintf('%dx%d', $options->maxWorkingEdge, $options->maxWorkingEdge)];
        } elseif ($longEdge > 0 && $longEdge < $floor) {
            $factor = $floor / $longEdge;
            $resize = ['-filter', 'Lanczos', '-resize', sprintf('%dx%d', (int) round($w * $factor), (int) round($h * $factor))];
        }

        $this->magick->run([
            $source . '[0]',
            '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
            ...$resize,
            '-colorspace', 'sRGB', '-depth', '8',
            $normalised,
        ]);

        return $normalised;
    }

    private function removeOuterBackground(string $snapped, string $workDir): string
    {
        $out = $workDir . '/no-background.png';
        $corner = $this->magick->pixelAt($snapped, 0, 0);

        $this->magick->run([
            $snapped,
            '-bordercolor', $corner, '-border', '1',
            '-fill', TraceOptions::BACKGROUND_SENTINEL, '-draw', 'color 0,0 floodfill',
            '-shave', '1x1',
            '-colorspace', 'sRGB', '-type', 'TrueColor', '-depth', '8',
            '-define', 'png:color-type=2',
            $out,
        ]);

        return $out;
    }

    private function makeWorkDir(): string
    {
        $dir = sprintf('%s/sgs-vectorizer-%s', rtrim($this->tmpDir, '/'), bin2hex(random_bytes(6)));
        $this->filesystem->mkdir($dir);

        return $dir;
    }
}
