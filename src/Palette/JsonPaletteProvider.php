<?php

declare(strict_types=1);

namespace Sgs\Vectorizer\Palette;

/**
 * Reads inks from a JSON file of {code, hex} objects.
 *
 * The portable default, and the fallback when a database-backed provider has
 * nothing. Accepts either key spelling so it can read SGS's PMSColors.json
 * (pmsCode/hexCode) unchanged.
 */
final class JsonPaletteProvider implements PaletteProviderInterface
{
    private readonly string $path;

    /**
     * Falls back to the Pantone Coated deck bundled with this package when no
     * path is given, so the library is useful without any host configuration.
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path ?? \dirname(__DIR__, 2) . '/resources/pms-colors.json';
    }

    public function colours(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }

        try {
            $rows = json_decode((string) file_get_contents($this->path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? $row['pmsCode'] ?? '');
            $hex = (string) ($row['hex'] ?? $row['hexCode'] ?? '');
            if ('' !== $code && '' !== $hex) {
                $out[] = ['code' => $code, 'hex' => $hex];
            }
        }

        return $out;
    }
}
