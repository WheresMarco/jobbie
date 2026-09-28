<?php

declare(strict_types=1);

namespace App\Matching;

use Illuminate\Support\Facades\DB;

/**
 * One user's stored scores, for the job list. The scores are only read
 * when the search is not cached.
 */
final class MatchScores
{
    /** @var array<int, int>|null */
    private ?array $scores = null;

    /**
     * @param  int  $threshold  Postings scoring at least this count as matching.
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $version,
        public readonly int $threshold,
    ) {
    }

    /**
     * Posting id => score. Postings without a row score 0.
     *
     * @return array<int, int>
     */
    public function all(): array
    {
        if ($this->scores === null) {
            $scores = [];
            foreach (DB::table('job_posting_scores')->where('user_id', $this->userId)->pluck('score', 'job_posting_id') as $id => $score) {
                $scores[(int) $id] = (int) $score;
            }
            $this->scores = $scores;
        }

        return $this->scores;
    }

    public function cacheKey(): string
    {
        return "m{$this->userId}v{$this->version}t{$this->threshold}";
    }
}
