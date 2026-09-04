<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class PaletteExtractor
{
    public const DEFAULT_MIN_AREA_PCT = 1.0;

    public const DEFAULT_MERGE_DISTANCE = 0.10;

    private const DISTINCT_DISTANCE = 0.03;

    private const RESCUE_MIN_AREA_PCT = 0.1;

    private const EDGE_SENTINEL = '#00FF00';

    private const MIN_INTERIOR_SHARE = 0.20;

    private const OVER_QUANTISE_FACTOR = 6;
    private const OVER_QUANTISE_MIN = 48;
    private const OVER_QUANTISE_MAX = 256;

    public function __construct(private readonly ImageMagick $magick)
    {
    }

    /**
     * @return list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int}}>
     */
    public function extract(
        string $file,
        string $workDir,
        int $maxColors,
        float $minAreaPct = self::DEFAULT_MIN_AREA_PCT,
        float $mergeDistance = self::DEFAULT_MERGE_DISTANCE,
        bool $interiorOnly = true,
    ): array {
        return $this->analyse($file, $workDir, $maxColors, $minAreaPct, $mergeDistance, $interiorOnly)['inks'];
    }

    /**
     * @return array{
     *     inks: list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int}}>,
     *     detected: int,
     *     truncated: bool
     * }
     */
    public function analyse(
        string $file,
        string $workDir,
        int $maxColors,
        float $minAreaPct = self::DEFAULT_MIN_AREA_PCT,
        float $mergeDistance = self::DEFAULT_MERGE_DISTANCE,
        bool $interiorOnly = true,
    ): array {
        $quantised = $workDir . '/over.png';

        $overQuantise = min(
            self::OVER_QUANTISE_MAX,
            max(self::OVER_QUANTISE_MIN, $maxColors * self::OVER_QUANTISE_FACTOR),
        );

        $this->magick->run([
            $file,
            '-colorspace', 'LAB',
            '-dither', 'None',
            '-colors', (string) $overQuantise,
            '-colorspace', 'sRGB',
            '-type', 'TrueColor', '-define', 'png:color-type=2',
            '-depth', '8',
            $quantised,
        ]);

        $histogram = $interiorOnly
            ? $this->interiorHistogram($quantised, $workDir)
            : $this->magick->histogram($quantised);
        $total = array_sum(array_column($histogram, 'count')) ?: 1;

        $clusters = [];
        foreach ($histogram as $entry) {
            $merged = false;
            foreach ($clusters as $i => $cluster) {
                if (Oklab::distance($cluster['rgb'], $entry['rgb']) < $mergeDistance) {
                    $clusters[$i]['count'] += $entry['count'];
                    $merged = true;
                    break;
                }
            }
            if (!$merged) {
                $clusters[] = ['count' => $entry['count'], 'rgb' => $entry['rgb'], 'hex' => $entry['hex']];
            }
        }

        $significant = self::filterSignificant($clusters, $total, $minAreaPct);

        if ([] === $significant) {
            $significant = array_slice($clusters, 0, 1);
        }

        $detected = count($significant);
        $capped = self::selectRepresentative($significant, max(1, $maxColors));

        return [
            'inks' => array_map(
                static fn (array $c): array => [
                    'hex' => $c['hex'],
                    'share' => round($c['count'] / $total * 100, 2),
                    'rgb' => $c['rgb'],
                ],
                $capped,
            ),
            'detected' => $detected,
            'truncated' => $detected > count($capped),
        ];
    }

    /**
     * @return list<array{count: int, rgb: array{0: int, 1: int, 2: int}, hex: string}>
     */
    private function interiorHistogram(string $quantised, string $workDir): array
    {
        $full = $this->magick->histogram($quantised);

        try {
            $edges = $workDir . '/edges.png';
            $this->magick->run([
                $quantised, '-colorspace', 'Gray',
                '(', '+clone', '-statistic', 'maximum', '3x3', ')',
                '(', '-clone', '0', '-statistic', 'minimum', '3x3', ')',
                '-delete', '0', '-compose', 'difference', '-composite',
                '-threshold', '1%',
                $edges,
            ]);

            $interior = $workDir . '/interior.png';
            $this->magick->run([
                $quantised,
                '(', '-clone', '0', '-fill', self::EDGE_SENTINEL, '-colorize', '100', ')',
                $edges, '-composite',
                '-type', 'TrueColor', '-define', 'png:color-type=2', '-depth', '8',
                $interior,
            ]);

            $rows = array_values(array_filter(
                $this->magick->histogram($interior),
                static fn (array $r): bool => self::EDGE_SENTINEL !== $r['hex'],
            ));

            $kept = array_sum(array_column($rows, 'count'));
            $all = array_sum(array_column($full, 'count')) ?: 1;

            return ($rows !== [] && $kept / $all >= self::MIN_INTERIOR_SHARE) ? $rows : $full;
        } catch (\Throwable) {
            return $full;
        }
    }

    /**
     * @param list<array{count: int, rgb: array{0: int, 1: int, 2: int}, hex: string}> $clusters
     * @return list<array{count: int, rgb: array{0: int, 1: int, 2: int}, hex: string}>
     */
    private static function filterSignificant(array $clusters, int $total, float $minAreaPct): array
    {
        $kept = [];
        $larger = [];

        foreach ($clusters as $cluster) {
            $share = $cluster['count'] / $total * 100;

            if ($share >= $minAreaPct) {
                $kept[] = $cluster;
                $larger[] = $cluster['rgb'];
                continue;
            }

            if ($share >= self::RESCUE_MIN_AREA_PCT
                && self::blendDistance($cluster['rgb'], $larger) > self::DISTINCT_DISTANCE) {
                $kept[] = $cluster;
            }
        }

        return $kept;
    }

    /**
     * @param array{0: int, 1: int, 2: int}      $rgb
     * @param list<array{0: int, 1: int, 2: int}> $inks
     */
    private static function blendDistance(array $rgb, array $inks): float
    {
        $count = count($inks);
        if ($count < 2) {
            return \INF;
        }

        $best = \INF;
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $best = min($best, Oklab::distanceToBlend($rgb, $inks[$i], $inks[$j]));
                if ($best <= self::DISTINCT_DISTANCE) {
                    return $best;
                }
            }
        }

        return $best;
    }

    /**
     * @param list<array{count: int, rgb: array{0: int, 1: int, 2: int}, hex: string}> $clusters
     * @return list<array{count: int, rgb: array{0: int, 1: int, 2: int}, hex: string}>
     */
    private static function selectRepresentative(array $clusters, int $limit): array
    {
        if (count($clusters) <= $limit) {
            return $clusters;
        }

        $selected = [0];
        $remaining = array_keys($clusters);
        array_shift($remaining);

        while (count($selected) < $limit && [] !== $remaining) {
            $bestIndex = null;
            $bestGain = -1.0;

            foreach ($remaining as $candidate) {
                $nearest = \PHP_FLOAT_MAX;
                foreach ($selected as $chosen) {
                    $nearest = min($nearest, Oklab::distance($clusters[$candidate]['rgb'], $clusters[$chosen]['rgb']));
                }

                $gain = $clusters[$candidate]['count'] * $nearest;
                if ($gain > $bestGain) {
                    $bestGain = $gain;
                    $bestIndex = $candidate;
                }
            }

            if (null === $bestIndex) {
                break;
            }

            $selected[] = $bestIndex;
            $remaining = array_values(array_diff($remaining, [$bestIndex]));
        }

        sort($selected);

        return array_values(array_map(static fn (int $i): array => $clusters[$i], $selected));
    }

    /**
     * @param list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int}}> $palette
     */
    public function snapToPalette(string $file, array $palette, string $workDir): string
    {
        $swatch = $workDir . '/palette.png';
        $args = [];
        foreach ($palette as $colour) {
            $args = [...$args, '(', '-size', '1x1', 'xc:' . $colour['hex'], ')'];
        }

        $this->magick->run([
            ...$args, '+append',
            '-colorspace', 'sRGB', '-type', 'TrueColor', '-depth', '8',
            '-define', 'png:color-type=2',
            $swatch,
        ]);

        $snapped = $workDir . '/snapped.png';
        $this->magick->run([
            $file, '-dither', 'None', '-remap', $swatch,
            '-colorspace', 'sRGB', '-type', 'TrueColor', '-depth', '8',
            '-define', 'png:color-type=2',
            $snapped,
        ]);

        return $snapped;
    }
}
