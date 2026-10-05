<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\BoardStatus;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\JobPostingLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scrapes one board through its platform's adapter and stores its postings.
 * Safe to run repeatedly and to interrupt: postings are upserted and only
 * deactivated after a complete read.
 */
final readonly class BoardScraper
{
    private const int CHUNK = 500;

    public function __construct(private PlatformAdapters $adapters)
    {
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
            $this->upsert($board, $postings, $now);

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
     * @param  array<string, JobPostingData>  $postings
     */
    private function upsert(Board $board, array $postings, Carbon $now): void
    {
        foreach (array_chunk($postings, self::CHUNK, true) as $chunk) {
            $rows = array_map(static fn (JobPostingData $p): array => [
                'board_id' => $board->id,
                'feed_id' => $p->feedId,
                'title' => mb_substr($p->title, 0, 500),
                'url' => mb_substr($p->url, 0, 2048),
                'company' => $p->company === null ? null : mb_substr($p->company, 0, 255),
                'cities' => json_encode($p->cities(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'countries' => json_encode($p->countries(), JSON_THROW_ON_ERROR),
                'remote' => $p->remote?->value,
                'department' => $p->department === null ? null : mb_substr($p->department, 0, 255),
                'employment_type' => $p->employmentType === null ? null : mb_substr($p->employmentType, 0, 100),
                'salary_text' => $p->salaryText === null ? null : mb_substr($p->salaryText, 0, 255),
                'posted_at' => $p->postedAt?->format('Y-m-d H:i:s'),
                'description' => $p->description,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($chunk));

            JobPosting::query()->upsert($rows, ['board_id', 'feed_id'], [
                'title', 'url', 'company', 'cities', 'countries', 'remote', 'department', 'employment_type',
                'salary_text', 'posted_at', 'description', 'last_seen_at', 'active', 'updated_at',
            ]);

            $ids = JobPosting::query()
                ->where('board_id', $board->id)
                ->whereIn('feed_id', array_map(strval(...), array_keys($chunk)))
                ->pluck('id', 'feed_id');

            JobPostingLocation::query()->whereIn('job_posting_id', $ids->values())->delete();

            $locations = [];
            foreach ($chunk as $feedId => $posting) {
                foreach ($posting->locations as $location) {
                    $locations[] = [
                        'job_posting_id' => $ids[(string) $feedId],
                        'city' => $location->city === null ? null : mb_substr($location->city, 0, 191),
                        'country' => $location->country,
                    ];
                }
            }

            foreach (array_chunk($locations, self::CHUNK) as $locationChunk) {
                JobPostingLocation::query()->insert($locationChunk);
            }
        }
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
