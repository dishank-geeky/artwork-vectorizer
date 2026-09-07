<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class ImageMagick
{
    private ?string $resolved = null;

    public function __construct(
        private readonly string $magickBinary = 'magick',
        private readonly int $timeout = 120,
    ) {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): string
    {
        $process = new Process([$this->resolveBinary(), ...$args]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $args
     */
    public function processFor(array $args): Process
    {
        $process = new Process([$this->resolveBinary(), ...$args]);
        $process->setTimeout($this->timeout);

        return $process;
    }

    /**
     * @param list<string> $args
     */
    public function runBinary(array $args): string
    {
        $process = new Process([$this->resolveBinary(), ...$args]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $file): array
    {
        $out = trim($this->run([$file, '-format', '%w %h', 'info:-']));
        [$w, $h] = array_map('intval', explode(' ', $out));

        return [$w, $h];
    }

    /**
     * @return list<array{count: int, rgb: array{0: int, 1: int, 2: int}, hex: string}>
     */
    public function histogram(string $file): array
    {
        $out = $this->run([$file, '-format', '%c', 'histogram:info:-']);
        $rows = [];

        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^\s*(\d+):.*?#([0-9A-Fa-f]{6})/', $line, $m)) {
                continue;
            }
            $hex = '#' . strtoupper($m[2]);
            $rows[] = ['count' => (int) $m[1], 'rgb' => Oklab::hexToRgb($hex), 'hex' => $hex];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    public function meanLevel(string $file): float
    {
        return (float) trim($this->run([$file, '-format', '%[fx:mean]', 'info:-']));
    }

    public function pixelAt(string $file, int $x, int $y): string
    {
        return trim($this->run([$file, '-format', sprintf('%%[pixel:p{%d,%d}]', $x, $y), 'info:-']));
    }

    /**
     * RMSE between two images as a percentage, or null if it cannot be taken.
     *
     * ImageMagick 7 exposes this as "magick compare"; 6.x ships a separate
     * "compare" binary. Deriving it from the resolved binary means a custom
     * install path is honoured here too, instead of assuming one on PATH.
     */
    public function compareRmse(string $reference, string $rendered): ?float
    {
        $binary = $this->resolveBinary();
        $args = ['-metric', 'RMSE', $reference, $rendered, 'null:'];

        $command = 'magick' === basename($binary)
            ? [$binary, 'compare', ...$args]
            : [$this->siblingBinary($binary, 'compare'), ...$args];

        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        // The score goes to stderr, as "1234.5 (0.0188)".
        if (1 === preg_match('/\(([\d.]+)\)/', $process->getErrorOutput(), $m)) {
            return round((float) $m[1] * 100, 2);
        }

        return null;
    }

    private function siblingBinary(string $binary, string $name): string
    {
        $dir = \dirname($binary);

        return \in_array($dir, ['', '.'], true) ? $name : $dir . '/' . $name;
    }

    private function resolveBinary(): string
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        foreach ([$this->magickBinary, 'magick', 'convert'] as $candidate) {
            $probe = new Process([$candidate, '-version']);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $this->resolved = $candidate;
            }
        }

        return $this->resolved = $this->magickBinary;
    }
}
