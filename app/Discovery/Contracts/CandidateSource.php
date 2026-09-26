<?php

declare(strict_types=1);

namespace App\Discovery\Contracts;

use App\Discovery\CandidateData;
use App\Enums\CandidateSourceType;
use Illuminate\Support\LazyCollection;

interface CandidateSource
{
    public function type(): CandidateSourceType;

    /**
     * Candidate hosts, lazily. Hosts need not be normalised or unique;
     * CollectCandidates takes care of that. A source may also write rows to
     * the domains table as a side effect.
     *
     * @return LazyCollection<int, CandidateData>
     */
    public function collect(): LazyCollection;
}
