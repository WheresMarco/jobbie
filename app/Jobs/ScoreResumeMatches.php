<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Matching\ResumeMatcher;
use App\Models\Resume;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Has Claude score one chunk of candidate postings for a CV.
 */
final class ScoreResumeMatches implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 300;

    /**
     * @param  list<int>  $postingIds
     */
    public function __construct(public int $resumeId, public int $run, public array $postingIds)
    {
    }

    public function handle(ResumeMatcher $matcher): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $resume = Resume::query()->find($this->resumeId);
        if ($resume === null || $resume->run !== $this->run) {
            return;
        }

        $matcher->score($resume, $this->postingIds);
    }
}
