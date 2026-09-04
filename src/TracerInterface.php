<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

interface TracerInterface
{
    public const INPUT_SNAPPED = 'snapped';

    public const INPUT_NORMALISED = 'normalised';

    public function name(): string;

    public function isAvailable(): bool;

    /**
     * @return self::INPUT_* which prepared raster this tracer should be handed
     */
    public function inputPreference(): string;

    /**
     * @param list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int},
     *                   pms?: string|null, pmsDelta?: float|null}> $palette
     * @return list<array{hex: string, share: float, d: string, transform: string|null,
     *                     pms?: string|null, pmsDelta?: float|null}>
     */
    public function trace(string $inputFile, array $palette, string $workDir, TraceOptions $options): array;
}
