<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Enums\ResumeStatus;
use App\Livewire\ResumeMatches;
use App\Matching\MatchingException;
use App\Matching\ResumeMatcher;
use App\Models\Resume;
use App\Models\ResumeMatch;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * CV matching from the terminal, without the queue: the same steps as
 * /matcha, for trying CVs and prompts.
 */
final class RadarMatch extends Command
{
    use Translates;

    protected $signature = 'radar:match
        {email : The account the CV belongs to}
        {cv? : A PDF file. Without it, the account\'s current profile is matched again}
        {--preferences= : Preferences in your own words, such as "Linköping or remote"}
        {--top=15 : Matches to list}';

    protected $description = 'Match a CV against the postings with Claude and list the best matches';

    public function handle(): int
    {
        if (! ResumeMatches::enabled()) {
            $this->components->error($this->t('radar.match.disabled'));

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->firstWhere('email', $email);
        if ($user === null) {
            $this->components->error($this->t('radar.match.no_user', ['email' => $email]));

            return self::INVALID;
        }

        $matcher = app(ResumeMatcher::class);
        $path = $this->argument('cv');
        $preferences = $this->option('preferences');

        try {
            if (is_string($path)) {
                if (! is_file($path) || ! is_readable($path)) {
                    $this->components->error($this->t('radar.match.no_file', ['path' => $path]));

                    return self::INVALID;
                }

                $resume = $matcher->prepare($user, basename($path), is_string($preferences) ? $preferences : null);
                $this->components->task($this->t('radar.match.reading'), static fn () => $matcher->analyze($resume, (string) file_get_contents($path)));
            } else {
                $resume = $user->resume()->first();
                if ($resume === null || $resume->profile === null) {
                    $this->components->error($this->t('radar.match.no_resume', ['email' => $email]));

                    return self::INVALID;
                }

                if (is_string($preferences)) {
                    $resume->preferences = ResumeMatcher::clean($preferences);
                    $matcher->restart($resume, ResumeStatus::Analyzing, keepMatches: false);
                    $this->components->task($this->t('radar.match.revising'), static fn () => $matcher->analyze($resume));
                } else {
                    $matcher->restart($resume, ResumeStatus::Matching, keepMatches: true);
                }
            }
        } catch (MatchingException $e) {
            $this->components->error($this->t('radar.match.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        $resume->refresh();
        $profile = $resume->profile();
        if ($profile === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail($this->t('radar.match.profile'), $profile->headline);
        $this->components->twoColumnDetail($this->t('radar.match.countries'), implode(', ', $profile->countries));
        $this->components->twoColumnDetail($this->t('radar.match.title_terms'), implode(', ', $profile->titleTerms));
        $this->components->twoColumnDetail($this->t('radar.match.keywords'), implode(', ', $profile->keywords));

        $chunks = $matcher->plan($resume);
        $this->components->info($this->t('radar.match.candidates', ['count' => array_sum(array_map(count(...), $chunks))]));

        $failed = 0;
        $bar = $this->output->createProgressBar(count($chunks));
        foreach ($chunks as $chunk) {
            try {
                $matcher->score($resume, $chunk);
            } catch (MatchingException $e) {
                $failed++;
                $this->newLine();
                $this->components->warn($this->t('radar.match.chunk_failed', ['error' => $e->getMessage()]));
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $matcher->finish($resume->id, $resume->run, $chunks !== [] && $failed === count($chunks));
        $this->table(
            [$this->t('radar.match.score'), $this->t('radar.match.posting'), $this->t('radar.match.reason')],
            $this->top($resume, max(1, (int) $this->option('top'))),
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<list<string>>
     */
    private function top(Resume $resume, int $count): array
    {
        return $resume->matches()
            ->whereHas('jobPosting', static fn ($q) => $q->where('active', true))
            ->with('jobPosting.board')
            ->orderByDesc('score')
            ->limit($count)
            ->get()
            ->map(static fn (ResumeMatch $m): array => [
                (string) $m->score,
                $m->jobPosting === null ? '' : wordwrap("{$m->jobPosting->title}\n{$m->jobPosting->employerName()}, ".implode(', ', $m->jobPosting->cities), 50),
                wordwrap($m->reason, 60),
            ])
            ->values()
            ->all();
    }
}
