<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class ImageMagick
{
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

    private function resolveBinary(): string
    {
        static $resolved = null;

        if (null !== $resolved) {
            return $resolved;
        }

        foreach ([$this->magickBinary, 'magick', 'convert'] as $candidate) {
            $probe = new Process([$candidate, '-version']);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $resolved = $candidate;
            }
        }

        return $resolved = $this->magickBinary;
    }
}
