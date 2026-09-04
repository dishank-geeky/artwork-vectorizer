<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class TraceOptions
{
    public const PRESET_LOGO = 'logo';
    public const PRESET_DETAILED = 'detailed';
    public const PRESET_ILLUSTRATION = 'illustration';
    public const PRESET_PHOTO = 'photo';
    public const PRESET_MAX_DETAIL = 'max_detail';

    public const BACKGROUND_SENTINEL = '#FF00FF';

    public function __construct(
        public readonly int $maxColors = 6,
        public readonly float $minAreaPct = PaletteExtractor::DEFAULT_MIN_AREA_PCT,
        public readonly float $mergeDistance = PaletteExtractor::DEFAULT_MERGE_DISTANCE,
        public readonly int $speckleSize = 6,
        public readonly float $cornerSmoothing = 0.4,
        public readonly float $curveTolerance = 0.2,
        public readonly int $precision = 1,
        public readonly bool $removeBackground = true,
        public readonly int $maxWorkingEdge = 2600,
        public readonly int $minWorkingEdge = 900,
        public readonly float $simplify = 1.5,
        public readonly bool $snapToPms = true,
    ) {
    }

    public static function interiorPaletteFor(string $preset): bool
    {
        return !in_array($preset, [
            self::PRESET_ILLUSTRATION,
            self::PRESET_PHOTO,
            self::PRESET_MAX_DETAIL,
        ], true);
    }

    public static function defaultEngineFor(string $preset): string
    {
        return match ($preset) {
            self::PRESET_PHOTO, self::PRESET_ILLUSTRATION, self::PRESET_MAX_DETAIL => 'vtracer',
            default => 'potrace',
        };
    }

    public static function fromPreset(string $preset): self
    {
        return match ($preset) {
            self::PRESET_LOGO => new self(
                maxColors: 6,
                minAreaPct: 1.0,
                mergeDistance: 0.10,
                speckleSize: 6,
                cornerSmoothing: 0.4,
                curveTolerance: 0.2,
            ),
            self::PRESET_DETAILED => new self(
                maxColors: 10,
                minAreaPct: 0.4,
                mergeDistance: 0.07,
                speckleSize: 4,
                cornerSmoothing: 0.4,
                curveTolerance: 0.15,
            ),
            self::PRESET_ILLUSTRATION => new self(
                maxColors: 24,
                minAreaPct: 0.05,
                mergeDistance: 0.03,
                speckleSize: 4,
                cornerSmoothing: 1.0,
                curveTolerance: 0.2,
                precision: 1,
                simplify: 0.3,
            ),
            self::PRESET_PHOTO => new self(
                maxColors: 24,
                minAreaPct: 0.05,
                mergeDistance: 0.03,
                speckleSize: 8,
                cornerSmoothing: 1.334,
                curveTolerance: 0.4,
                precision: 1,
                simplify: 0.3,
            ),
            self::PRESET_MAX_DETAIL => new self(
                maxColors: 48,
                minAreaPct: 0.02,
                mergeDistance: 0.025,
                speckleSize: 1,
                cornerSmoothing: 0.4,
                curveTolerance: 0.1,
                precision: 1,
                maxWorkingEdge: 3600,
                minWorkingEdge: 2200,
                simplify: 0.3,
            ),
            default => new self(),
        };
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            maxColors: (int) ($overrides['maxColors'] ?? $this->maxColors),
            minAreaPct: (float) ($overrides['minAreaPct'] ?? $this->minAreaPct),
            mergeDistance: (float) ($overrides['mergeDistance'] ?? $this->mergeDistance),
            speckleSize: (int) ($overrides['speckleSize'] ?? $this->speckleSize),
            cornerSmoothing: (float) ($overrides['cornerSmoothing'] ?? $this->cornerSmoothing),
            curveTolerance: (float) ($overrides['curveTolerance'] ?? $this->curveTolerance),
            precision: (int) ($overrides['precision'] ?? $this->precision),
            removeBackground: (bool) ($overrides['removeBackground'] ?? $this->removeBackground),
            maxWorkingEdge: (int) ($overrides['maxWorkingEdge'] ?? $this->maxWorkingEdge),
            minWorkingEdge: (int) ($overrides['minWorkingEdge'] ?? $this->minWorkingEdge),
            simplify: (float) ($overrides['simplify'] ?? $this->simplify),
            snapToPms: (bool) ($overrides['snapToPms'] ?? $this->snapToPms),
        );
    }
}
