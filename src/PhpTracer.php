<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class PhpTracer implements TracerInterface
{
    private const MAX_EDGE = 1400;

    private const SIMPLIFY_TOLERANCE = 0.75;

    public function __construct(private readonly ImageMagick $magick)
    {
    }

    public function name(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function inputPreference(): string
    {
        return self::INPUT_SNAPPED;
    }

    public function trace(string $inputFile, array $palette, string $workDir, TraceOptions $options): array
    {
        [$width, $height] = $this->magick->dimensions($inputFile);
        $scale = 1.0;
        if (max($width, $height) > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / max($width, $height);
        }

        $layers = [];

        foreach ($palette as $index => $colour) {
            $mask = $this->maskBytes($inputFile, $colour['hex'], $workDir, $index, $scale);
            if (null === $mask) {
                continue;
            }

            [$bytes, $mw, $mh] = $mask;
            $rings = $this->traceRings($bytes, $mw, $mh, max(1, $options->speckleSize));
            if ([] === $rings) {
                continue;
            }

            $d = $this->toPath($rings, 1.0 / $scale, $options->precision);
            if ('' === $d) {
                continue;
            }

            $layers[] = [
                'hex' => $colour['hex'],
                'share' => $colour['share'],
                'pms' => $colour['pms'] ?? null,
                'pmsDelta' => $colour['pmsDelta'] ?? null,
                'd' => $d,
                'transform' => null,
            ];
        }

        return $layers;
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function maskBytes(string $file, string $hex, string $workDir, int $index, float $scale): ?array
    {
        $png = sprintf('%s/php-mask-%d.png', $workDir, $index);

        $resize = 1.0 === $scale
            ? []
            : ['-resize', sprintf('%d%%', max(1, (int) round($scale * 100)))];

        $this->magick->run([
            $file,
            '-alpha', 'off',
            '-fuzz', '0%',
            '-transparent', $hex,
            '-alpha', 'extract',
            ...$resize,
            '-threshold', '50%',
            '-depth', '8',
            $png,
        ]);

        [$w, $h] = $this->magick->dimensions($png);
        if ($w < 1 || $h < 1) {
            return null;
        }

        $raw = $this->magick->runBinary([$png, '-depth', '8', 'gray:-']);
        if (strlen($raw) < $w * $h) {
            return null;
        }

        return [$raw, $w, $h];
    }

    /**
     * @return list<list<array{0: int, 1: int}>>
     */
    private function traceRings(string $bytes, int $w, int $h, int $minArea): array
    {
        $out = [];

        $ink = static fn (int $x, int $y): bool =>
            $x >= 0 && $y >= 0 && $x < $w && $y < $h && "\x00" === $bytes[$y * $w + $x];

        for ($y = 0; $y < $h; ++$y) {
            $row = $y * $w;
            for ($x = 0; $x < $w; ++$x) {
                if ("\x00" !== $bytes[$row + $x]) {
                    continue;
                }
                $stride = $w + 1;
                if (!$ink($x, $y - 1)) {
                    $out[$y * $stride + $x][] = $y * $stride + $x + 1;
                }
                if (!$ink($x + 1, $y)) {
                    $out[$y * $stride + $x + 1][] = ($y + 1) * $stride + $x + 1;
                }
                if (!$ink($x, $y + 1)) {
                    $out[($y + 1) * $stride + $x + 1][] = ($y + 1) * $stride + $x;
                }
                if (!$ink($x - 1, $y)) {
                    $out[($y + 1) * $stride + $x][] = $y * $stride + $x;
                }
            }
        }

        $stride = $w + 1;
        $rings = [];

        foreach (array_keys($out) as $start) {
            while (isset($out[$start])) {
                $ring = [];
                $current = $start;

                while (true) {
                    if (!isset($out[$current])) {
                        break;
                    }
                    $next = (int) array_pop($out[$current]);
                    if ([] === $out[$current]) {
                        unset($out[$current]);
                    }
                    $ring[] = [$current % $stride, intdiv($current, $stride)];
                    $current = $next;
                    if ($current === $start) {
                        break;
                    }
                }

                if (count($ring) < 4) {
                    continue;
                }
                if (abs($this->area($ring)) < $minArea) {
                    continue;
                }

                $rings[] = $this->simplify($ring, self::SIMPLIFY_TOLERANCE);
            }
        }

        return $rings;
    }

    /**
     * @param list<array{0: int|float, 1: int|float}> $ring
     */
    private function area(array $ring): float
    {
        $sum = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n; ++$i) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[($i + 1) % $n];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return $sum / 2.0;
    }

    /**
     * @param list<array{0: int, 1: int}> $ring
     * @return list<array{0: int, 1: int}>
     */
    private function simplify(array $ring, float $tolerance): array
    {
        $n = count($ring);
        if ($n < 4) {
            return $ring;
        }

        $farthest = 0;
        $best = -1.0;
        for ($i = 1; $i < $n; ++$i) {
            $dx = $ring[$i][0] - $ring[0][0];
            $dy = $ring[$i][1] - $ring[0][1];
            $d = $dx * $dx + $dy * $dy;
            if ($d > $best) {
                $best = $d;
                $farthest = $i;
            }
        }

        $first = $this->rdp(array_slice($ring, 0, $farthest + 1), $tolerance);
        $second = $this->rdp([...array_slice($ring, $farthest), $ring[0]], $tolerance);

        array_pop($first);
        array_pop($second);

        return [...$first, ...$second];
    }

    /**
     * @param list<array{0: int, 1: int}> $points
     * @return list<array{0: int, 1: int}>
     */
    private function rdp(array $points, float $tolerance): array
    {
        $n = count($points);
        if ($n < 3) {
            return $points;
        }

        [$ax, $ay] = $points[0];
        [$bx, $by] = $points[$n - 1];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len = sqrt($dx * $dx + $dy * $dy);

        $index = 0;
        $max = -1.0;
        for ($i = 1; $i < $n - 1; ++$i) {
            [$px, $py] = $points[$i];
            $distance = $len > 0.0
                ? abs($dy * ($px - $ax) - $dx * ($py - $ay)) / $len
                : sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);
            if ($distance > $max) {
                $max = $distance;
                $index = $i;
            }
        }

        if ($max <= $tolerance) {
            return [$points[0], $points[$n - 1]];
        }

        $left = $this->rdp(array_slice($points, 0, $index + 1), $tolerance);
        $right = $this->rdp(array_slice($points, $index), $tolerance);
        array_pop($left);

        return [...$left, ...$right];
    }

    /**
     * @param list<list<array{0: int, 1: int}>> $rings
     */
    private function toPath(array $rings, float $upscale, int $precision): string
    {
        $parts = [];

        foreach ($rings as $ring) {
            if (count($ring) < 3) {
                continue;
            }
            $segments = [];
            foreach ($ring as $i => [$x, $y]) {
                $segments[] = sprintf(
                    '%s%s %s',
                    0 === $i ? 'M' : 'L',
                    $this->round($x * $upscale, $precision),
                    $this->round($y * $upscale, $precision),
                );
            }
            $parts[] = implode(' ', $segments) . ' Z';
        }

        return implode(' ', $parts);
    }

    private function round(float $value, int $precision): string
    {
        $formatted = number_format($value, max(0, $precision), '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
