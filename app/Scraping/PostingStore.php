<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Models\Board;
use App\Models\JobPosting;
use App\Models\JobPostingLocation;
use Illuminate\Support\Carbon;

/**
 * Writes postings for a board: upsert on (board_id, feed_id), keeping
 * first_seen_at, and replacing their locations. Shared by every source.
 */
final readonly class PostingStore
{
    private const int CHUNK = 500;

    /** Postings are listed when located in this country, or nowhere at all. */
    public const string COUNTRY = 'SE';

    /**
     * @param  list<string>  $countries  ISO codes of the posting's locations.
     */
    public static function inSweden(array $countries): bool
    {
        return $countries === [] || in_array(self::COUNTRY, $countries, true);
    }

    /**
     * @param  array<string, JobPostingData>  $postings  Keyed by feed id.
     */
    public function upsert(Board $board, array $postings, Carbon $now): void
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
                'org_nr' => $p->orgNr,
                'in_sweden' => self::inSweden($p->countries()),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($chunk));

            JobPosting::query()->upsert($rows, ['board_id', 'feed_id'], [
                'title', 'url', 'company', 'cities', 'countries', 'remote', 'department', 'employment_type',
                'salary_text', 'posted_at', 'description', 'org_nr', 'in_sweden', 'last_seen_at', 'active', 'updated_at',
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

}
