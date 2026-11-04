<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Discovery\Sources\RedirectSource;
use App\Enums\BoardStatus;
use App\Models\Board;
use App\Platforms\PlatformRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scrapes one board, on whichever platform, and stores its postings. Safe
 * to run repeatedly and to interrupt: postings are upserted and only
 * deactivated after a complete read.
 */
final readonly class BoardScraper
{
    public function __construct(
        private PlatformRegistry $platforms,
        private PostingWriter $writer,
        private RedirectSource $redirects,
    ) {
    }

    public function scrape(Board $board): ScrapeResult
    {
        $now = Carbon::now();

        try {
            $feed = $this->platforms->adapter($board->platform)->fetch($board);
        } catch (FeedException $e) {
            return $e->dead ? $this->markDead($board, $e->getMessage(), $now) : $this->markError($board, $e->getMessage(), $now);
        }

        $deactivated = DB::transaction(function () use ($board, $feed, $now): int {
            $this->writer->write($board, $feed->postings, $now);

            $deactivated = $feed->truncated
                ? 0
                : $this->writer->deactivateMissing($board, array_map(strval(...), array_keys($feed->postings)), $now);

            $jobCount = $board->jobPostings()->where('active', true)->count();

            $board->forceFill([
                'status' => $jobCount > 0 ? BoardStatus::Active : BoardStatus::Empty,
                'job_count' => $jobCount,
                'name' => $feed->title ?? $board->name,
                'home_url' => $feed->homeUrl ?? $board->home_url,
                'checked_at' => $now,
                'error' => null,
            ])->save();

            return $deactivated;
        });

        $result = new ScrapeResult(
            status: $board->status,
            jobCount: $board->job_count,
            deactivated: $deactivated,
            truncated: $feed->truncated,
            feed: $feed->teamtailor,
        );

        // Source A: record where a Teamtailor feed lives and merge duplicate boards.
        if ($feed->teamtailor !== null) {
            $this->redirects->record($board, $feed->teamtailor);
        }

        return $result;
    }

    /**
     * The site is gone or is no longer a job feed: its ads are closed.
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
