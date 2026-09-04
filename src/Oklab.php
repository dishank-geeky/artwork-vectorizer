<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class Oklab
{
    /** @return array{0: int, 1: int, 2: int} */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float} $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    public static function fromRgb(array $rgb): array
    {
        $r = self::toLinear((float) $rgb[0]);
        $g = self::toLinear((float) $rgb[1]);
        $b = self::toLinear((float) $rgb[2]);

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float} $a
     * @param array{0: int|float, 1: int|float, 2: int|float} $b
     */
    public static function distance(array $a, array $b): float
    {
        [$l1, $a1, $b1] = self::fromRgb($a);
        [$l2, $a2, $b2] = self::fromRgb($b);

        return sqrt(($l1 - $l2) ** 2 + ($a1 - $a2) ** 2 + ($b1 - $b2) ** 2);
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float} $point
     * @param array{0: int|float, 1: int|float, 2: int|float} $a
     * @param array{0: int|float, 1: int|float, 2: int|float} $b
     */
    public static function distanceToBlend(array $point, array $a, array $b): float
    {
        $p = self::fromRgb($point);
        $from = self::fromRgb($a);
        $to = self::fromRgb($b);

        $ab = [$to[0] - $from[0], $to[1] - $from[1], $to[2] - $from[2]];
        $ap = [$p[0] - $from[0], $p[1] - $from[1], $p[2] - $from[2]];

        $lenSq = $ab[0] ** 2 + $ab[1] ** 2 + $ab[2] ** 2;
        if ($lenSq <= 0.0) {
            return self::distance($point, $a);
        }

        $t = max(0.0, min(1.0, ($ap[0] * $ab[0] + $ap[1] * $ab[1] + $ap[2] * $ab[2]) / $lenSq));

        return sqrt(
            ($p[0] - ($from[0] + $t * $ab[0])) ** 2
            + ($p[1] - ($from[1] + $t * $ab[1])) ** 2
            + ($p[2] - ($from[2] + $t * $ab[2])) ** 2,
        );
    }

    private static function toLinear(float $channel): float
    {
        $c = $channel / 255.0;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function cbrt(float $v): float
    {
        return $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
    }
}
