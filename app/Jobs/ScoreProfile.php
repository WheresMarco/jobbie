<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Matching\ProfileScorer;
use App\Models\Profile;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores every active posting against a profile. Unique until it starts,
 * so saving a profile twice in a row scores once, but a save during a run
 * queues another with the new profile.
 */
final class ScoreProfile implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** A profile cleared before the job ran has nothing to score. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Profile $profile)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->profile->id;
    }

    public function handle(ProfileScorer $scorer): void
    {
        // The profile is read again when the job runs, so the latest save wins.
        $scorer->score($this->profile);
    }
}
