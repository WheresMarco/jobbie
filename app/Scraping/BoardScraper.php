<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\BoardStatus;
use App\Models\Board;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scrapes one board through its platform's adapter and stores its postings.
 * Safe to run repeatedly and to interrupt: postings are upserted and only
 * deactivated after a complete read.
 */
final readonly class BoardScraper
{
    public function __construct(
        private PlatformAdapters $adapters,
        private PostingStore $store,
    ) {
    }

    public function scrape(Board $board): ScrapeResult
    {
        $now = Carbon::now();

        $adapter = $this->adapters->for($board->platform);

        try {
            $listing = $adapter->fetch($board);
        } catch (FeedException $e) {
            return $e->dead ? $this->markDead($board, $e->getMessage(), $now) : $this->markError($board, $e->getMessage(), $now);
        }

        $postings = $listing->postings;

        $deactivated = DB::transaction(function () use ($board, $listing, $postings, $now): int {
            $this->store->upsert($board, $postings, $now);

            $deactivated = 0;
            if (! $listing->truncated) {
                $deactivated = $board->jobPostings()
                    ->where('active', true)
                    ->whereNotIn('feed_id', array_map(strval(...), array_keys($postings)))
                    ->update(['active' => false, 'updated_at' => $now]);
            }

            $jobCount = $board->jobPostings()->where('active', true)->count();

            $board->forceFill([
                'status' => $jobCount > 0 ? BoardStatus::Active : BoardStatus::Empty,
                'job_count' => $jobCount,
                'name' => $listing->name ?? $board->name,
                'home_url' => $listing->homeUrl ?? $board->home_url,
                'checked_at' => $now,
                'error' => null,
            ])->save();

            return $deactivated;
        });

        $result = new ScrapeResult(
            status: $board->status,
            jobCount: $board->job_count,
            deactivated: $deactivated,
            truncated: $listing->truncated,
            listing: $listing,
        );

        $adapter->scraped($board, $listing);

        return $result;
    }

    /**
     * The site is gone or no longer serves job postings: its ads are closed.
     */
    private function markDead(Board $board, string $error, Carbon $now): ScrapeResult
    {
        $deactivated = DB::transaction(function () use ($board, $error, $now): int {
            $deactivated = $board->jobPostings()->where('active', true)->update(['active' => false, 'updated_at' => $now]);
            $board->forceFill([
                'status' => BoardStatus::Dead,
                'job_count' => 0,
                'checked_at' => $now,
                'error' => $error,
            ])->save();

            return $deactivated;
        });

        return new ScrapeResult(BoardStatus::Dead, deactivated: $deactivated, error: $error);
    }

    /**
     * A temporary problem: keep the last known postings.
     */
    private function markError(Board $board, string $error, Carbon $now): ScrapeResult
    {
        $board->forceFill([
            'status' => BoardStatus::Error,
            'checked_at' => $now,
            'error' => $error,
        ])->save();

        return new ScrapeResult(BoardStatus::Error, jobCount: $board->job_count, error: $error);
    }
}
