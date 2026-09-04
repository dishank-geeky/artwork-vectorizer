<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

final class PathTransformer
{
    private const SUPPORTED_COMMANDS = ['M', 'm', 'l', 'c', 'z', 'Z'];

    /**
     * @return array{d: string, transform: string|null} transform is non-null only
     *                                                  when it could not be baked in
     */
    public function bake(string $d, ?string $transform, int $precision): array
    {
        if (null === $transform) {
            return ['d' => $d, 'transform' => null];
        }

        $parsed = $this->parseTranslateScale($transform);
        if (null === $parsed) {
            return ['d' => $d, 'transform' => $transform];
        }

        [$translateX, $translateY, $scaleX, $scaleY] = $parsed;

        $tokens = $this->tokenise($d);
        if (null === $tokens) {
            return ['d' => $d, 'transform' => $transform];
        }

        $out = [];

        foreach ($tokens as [$command, $numbers]) {
            if ('z' === $command || 'Z' === $command) {
                $out[] = 'Z';
                continue;
            }

            $isAbsolute = $command === strtoupper($command);
            $mapped = [];

            for ($i = 0, $n = count($numbers); $i < $n; $i += 2) {
                $x = $numbers[$i];
                $y = $numbers[$i + 1] ?? 0.0;

                if ($isAbsolute) {
                    $mapped[] = $translateX + $x * $scaleX;
                    $mapped[] = $translateY + $y * $scaleY;
                } else {
                    $mapped[] = $x * $scaleX;
                    $mapped[] = $y * $scaleY;
                }
            }

            $out[] = $command . $this->formatNumbers($mapped, $precision);
        }

        return ['d' => implode('', $out), 'transform' => null];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function parseTranslateScale(string $transform): ?array
    {
        $pattern = '/^\s*translate\(\s*(-?[\d.]+)[,\s]+(-?[\d.]+)\s*\)\s*'
            . 'scale\(\s*(-?[\d.]+)[,\s]+(-?[\d.]+)\s*\)\s*$/';

        if (!preg_match($pattern, $transform, $m)) {
            return null;
        }

        return [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
    }

    /**
     * @return list<array{0: string, 1: list<float>}>|null
     */
    private function tokenise(string $d): ?array
    {
        if (!preg_match_all('/([A-Za-z])([^A-Za-z]*)/', $d, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $tokens = [];

        foreach ($matches as $match) {
            $command = $match[1];
            if (!in_array($command, self::SUPPORTED_COMMANDS, true)) {
                return null;
            }

            preg_match_all('/-?\d*\.?\d+(?:[eE][-+]?\d+)?/', $match[2], $nums);
            $numbers = array_map('floatval', $nums[0]);

            if ('z' !== $command && 'Z' !== $command && 0 !== count($numbers) % 2) {
                return null;
            }

            $tokens[] = [$command, $numbers];
        }

        return $tokens;
    }

    /**
     * @param list<float> $numbers
     */
    private function formatNumbers(array $numbers, int $precision): string
    {
        $parts = [];

        foreach ($numbers as $number) {
            $formatted = number_format($number, max(0, $precision), '.', '');
            if (str_contains($formatted, '.')) {
                $formatted = rtrim(rtrim($formatted, '0'), '.');
            }
            if ('' === $formatted || '-0' === $formatted) {
                $formatted = '0';
            }
            $parts[] = $formatted;
        }

        $out = '';
        foreach ($parts as $i => $part) {
            if (0 !== $i && !str_starts_with($part, '-')) {
                $out .= ' ';
            }
            $out .= $part;
        }

        return $out;
    }
}
