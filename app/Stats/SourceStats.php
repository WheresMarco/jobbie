<?php

declare(strict_types=1);

namespace App\Stats;

use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;

final readonly class SourceStats
{
    /**
     * @param  array<string, int>  $stages  CandidateStage value => candidates
     */
    public function __construct(
        public CandidateSourceType $source,
        public array $stages,
        public int $activeBoards,
        public int $postings,
    ) {
    }

    public function total(): int
    {
        return array_sum($this->stages);
    }

    /**
     * Candidates that got a verdict (everything but "new").
     */
    public function tested(): int
    {
        return $this->total() - ($this->stages[CandidateStage::New->value] ?? 0);
    }

    public function verified(): int
    {
        return $this->stages[CandidateStage::Verified->value] ?? 0;
    }
}
