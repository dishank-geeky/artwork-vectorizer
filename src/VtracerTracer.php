<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Symfony\Component\Process\Process;

final class VtracerTracer implements TracerInterface
{
    public function __construct(
        private readonly string $vtracerBinary = 'vtracer',
        private readonly int $timeout = 180,
    ) {
    }

    public function name(): string
    {
        return 'vtracer';
    }

    public function isAvailable(): bool
    {
        $probe = new Process([$this->vtracerBinary, '--version']);
        $probe->run();

        return $probe->isSuccessful();
    }

    public function inputPreference(): string
    {
        return self::INPUT_SNAPPED;
    }

    public function trace(string $inputFile, array $palette, string $workDir, TraceOptions $options): array
    {
        $output = $workDir . '/vtracer.svg';

        $inks = array_column($palette, 'hex');
        $inks[] = TraceOptions::BACKGROUND_SENTINEL;

        $process = new Process([
            $this->vtracerBinary,
            $inputFile,
            $output,
            '--palette', implode(',', $inks),
            '--hierarchical', 'cutout',
            '--simplify', (string) max(0.1, $options->simplify),
            '--filter-speckle', (string) $options->speckleSize,
            '--path-precision', (string) max(1, $options->precision),
            '--optimize', '2',
        ]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful() || !is_file($output)) {
            return [];
        }

        $byColour = $this->collectPathsByFill((string) file_get_contents($output));

        $merged = [];
        foreach ($byColour as $emittedHex => $d) {
            $target = $this->nearestInk($emittedHex, $palette);
            $merged[$target] = ($merged[$target] ?? '') . ' ' . $d;
        }

        $layers = [];
        foreach ($palette as $colour) {
            $hex = strtoupper($colour['hex']);
            if (!isset($merged[$hex])) {
                continue;
            }
            $layers[] = [
                'hex' => $hex,
                'share' => $colour['share'],
                'pms' => $colour['pms'] ?? null,
                'pmsDelta' => $colour['pmsDelta'] ?? null,
                'd' => SvgAssembler::roundPath(trim($merged[$hex]), $options->precision),
                'transform' => null,
            ];
        }

        return $layers;
    }

    /**
     * @param list<array{hex: string, share: float, rgb: array{0: int, 1: int, 2: int},
     *                   pms?: string|null, pmsDelta?: float|null}> $palette
     */
    private function nearestInk(string $hex, array $palette): string
    {
        $rgb = Oklab::hexToRgb($hex);
        $best = strtoupper($palette[0]['hex']);
        $bestDistance = \PHP_FLOAT_MAX;

        foreach ($palette as $ink) {
            $distance = Oklab::distance($rgb, $ink['rgb']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = strtoupper($ink['hex']);
            }
        }

        return $best;
    }

    /**
     * @return array<string, string> uppercase hex => concatenated path data
     */
    private function collectPathsByFill(string $svg): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $byColour = [];

        foreach ((new \DOMXPath($document))->query('//*[local-name()="path"]') ?: [] as $path) {
            if (!$path instanceof \DOMElement) {
                continue;
            }

            $d = $path->getAttribute('d');
            if ('' === $d) {
                continue;
            }

            $fill = $this->effectiveFill($path);
            if (null === $fill || TraceOptions::BACKGROUND_SENTINEL === $fill) {
                continue;
            }

            $byColour[$fill] = ($byColour[$fill] ?? '') . ' ' . $d;
        }

        return $byColour;
    }

    private function effectiveFill(\DOMElement $element): ?string
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            $fill = $node->getAttribute('fill');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $fill)) {
                return strtoupper($fill);
            }
        }

        return null;
    }
}
