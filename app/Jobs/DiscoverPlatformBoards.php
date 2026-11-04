<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\PlatformBoardDiscovery;
use App\Jobs\Concerns\RunsOnLongQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs after SyncJobStream: boards on other platforms from apply links.
 */
final class DiscoverPlatformBoards implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsOnLongQueue;

    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct()
    {
        $this->useLongQueue();
    }

    public function handle(PlatformBoardDiscovery $discovery): void
    {
        Log::info('Platform boards discovered', $discovery->discover());
    }
}
