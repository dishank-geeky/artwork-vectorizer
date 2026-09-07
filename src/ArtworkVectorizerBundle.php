<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Sgs\Vectorizer\Palette\JsonPaletteProvider;
use Sgs\Vectorizer\Palette\PaletteProviderInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the pipeline so a host application only has to inject
 * VectorizerService. Every service is declared by hand rather than globbed
 * from src/, because TraceOptions, Oklab, ProcessPool and ConversionAssessment
 * are value objects and static helpers that must not become services.
 */
final class ArtworkVectorizerBundle extends AbstractBundle
{
    protected string $extensionAlias = 'artwork_vectorizer';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('tmp_dir')
                    ->defaultValue('%kernel.project_dir%/var/tmp')
                    ->info('Scratch directory for intermediate images. Wiped after every conversion.')
                ->end()
                ->scalarNode('palette')
                    ->defaultValue('json')
                    ->info('"json" for the Pantone deck bundled with this package, or the id of your own service implementing PaletteProviderInterface.')
                ->end()
                ->scalarNode('palette_file')
                    ->defaultNull()
                    ->info('Override the bundled deck with your own json file. Only used when palette is "json".')
                ->end()
                ->arrayNode('binaries')
                    ->addDefaultsIfNotSet()
                    ->info('Absolute paths, or bare names if they are on PATH.')
                    ->children()
                        ->scalarNode('magick')->defaultValue('magick')->end()
                        ->scalarNode('potrace')->defaultValue('potrace')->end()
                        ->scalarNode('vtracer')->defaultValue('vtracer')->end()
                    ->end()
                ->end()
                ->arrayNode('timeouts')
                    ->addDefaultsIfNotSet()
                    ->info('Seconds. Raise the trace timeout for very large artwork.')
                    ->children()
                        ->integerNode('magick')->defaultValue(120)->min(1)->end()
                        ->integerNode('trace')->defaultValue(180)->min(1)->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     tmp_dir: string,
     *     palette: string,
     *     palette_file: string|null,
     *     binaries: array{magick: string, potrace: string, vtracer: string},
     *     timeouts: array{magick: int, trace: int}
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();   // private by default

        $services->set(ImageMagick::class)
            ->args([$config['binaries']['magick'], $config['timeouts']['magick']]);

        $services->set(PathTransformer::class);
        $services->set(SvgAssembler::class);

        $services->set(PaletteExtractor::class)
            ->args([service(ImageMagick::class)]);

        $services->set(ArtworkClassifier::class)
            ->args([service(ImageMagick::class), service(PaletteExtractor::class)]);

        $services->set(PotraceTracer::class)
            ->args([
                service(ImageMagick::class),
                service(PathTransformer::class),
                $config['binaries']['potrace'],
                $config['timeouts']['trace'],
            ]);

        $services->set(VtracerTracer::class)
            ->args([$config['binaries']['vtracer'], $config['timeouts']['trace']]);

        $services->set(PhpTracer::class)
            ->args([service(ImageMagick::class)]);

        // Own instance so the bundle does not depend on FrameworkBundle's.
        $services->set('artwork_vectorizer.filesystem', Filesystem::class);

        if ('json' === $config['palette']) {
            $services->set(JsonPaletteProvider::class)->args([$config['palette_file']]);
            $services->alias(PaletteProviderInterface::class, JsonPaletteProvider::class);
        } else {
            $services->alias(PaletteProviderInterface::class, $config['palette']);
        }

        $services->set(PmsPalette::class)
            ->args([service(PaletteProviderInterface::class)]);

        $services->set(VectorizerService::class)
            ->args([
                service(ImageMagick::class),
                service(PaletteExtractor::class),
                service(ArtworkClassifier::class),
                service(PotraceTracer::class),
                service(VtracerTracer::class),
                service(PhpTracer::class),
                service(SvgAssembler::class),
                service(PmsPalette::class),
                service('artwork_vectorizer.filesystem'),
                $config['tmp_dir'],
            ]);
    }
}
