<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class ArtworkClassifier
{
    private const PHOTO_COVERAGE_24 = 70.0;

    private const FLAT_COVERAGE_8 = 88.0;

    private const LANDED_THRESHOLD_PCT = '2%';

    private const ANALYSIS_EDGE = 700;

    public function __construct(
        private readonly ImageMagick $magick,
        private readonly PaletteExtractor $paletteExtractor,
    ) {
    }

    /**
     * @return array{
     *     preset: string,
     *     engine: string,
     *     detectedInks: int,
     *     suggestedMaxColors: int|null,
     *     coverage8: float,
     *     coverage24: float
     * }
     */
    public function classify(string $normalisedFile, string $workDir): array
    {
        $sample = $this->downscaleForAnalysis($normalisedFile, $workDir);

        $coverage8 = $this->inkCoverage($sample, $workDir, 8);
        $coverage24 = $this->inkCoverage($sample, $workDir, 24);

        $detected = $this->paletteExtractor->analyse(
            $normalisedFile,
            $workDir,
            64,
            PaletteExtractor::DEFAULT_MIN_AREA_PCT,
            PaletteExtractor::DEFAULT_MERGE_DISTANCE,
        )['detected'];

        $preset = match (true) {
            $coverage24 < self::PHOTO_COVERAGE_24 => TraceOptions::PRESET_PHOTO,
            $coverage8 >= self::FLAT_COVERAGE_8 => TraceOptions::PRESET_LOGO,
            default => TraceOptions::PRESET_ILLUSTRATION,
        };

        $suggested = TraceOptions::PRESET_LOGO === $preset
            ? max(2, min(64, $detected))
            : null;

        return [
            'preset' => $preset,
            'engine' => TraceOptions::defaultEngineFor($preset),
            'detectedInks' => $detected,
            'suggestedMaxColors' => $suggested,
            'coverage8' => round($coverage8, 1),
            'coverage24' => round($coverage24, 1),
        ];
    }

    private function downscaleForAnalysis(string $file, string $workDir): string
    {
        $sample = $workDir . '/analysis.png';
        $this->magick->run([
            $file,
            '-resize', sprintf('%dx%d>', self::ANALYSIS_EDGE, self::ANALYSIS_EDGE),
            '-colorspace', 'sRGB', '-depth', '8',
            $sample,
        ]);

        return $sample;
    }

    private function inkCoverage(string $file, string $workDir, int $inks): float
    {
        $scope = sprintf('%s/coverage-%d', $workDir, $inks);
        if (!is_dir($scope) && !mkdir($scope) && !is_dir($scope)) {
            return 0.0;
        }

        $palette = $this->paletteExtractor->extract($file, $scope, $inks, 0.05, 0.03);
        $snapped = $this->paletteExtractor->snapToPalette($file, $palette, $scope);

        $landed = $scope . '/landed.png';
        $this->magick->run([
            $file, $snapped,
            '-compose', 'difference', '-composite',
            '-colorspace', 'Gray',
            '-threshold', self::LANDED_THRESHOLD_PCT,
            '-negate',
            $landed,
        ]);

        return $this->magick->meanLevel($landed) * 100;
    }
}
