<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Models\Board;
use App\Models\JobPosting;
use App\Models\JobPostingLocation;
use Illuminate\Support\Carbon;

/**
 * Stores postings for a board, whatever platform they were read from.
 * Postings are upserted on (board_id, feed_id), so first_seen_at survives
 * and writing the same postings twice is harmless.
 */
final class PostingWriter
{
    private const int CHUNK = 500;

    /**
     * @param  array<string, JobPostingData>  $postings  Keyed by feed id.
     * @param  array<string, mixed>  $extra  Further columns written on every row.
     */
    public function write(Board $board, array $postings, Carbon $now, array $extra = []): void
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
                ...$extra,
            ], array_values($chunk));

            JobPosting::query()->upsert($rows, ['board_id', 'feed_id'], [
                'title', 'url', 'company', 'cities', 'countries', 'remote', 'department', 'employment_type',
                'salary_text', 'posted_at', 'description', 'last_seen_at', 'active', 'updated_at',
                ...array_keys($extra),
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
     * Close the board's active postings that are not among the given feed
     * ids. Only call this after a complete read.
     *
     * @param  list<string>  $feedIds
     */
    public function deactivateMissing(Board $board, array $feedIds, Carbon $now): int
    {
        $query = $board->jobPostings()->where('active', true);

        // A long NOT IN list is slow and may exceed placeholder limits.
        if (count($feedIds) <= self::CHUNK) {
            return $query->whereNotIn('feed_id', $feedIds)->update(['active' => false, 'updated_at' => $now]);
        }

        $keep = array_flip($feedIds);
        $missing = array_values(array_filter(
            $query->pluck('feed_id')->map(strval(...))->all(),
            static fn (string $id): bool => ! isset($keep[$id]),
        ));

        return $this->deactivate($board, $missing, $now);
    }

    /**
     * @param  list<string>  $feedIds
     */
    public function deactivate(Board $board, array $feedIds, Carbon $now): int
    {
        $closed = 0;
        foreach (array_chunk($feedIds, self::CHUNK) as $chunk) {
            $closed += $board->jobPostings()->where('active', true)->whereIn('feed_id', $chunk)->update(['active' => false, 'updated_at' => $now]);
        }

        return $closed;
    }
}
