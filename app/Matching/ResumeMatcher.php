<?php

declare(strict_types=1);

namespace App\Matching;

use App\Enums\ResumeStatus;
use App\Jobs\AnalyzeResume;
use App\Jobs\ScoreResumeMatches;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Tracking\PostingTracker;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Runs CV matching: Claude reads the CV into a profile (AnalyzeResume),
 * full-text search picks candidate postings, and Claude scores them in
 * chunks, in parallel on the queue (ScoreResumeMatches).
 *
 * Every upload, change of preferences and new search starts a new run.
 * Queued jobs carry their run and do nothing once a newer one has started.
 */
final readonly class ResumeMatcher
{
    public function __construct(
        private ProfileExtractor $extractor,
        private CandidateFinder $finder,
        private MatchScorer $scorer,
        private PostingTracker $tracker,
        private int $candidates,
        private int $chunkSize,
    ) {
    }

    /**
     * Replace the user's CV and start over, matches and all.
     */
    public function upload(User $user, string $pdf, string $filename, ?string $preferences): Resume
    {
        $resume = $this->prepare($user, $filename, $preferences);
        Storage::disk('local')->put($resume->path(), $pdf);
        AnalyzeResume::dispatch($resume->id, $resume->run);

        return $resume;
    }

    /**
     * A new run for a CV that is about to be read, with no profile or
     * matches yet.
     */
    public function prepare(User $user, string $filename, ?string $preferences): Resume
    {
        $resume = Resume::query()->firstOrNew(['user_id' => $user->id]);
        $resume->fill([
            'filename' => mb_substr($filename, 0, 255),
            'preferences' => self::clean($preferences),
            'profile' => null,
        ]);

        return $this->restart($resume, ResumeStatus::Analyzing, keepMatches: false);
    }

    /**
     * New preferences change the profile and every score, so the profile
     * is revised and all postings are scored again.
     */
    public function changePreferences(Resume $resume, ?string $preferences): void
    {
        $resume->preferences = self::clean($preferences);
        $this->restart($resume, ResumeStatus::Analyzing, keepMatches: false);
        AnalyzeResume::dispatch($resume->id, $resume->run);
    }

    /**
     * Search again with the same profile. Postings scored before keep their
     * scores and only new candidates are sent to Claude, unless the user
     * has edited the profile since: then everything is scored again.
     */
    public function refresh(Resume $resume): void
    {
        $this->restart($resume, ResumeStatus::Matching, keepMatches: ! $resume->edited);
        $this->dispatchScoring($resume);
    }

    /**
     * Add a role or skill to the profile: back again if it was Claude's and
     * the user removed it, else as the user's own. The matches stay as they
     * are until the user matches again.
     *
     * @param  'roles'|'skills'  $list
     */
    public function addToProfile(Resume $resume, string $list, string $value): void
    {
        $value = trim($value);
        $additions = $resume->additions();
        $removals = $resume->removals();

        if (Profile::contains($removals[$list], $value)) {
            $removals[$list] = self::without($removals[$list], $value);
        } else {
            $additions[$list][] = $value;
        }

        $resume->forceFill(['additions' => $additions, 'removals' => $removals, 'edited' => true])->save();
    }

    /**
     * Remove a role or skill from the profile, the user's own or Claude's.
     *
     * @param  'roles'|'skills'  $list
     */
    public function removeFromProfile(Resume $resume, string $list, string $value): void
    {
        $additions = $resume->additions();
        $removals = $resume->removals();

        if (Profile::contains($additions[$list], $value)) {
            $additions[$list] = self::without($additions[$list], $value);
        } elseif (Profile::contains($resume->extractedProfile()->{$list} ?? [], $value)) {
            $removals[$list][] = trim($value);
        } else {
            return;
        }

        $resume->forceFill(['additions' => $additions, 'removals' => $removals, 'edited' => true])->save();
    }

    public function remove(Resume $resume): void
    {
        Storage::disk('local')->delete($resume->path());
        $resume->delete();
    }

    /**
     * Read the uploaded CV, or revise the profile for new preferences when
     * there is no file. The file is deleted either way: only the profile
     * is kept.
     *
     * @throws MatchingException
     */
    public function analyze(Resume $resume, ?string $pdf = null): void
    {
        $disk = Storage::disk('local');

        try {
            $pdf ??= $disk->exists($resume->path()) ? (string) $disk->get($resume->path()) : null;
            $profile = match (true) {
                $pdf !== null => $this->extractor->extract($pdf, $resume->preferences),
                $resume->extractedProfile() !== null => $this->extractor->revise($resume->extractedProfile(), $resume->preferences),
                default => throw new MatchingException('There is neither a CV nor a profile to work from.'),
            };
        } finally {
            $disk->delete($resume->path());
        }

        if (! $this->isCurrent($resume)) {
            return;
        }

        $resume->profile = $profile->toArray();
        $resume->status = ResumeStatus::Matching;
        $resume->save();
    }

    /**
     * Pick the candidates that have no score yet and queue their scoring.
     */
    public function dispatchScoring(Resume $resume): void
    {
        $chunks = $this->plan($resume);
        if ($chunks === []) {
            $this->finish($resume->id, $resume->run);

            return;
        }

        $id = $resume->id;
        $run = $resume->run;
        Bus::batch(array_map(static fn (array $ids): ScoreResumeMatches => new ScoreResumeMatches($id, $run, $ids), $chunks))
            ->name("resume:{$id}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($id, $run): void {
                app(self::class)->finish($id, $run, $batch->failedJobs === $batch->totalJobs);
            })
            ->dispatch();
    }

    /**
     * The candidates without a score, in chunks for Claude, best first.
     *
     * @return list<list<int>>
     */
    public function plan(Resume $resume): array
    {
        $profile = $resume->profile();
        if ($profile === null) {
            return [];
        }

        $user = $resume->user()->firstOrFail();
        $scored = array_flip($resume->matches()->pluck('job_posting_id')->all());
        $ids = array_values(array_filter(
            $this->finder->find($profile, $this->tracker->marks($user), $this->candidates),
            static fn (int $id): bool => ! isset($scored[$id]),
        ));

        $resume->forceFill(['to_score' => count($ids), 'scored' => 0])->save();

        return array_chunk($ids, max(1, $this->chunkSize));
    }

    /**
     * Score one chunk and store the results.
     *
     * @param  list<int>  $postingIds
     *
     * @throws MatchingException
     */
    public function score(Resume $resume, array $postingIds): void
    {
        $profile = $resume->profile();
        if ($profile === null) {
            return;
        }

        $postings = JobPosting::query()->with('board')->whereKey($postingIds)->get()->all();
        $matches = $this->scorer->score($profile, $resume->preferences, $postings);

        // Claude takes a while; the user may have started over meanwhile.
        if (! $this->isCurrent($resume)) {
            return;
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($matches as $postingId => $match) {
            $rows[] = [
                'resume_id' => $resume->id,
                'job_posting_id' => $postingId,
                'score' => $match['score'],
                'reason' => $match['reason'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(static function () use ($rows, $resume, $postingIds): void {
            DB::table('resume_matches')->upsert($rows, ['resume_id', 'job_posting_id'], ['score', 'reason', 'updated_at']);
            Resume::query()->whereKey($resume->id)->where('run', $resume->run)->increment('scored', count($postingIds));
        });
    }

    /**
     * Done with a run. It failed only if nothing could be scored; matches
     * from earlier runs stay either way.
     */
    public function finish(int $resumeId, int $run, bool $failed = false): void
    {
        Resume::query()->whereKey($resumeId)->where('run', $run)->update([
            'status' => $failed ? ResumeStatus::Failed : ResumeStatus::Ready,
            'error' => $failed ? __('ui.match.error.scoring') : null,
            'matched_at' => Carbon::now(),
        ]);
    }

    /**
     * Stop a run with a message for the user.
     */
    public function fail(int $resumeId, int $run, string $error): void
    {
        Resume::query()->whereKey($resumeId)->where('run', $run)->update([
            'status' => ResumeStatus::Failed,
            'error' => $error,
        ]);
    }

    /**
     * Start a new run, which makes queued jobs from earlier runs stale.
     */
    public function restart(Resume $resume, ResumeStatus $status, bool $keepMatches): Resume
    {
        $resume->forceFill([
            'status' => $status,
            'error' => null,
            'run' => $resume->run + 1,
            'to_score' => 0,
            'scored' => 0,
        ])->save();

        if (! $keepMatches) {
            $resume->matches()->delete();
            $resume->matched_at = null;
            $resume->edited = false;
            $resume->save();
        }

        return $resume;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function without(array $values, string $value): array
    {
        return array_values(array_filter($values, static fn (string $v): bool => ! Profile::contains([$value], $v)));
    }

    private function isCurrent(Resume $resume): bool
    {
        $run = Resume::query()->whereKey($resume->id)->value('run');

        return $run !== null && (int) $run === $resume->run;
    }

    public static function clean(?string $preferences): ?string
    {
        $preferences = trim((string) $preferences);

        return $preferences === '' ? null : $preferences;
    }
}
