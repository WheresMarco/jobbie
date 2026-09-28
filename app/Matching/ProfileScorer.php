<?php

declare(strict_types=1);

namespace App\Matching;

use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scores every active posting against a profile and stores the scores, so
 * the job list can filter and sort on them without reading descriptions.
 */
final readonly class ProfileScorer
{
    private const int CHUNK = 1000;

    public function __construct(private Matcher $matcher, private int $threshold)
    {
    }

    public function threshold(): int
    {
        return $this->threshold;
    }

    public function scores(Profile $profile): MatchScores
    {
        return new MatchScores($profile->user_id, $profile->scores_version, $this->threshold);
    }

    /**
     * Replace the profile's scores. Returns how many postings match.
     */
    public function score(Profile $profile): int
    {
        $criteria = Criteria::fromProfile($profile);
        $rows = [];

        if (! $criteria->isEmpty()) {
            DB::table('job_postings')
                ->where('active', true)
                ->select('id', 'title', 'department', 'description', 'cities', 'remote')
                ->chunkById(self::CHUNK, function (Collection $postings) use ($criteria, $profile, &$rows): void {
                    /** @var \stdClass $posting */
                    foreach ($postings as $posting) {
                        $cities = json_decode((string) $posting->cities, true);
                        $result = $this->matcher->match(
                            $criteria,
                            (string) $posting->title,
                            $posting->department === null ? null : (string) $posting->department,
                            $posting->description === null ? null : (string) $posting->description,
                            is_array($cities) ? array_values(array_map(strval(...), $cities)) : [],
                            $posting->remote === null ? null : (string) $posting->remote,
                        );
                        if ($result->score > 0) {
                            $rows[] = ['user_id' => $profile->user_id, 'job_posting_id' => (int) $posting->id, 'score' => $result->score];
                        }
                    }
                });
        }

        DB::transaction(function () use ($profile, $rows): void {
            DB::table('job_posting_scores')->where('user_id', $profile->user_id)->delete();
            foreach (array_chunk($rows, self::CHUNK) as $batch) {
                DB::table('job_posting_scores')->insert($batch);
            }

            $profile->scored_at = Carbon::now();
            $profile->scores_version++;
            $profile->save();
        });

        return count(array_filter($rows, fn (array $row): bool => $row['score'] >= $this->threshold));
    }
}
