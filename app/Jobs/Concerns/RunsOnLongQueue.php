<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

/**
 * Sends a job to the connection for long discovery jobs, when one is set
 * (radar.long_queue_connection).
 */
trait RunsOnLongQueue
{
    protected function useLongQueue(): void
    {
        $connection = config('radar.long_queue_connection');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection)->onQueue('discovery');
        }
    }
}
