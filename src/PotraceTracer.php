<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Symfony\Component\Process\Process;

final class PotraceTracer implements TracerInterface
{
    public function __construct(
        private readonly ImageMagick $magick,
        private readonly PathTransformer $pathTransformer,
        private readonly string $potraceBinary = 'potrace',
        private readonly int $timeout = 180,
    ) {
    }

    public function name(): string
    {
        return 'potrace';
    }

    public function isAvailable(): bool
    {
        $probe = new Process([$this->potraceBinary, '--version']);
        $probe->run();

        return $probe->isSuccessful();
    }

    public function inputPreference(): string
    {
        return self::INPUT_SNAPPED;
    }

    public function trace(string $inputFile, array $palette, string $workDir, TraceOptions $options): array
    {
        $masks = [];
        $cuts = [];
        foreach ($palette as $index => $colour) {
            $mask = sprintf('%s/mask-%d.pbm', $workDir, $index);
            $masks[$index] = $mask;
            $cuts[$index] = $this->magick->processFor($this->maskArgs($inputFile, $colour['hex'], $mask));
        }
        ProcessPool::run($cuts, null, $this->timeout);

        $svgs = [];
        $traces = [];
        foreach ($masks as $index => $mask) {
            if (!is_file($mask)) {
                continue;
            }
            $svg = sprintf('%s/mask-%d.svg', $workDir, $index);
            $svgs[$index] = $svg;
            $traces[$index] = new Process($this->potraceArgs($mask, $svg, $options));
        }
        ProcessPool::run($traces, null, $this->timeout);

        $layers = [];
        foreach ($palette as $index => $colour) {
            if (!isset($svgs[$index], $traces[$index]) || !$traces[$index]->isSuccessful()) {
                continue;
            }

            $traced = $this->readTrace($svgs[$index], $options);
            if (null === $traced) {
                continue;
            }

            $layers[] = [
                'hex' => $colour['hex'],
                'share' => $colour['share'],
                'pms' => $colour['pms'] ?? null,
                'pmsDelta' => $colour['pmsDelta'] ?? null,
                'd' => $traced['d'],
                'transform' => $traced['transform'],
            ];
        }

        return $layers;
    }

    /**
     * @return list<string>
     */
    private function maskArgs(string $file, string $hex, string $mask): array
    {
        return [
            $file,
            '-alpha', 'off',
            '-fuzz', '0%',
            '-transparent', $hex,
            '-alpha', 'extract',
            $mask,
        ];
    }

    /**
     * @return list<string>
     */
    private function potraceArgs(string $mask, string $svg, TraceOptions $options): array
    {
        return [
            $this->potraceBinary,
            '--svg',
            '--flat',                                        // one path, not nested groups
            '--output', $svg,
            '--turdsize', (string) $options->speckleSize,
            '--alphamax', (string) $options->cornerSmoothing,
            '--opttolerance', (string) $options->curveTolerance,
            $mask,
        ];
    }

    /**
     * @return array{d: string, transform: string|null}|null
     */
    private function readTrace(string $svgFile, TraceOptions $options): ?array
    {
        if (!is_file($svgFile)) {
            return null;
        }

        $svg = (string) file_get_contents($svgFile);

        preg_match_all('/\sd="([^"]+)"/', $svg, $matches);
        $d = trim(implode(' ', $matches[1]));

        if ('' === $d) {
            return null;
        }

        $transform = preg_match('/<g[^>]*transform="([^"]+)"/s', $svg, $m) ? $m[1] : null;

        $baked = $this->pathTransformer->bake($d, $transform, $options->precision);

        return [
            'd' => null === $baked['transform']
                ? $baked['d']
                : SvgAssembler::roundPath($baked['d'], $options->precision),
            'transform' => $baked['transform'],
        ];
    }

}
