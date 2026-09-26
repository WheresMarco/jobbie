<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\JobStream\JobStreamSync;
use App\Jobs\Concerns\RunsOnLongQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SyncJobStream implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsOnLongQueue;

    public int $timeout = 3600;

    public int $tries = 2;

    /** JobStream allows one request per minute. */
    public int $backoff = 120;

    public function __construct()
    {
        $this->useLongQueue();
    }

    public function handle(JobStreamSync $sync): void
    {
        $result = $sync->sync();

        Log::info('JobStream synced', [
            'snapshot' => $result->snapshot,
            'ads' => $result->ads,
            'removed' => $result->removed,
            'new_domains' => $result->newDomains,
        ]);
    }
}
