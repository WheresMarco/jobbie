<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\PlatformBoardDiscovery;
use App\Enums\Platform;
use App\Jobs\Concerns\RunsOnLongQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs with source D: boards for one platform's accounts in Common Crawl.
 */
final class CrawlPlatformBoards implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsOnLongQueue;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public Platform $platform)
    {
        $this->useLongQueue();
    }

    public function uniqueId(): string
    {
        return $this->platform->value;
    }

    public function handle(PlatformBoardDiscovery $discovery): void
    {
        Log::info('Platform boards crawled', $discovery->crawl($this->platform));
    }
}
