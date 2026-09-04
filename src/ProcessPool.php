<?php

declare(strict_types=1);

namespace Sgs\Vectorizer;

use Symfony\Component\Process\Process;

final class ProcessPool
{
    private const DEFAULT_CONCURRENCY = 4;
    private const MAX_CONCURRENCY = 8;

    /**
     * @param array<int|string, Process> $processes keyed however the caller likes;
     *                                             keys are preserved
     * @return array<int|string, Process> the same processes, all finished
     */
    public static function run(array $processes, ?int $concurrency = null, int $timeout = 180): array
    {
        $limit = max(1, min(self::MAX_CONCURRENCY, $concurrency ?? self::concurrency()));

        $pending = $processes;
        $running = [];

        while ([] !== $pending || [] !== $running) {
            while ([] !== $pending && count($running) < $limit) {
                $key = array_key_first($pending);
                $process = $pending[$key];
                unset($pending[$key]);

                $process->setTimeout($timeout);
                $process->start();
                $running[$key] = $process;
            }

            usleep(10000);

            foreach ($running as $key => $process) {
                if (!$process->isRunning()) {
                    unset($running[$key]);
                }
            }
        }

        return $processes;
    }

    public static function concurrency(): int
    {
        static $count = null;
        if (null !== $count) {
            return $count;
        }

        $configured = (int) (getenv('VECTORIZER_CONCURRENCY') ?: 0);
        if ($configured > 0) {
            return $count = min(self::MAX_CONCURRENCY, $configured);
        }

        return $count = self::DEFAULT_CONCURRENCY;
    }
}
