<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Jobs\ScoreProfile;
use App\Matching\ProfileScorer;
use App\Models\Profile;
use Illuminate\Console\Command;

final class RadarMatchProfiles extends Command
{
    use Translates;

    protected $signature = 'radar:match-profiles
        {--sync : Score in this process instead of on the queue}';

    protected $description = 'Score all active job postings against every user profile';

    public function handle(ProfileScorer $scorer): int
    {
        $profiles = Profile::query()->orderBy('id')->get();

        foreach ($profiles as $profile) {
            if ($this->option('sync') === true) {
                $matching = $scorer->score($profile);
                $this->components->twoColumnDetail((string) $profile->user_id, $this->t('radar.match_profiles.matching', ['count' => $matching]));
            } else {
                ScoreProfile::dispatch($profile);
            }
        }

        $this->components->info($this->t(
            $this->option('sync') === true ? 'radar.match_profiles.done' : 'radar.match_profiles.dispatched',
            ['count' => $profiles->count()],
        ));

        return self::SUCCESS;
    }
}
