<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\CandidateCollector;
use App\Discovery\SourceRegistry;
use App\Enums\CandidateSourceType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class CollectCandidates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct(public CandidateSourceType $source)
    {
    }

    public function uniqueId(): string
    {
        return $this->source->value;
    }

    public function handle(SourceRegistry $sources, CandidateCollector $collector): void
    {
        $counts = $collector->collect($sources->source($this->source));

        Log::info('Candidates collected', ['source' => $this->source->value, ...$counts]);
    }
}
