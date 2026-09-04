<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class ConversionAssessment
{
    public const VERDICT_GOOD = 'good';
    public const VERDICT_REVIEW = 'review';
    public const VERDICT_ESCALATE = 'escalate';

    private const DEVIATION_GOOD = 3.0;

    private const DEVIATION_ESCALATE = 6.0;

    private const COVERAGE_INCOMPLETE = 92.0;

    private const PAINTED_PRESETS = [
        TraceOptions::PRESET_ILLUSTRATION,
        TraceOptions::PRESET_PHOTO,
        TraceOptions::PRESET_MAX_DETAIL,
    ];

    /**
     * @return array{verdict: string, headline: string, detail: string}
     */
    public static function assess(
        ?float $deviation,
        ?float $coverage,
        string $preset,
        int $layerCount,
    ): array {
        $painted = in_array($preset, self::PAINTED_PRESETS, true);

        if (null !== $coverage && $coverage < self::COVERAGE_INCOMPLETE) {
            return [
                'verdict' => self::VERDICT_ESCALATE,
                'headline' => sprintf('Incomplete — only %s%% of the artwork was drawn', $coverage),
                'detail' => 'Part of the artwork is missing rather than imprecise. Try the other trace '
                    . 'engine or raise the colour count before using this.',
            ];
        }

        if (null === $deviation) {
            return [
                'verdict' => self::VERDICT_REVIEW,
                'headline' => 'Converted — accuracy not measured',
                'detail' => 'Check the result against the original at 3x zoom before using it.',
            ];
        }

        if ($deviation > self::DEVIATION_ESCALATE) {
            return [
                'verdict' => self::VERDICT_ESCALATE,
                'headline' => sprintf('Poor fit for tracing — %s%% off the original', $deviation),
                'detail' => 'Ask the customer for the vector original (.ai, .eps or .svg), or send this '
                    . 'to a designer to redraw. Tracing cannot get closer on artwork like this.',
            ];
        }

        if ($deviation > self::DEVIATION_GOOD) {
            return [
                'verdict' => self::VERDICT_REVIEW,
                'headline' => sprintf('Usable, worth a look — %s%% off the original', $deviation),
                'detail' => $painted
                    ? 'Painted artwork, so smooth shading has become tonal steps. Check the shaded areas '
                        . 'at 3x zoom and decide whether the stepping is acceptable for the print method.'
                    : 'Check fine detail and small text at 3x zoom. Raising the colour count or switching '
                        . 'engine often closes the gap on flat artwork.',
            ];
        }

        return [
            'verdict' => self::VERDICT_GOOD,
            'headline' => sprintf('Good — %s%% off the original across %d layers', $deviation, $layerCount),
            'detail' => $painted
                ? 'Close as tracing gets on painted artwork. Any remaining difference is tonal stepping '
                    . 'where the original has smooth shading, which is inherent to flat colour separations.'
                : 'Remaining difference is sub-pixel edge placement in the anti-aliased border, where the '
                    . 'source itself does not define an exact edge.',
        ];
    }
}
