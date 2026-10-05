<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Board;
use App\Scraping\BoardScraper;
use App\Scraping\PlatformAdapters;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ScrapeBoard implements ShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Queueable;

    /** Unexpected exceptions allowed before the job fails. Overlap releases do not count. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public Board $board)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->board->id;
    }

    /**
     * At most one scrape at a time per concurrency key: per host for
     * Teamtailor, per platform for platforms on a shared host.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $key = app(PlatformAdapters::class)->for($this->board->platform)->concurrencyKey($this->board);

        return [
            (new WithoutOverlapping($key))
                ->releaseAfter(30)
                ->expireAfter(900),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(BoardScraper $scraper): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $scraper->scrape($this->board);
    }
}
