<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Matching\MatchingException;
use App\Matching\ResumeMatcher;
use App\Models\Resume;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Has Claude read a CV (or revise its profile for new preferences), then
 * queues the scoring.
 */
final class AnalyzeResume implements ShouldQueue
{
    use Queueable;

    /** The SDK already retries rate limits and server errors. */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $resumeId, public int $run)
    {
    }

    public function handle(ResumeMatcher $matcher): void
    {
        $resume = Resume::query()->find($this->resumeId);
        if ($resume === null || $resume->run !== $this->run) {
            return;
        }

        try {
            $matcher->analyze($resume);
        } catch (MatchingException $e) {
            Log::warning('CV analysis failed', ['resume' => $this->resumeId, 'error' => $e->getMessage()]);
            $matcher->fail($this->resumeId, $this->run, __($e->isNotACv() ? 'ui.match.error.not_cv' : 'ui.match.error.reading'));

            return;
        }

        $resume->refresh();
        if ($resume->run === $this->run) {
            $matcher->dispatchScoring($resume);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(ResumeMatcher::class)->fail($this->resumeId, $this->run, __('ui.match.error.reading'));
    }
}
