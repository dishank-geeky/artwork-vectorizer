<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class SvgAssembler
{
    /**
     * @param list<array{hex: string, share: float, d: string, transform: string|null,
     *                    pms?: string|null, pmsDelta?: float|null}> $layers
     */
    public function assemble(array $layers, int $width, int $height): string
    {
        $parts = [sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" fill="none">',
            $width,
            $height,
            $width,
            $height,
        )];

        foreach ($layers as $i => $layer) {
            $transform = null !== $layer['transform']
                ? sprintf(' transform="%s"', htmlspecialchars($layer['transform'], ENT_QUOTES))
                : '';

            $pms = isset($layer['pms']) && null !== $layer['pms']
                ? sprintf(
                    ' data-pms="%s" data-pms-delta="%s"',
                    htmlspecialchars((string) $layer['pms'], ENT_QUOTES),
                    $layer['pmsDelta'] ?? '',
                )
                : '';

            $parts[] = sprintf(
                '<g id="color-%d" data-color="%s" data-area="%s"%s fill="%s"%s><path d="%s"/></g>',
                $i + 1,
                $layer['hex'],
                $layer['share'],
                $pms,
                $layer['hex'],
                $transform,
                $layer['d'],
            );
        }

        $parts[] = '</svg>';

        return implode('', $parts);
    }

    public static function roundPath(string $d, int $places): string
    {
        return (string) preg_replace_callback(
            '/-?\d+\.\d+/',
            static function (array $m) use ($places): string {
                $value = number_format((float) $m[0], max(0, $places), '.', '');

                return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
            },
            $d,
        );
    }

    /**
     * @return list<string>
     */
    public static function complianceIssues(string $svg): array
    {
        $issues = [];

        foreach (['rect', 'circle', 'ellipse', 'polygon', 'polyline', 'line', 'image', 'text', 'use'] as $tag) {
            if (preg_match('/<' . $tag . '[\s\/>]/i', $svg)) {
                $issues[] = sprintf('contains <%s> (SGS layers keep only <path>)', $tag);
            }
        }

        foreach (['linearGradient', 'radialGradient', 'filter', 'mask', 'clipPath', 'pattern'] as $tag) {
            if (preg_match('/<' . $tag . '[\s\/>]/i', $svg)) {
                $issues[] = sprintf('contains <%s> (unsupported in print separations)', $tag);
            }
        }

        if (preg_match('/\sstroke="(?!none)/i', $svg)) {
            $issues[] = 'contains stroke attributes (fills only)';
        }

        if (preg_match('/\sclass="/i', $svg)) {
            $issues[] = 'contains CSS classes (the design editor overwrites class attributes)';
        }

        if (preg_match('/fill="rgb\(/i', $svg)) {
            $issues[] = 'uses rgb() fills instead of hex';
        }

        return $issues;
    }
}
