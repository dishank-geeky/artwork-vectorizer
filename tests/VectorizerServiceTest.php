<?php

declare(strict_types=1);

namespace Sgs\Vectorizer\Tests;

use PHPUnit\Framework\TestCase;
use Sgs\Vectorizer\ArtworkClassifier;
use Sgs\Vectorizer\ImageMagick;
use Sgs\Vectorizer\Palette\JsonPaletteProvider;
use Sgs\Vectorizer\PaletteExtractor;
use Sgs\Vectorizer\PathTransformer;
use Sgs\Vectorizer\PhpTracer;
use Sgs\Vectorizer\PmsPalette;
use Sgs\Vectorizer\PotraceTracer;
use Sgs\Vectorizer\SvgAssembler;
use Sgs\Vectorizer\TraceOptions;
use Sgs\Vectorizer\VectorizerService;
use Sgs\Vectorizer\VtracerTracer;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The fixture is a flat four-ink logo: red disc, navy triangle, yellow bar and
 * the word FALCONS in navy, on white. Anti-aliasing pushes it to 403 unique
 * colours, so getting back exactly four inks is the whole job.
 */
final class VectorizerServiceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/four-ink-logo.png';

    /** The four colours actually drawn, and the Pantone each should snap to. */
    private const EXPECTED = [
        '#FFFFFF' => 'WHITE',
        '#C8102E' => 'PMS 186 C',
        '#041E42' => 'PMS 282 C',
        '#FFB81C' => 'PMS 1235 C',
    ];

    private string $magickBinary;

    /**
     * ImageMagick 7 ships "magick"; the 6.x still on most distros ships only
     * "convert". Resolve it here so the suite runs on both instead of skipping.
     */
    protected function setUp(): void
    {
        foreach ([getenv('MAGICK_BIN'), 'magick', 'convert'] as $candidate) {
            if (!\is_string($candidate) || '' === $candidate) {
                continue;
            }

            try {
                (new ImageMagick($candidate))->dimensions(self::FIXTURE);
                $this->magickBinary = $candidate;

                return;
            } catch (\Throwable) {
                continue;
            }
        }

        self::markTestSkipped('No ImageMagick binary found (tried magick and convert).');
    }

    public function testSeparatesTheFixtureIntoItsFourInks(): void
    {
        $result = $this->service()->convert(self::FIXTURE, TraceOptions::fromPreset(TraceOptions::PRESET_LOGO));

        self::assertCount(4, $result['layers'], 'the four drawn inks should survive as four layers');
        self::assertSame([900, 400], [$result['width'], $result['height']]);
        self::assertSame(array_keys(self::EXPECTED), array_column($result['layers'], 'hex'));
    }

    public function testSnapsEveryInkToItsExactPantone(): void
    {
        $result = $this->service()->convert(self::FIXTURE, TraceOptions::fromPreset(TraceOptions::PRESET_LOGO));

        foreach ($result['layers'] as $layer) {
            self::assertSame(
                self::EXPECTED[$layer['hex']],
                $layer['pms'],
                sprintf('%s should snap to %s', $layer['hex'], self::EXPECTED[$layer['hex']])
            );
        }
    }

    public function testTheSvgIsPrintReady(): void
    {
        $svg = $this->service()->convert(self::FIXTURE, TraceOptions::fromPreset(TraceOptions::PRESET_LOGO))['svg'];

        self::assertSame([], SvgAssembler::complianceIssues($svg), 'paths with plain hex fills only');
        self::assertSame(4, preg_match_all('/<g id="color-\d+"/', $svg), 'one group per ink');
        self::assertStringNotContainsString('<image', $svg, 'no embedded raster');
    }

    /**
     * The guard against silent quality regressions: a flat logo has an exact
     * vector answer, so anything above a few percent means a real defect.
     */
    public function testStaysCloseToTheOriginal(): void
    {
        $service = $this->service();
        $options = TraceOptions::fromPreset(TraceOptions::PRESET_LOGO);
        $result = $service->convert(self::FIXTURE, $options);

        $deviation = $service->measureDeviation(self::FIXTURE, $result['svg'], $options->removeBackground);

        self::assertNotNull($deviation);
        self::assertLessThan(5.0, $deviation, sprintf('deviation was %.2f%%', $deviation));
    }

    public function testDetectsTheLogoPresetAndInkCount(): void
    {
        $detection = $this->service()->inspect(self::FIXTURE);

        self::assertSame(TraceOptions::PRESET_LOGO, $detection['preset']);
        self::assertSame(4, $detection['detectedInks']);
    }

    public function testReportsWhichEnginesAreInstalled(): void
    {
        foreach ($this->service()->engines() as $name => $engine) {
            self::assertIsBool($engine['available'], $name . ' must report availability');
            self::assertNotSame('', $engine['label']);
        }
    }

    private function service(): VectorizerService
    {
        $magick = new ImageMagick($this->magickBinary);
        $extractor = new PaletteExtractor($magick);

        return new VectorizerService(
            $magick,
            $extractor,
            new ArtworkClassifier($magick, $extractor),
            new PotraceTracer($magick, new PathTransformer()),
            new VtracerTracer(),
            new PhpTracer($magick),
            new SvgAssembler(),
            new PmsPalette(new JsonPaletteProvider()),
            new Filesystem(),
            sys_get_temp_dir(),
        );
    }
}
