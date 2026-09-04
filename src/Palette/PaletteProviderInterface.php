<?php

declare(strict_types=1);

namespace Sgs\Vectorizer\Palette;

/**
 * Supplies the orderable ink list the tracer snaps colours to.
 *
 * The only part of the pipeline that has to know about a host project, so it is
 * an interface: the tracer stays free of Doctrine, entities and project paths.
 */
interface PaletteProviderInterface
{
    /**
     * @return list<array{code: string, hex: string}> hex with or without a leading #
     */
    public function colours(): array;
}
