<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Sgs\Vectorizer\Palette\PaletteProviderInterface;

final class PmsPalette
{
    private const NAMED_INKS = [
        ['code' => 'WHITE', 'hex' => 'FFFFFF'],
        ['code' => 'BLACK', 'hex' => '000000'],
    ];

    /** @var list<array{code: string, hex: string, rgb: array{0: int, 1: int, 2: int}}>|null */
    private ?array $colours = null;

    public function __construct(private readonly PaletteProviderInterface $provider)
    {
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     * @return array{code: string, hex: string, rgb: array{0: int, 1: int, 2: int}, delta: float}|null
     */
    public function nearest(array $rgb): ?array
    {
        $best = null;
        $bestDistance = \PHP_FLOAT_MAX;

        foreach ($this->colours() as $colour) {
            $distance = Oklab::distance($rgb, $colour['rgb']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $colour;
            }
            if (0.0 === $bestDistance) {
                break;      // exact match
            }
        }

        if (null === $best) {
            return null;
        }

        return [...$best, 'delta' => round($bestDistance, 4)];
    }

    public function isEmpty(): bool
    {
        return [] === $this->colours();
    }

    /**
     * @return list<array{code: string, hex: string, rgb: array{0: int, 1: int, 2: int}}>
     */
    private function colours(): array
    {
        if (null !== $this->colours) {
            return $this->colours;
        }

        $out = [];
        foreach ($this->provider->colours() as $row) {
            $entry = self::entry($row['code'], $row['hex']);
            if (null !== $entry) {
                $out[] = $entry;
            }
        }

        return $this->colours = self::withNamedInks($out);
    }

    /**
     * White and black are standard print inks absent from the Pantone Coated deck,
     * whose L* only spans 9.3-91.9. Without them black snaps to PMS 296 C, a navy,
     * at ten times the error of any other ink.
     *
     * @param list<array{code: string, hex: string, rgb: array{0: int, 1: int, 2: int}}> $colours
     * @return list<array{code: string, hex: string, rgb: array{0: int, 1: int, 2: int}}>
     */
    private static function withNamedInks(array $colours): array
    {
        if ([] === $colours) {
            return $colours;
        }

        $have = array_column($colours, 'hex');
        foreach (self::NAMED_INKS as $ink) {
            $entry = self::entry($ink['code'], $ink['hex']);
            if (null !== $entry && !in_array($entry['hex'], $have, true)) {
                $colours[] = $entry;
            }
        }

        return $colours;
    }

    /**
     * @return array{code: string, hex: string, rgb: array{0: int, 1: int, 2: int}}|null
     */
    private static function entry(string $code, string $hex): ?array
    {
        $hex = strtoupper(ltrim(trim($hex), '#'));
        if ('' === $code || 1 !== preg_match('/^[0-9A-F]{6}$/', $hex)) {
            return null;
        }

        return ['code' => $code, 'hex' => '#' . $hex, 'rgb' => Oklab::hexToRgb($hex)];
    }
}
