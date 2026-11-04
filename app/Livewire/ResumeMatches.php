<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\MarksPostings;
use App\Matching\Contracts\LanguageModel;
use App\Matching\ResumeMatcher;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeMatch;
use App\Tracking\PostingTracker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Upload a CV on /matcha and see the postings Claude finds fit it best.
 * Hidden postings and employers are left out, as in the job list.
 */
final class ResumeMatches extends Component
{
    use MarksPostings;
    use WithFileUploads;

    public const int PAGE_SIZE = 25;

    public const int MAX_PREFERENCES = 1000;

    public ?TemporaryUploadedFile $cv = null;

    public string $preferences = '';

    /** Also show matches below the minimum score. */
    #[Url(except: false)]
    public bool $all = false;

    public int $limit = self::PAGE_SIZE;

    public function mount(): void
    {
        $this->preferences = $this->resume()->preferences ?? '';
    }

    public static function enabled(): bool
    {
        return app()->bound(LanguageModel::class);
    }

    public function updated(string $property): void
    {
        if ($property === 'all') {
            $this->limit = self::PAGE_SIZE;
        }
    }

    public function upload(ResumeMatcher $matcher): void
    {
        abort_unless(self::enabled(), 404);

        $this->validate(
            [
                'cv' => 'required|file|mimes:pdf|max:'.config()->integer('radar.matching.max_upload_kb'),
                'preferences' => 'nullable|string|max:'.self::MAX_PREFERENCES,
            ],
            [
                'cv.required' => __('ui.match.cv_required'),
                'cv.mimes' => __('ui.match.cv_pdf'),
                'cv.max' => __('ui.match.cv_too_large', ['mb' => intdiv(config()->integer('radar.matching.max_upload_kb'), 1024)]),
                'preferences.max' => __('ui.match.preferences_too_long', ['max' => self::MAX_PREFERENCES]),
            ],
        );

        if (! $this->takeRun('cv') || $this->cv === null) {
            return;
        }

        $matcher->upload($this->currentUser(), (string) $this->cv->get(), $this->cv->getClientOriginalName(), $this->preferences);
        $this->cv->delete();
        $this->reset('cv', 'limit');
    }

    public function savePreferences(ResumeMatcher $matcher): void
    {
        $resume = $this->resume();
        abort_unless(self::enabled() && $resume !== null && ! $resume->status->busy(), 404);

        $this->validate(
            ['preferences' => 'nullable|string|max:'.self::MAX_PREFERENCES],
            ['preferences.max' => __('ui.match.preferences_too_long', ['max' => self::MAX_PREFERENCES])],
        );

        if (trim($this->preferences) === trim((string) $resume->preferences) || ! $this->takeRun('preferences')) {
            return;
        }

        $matcher->changePreferences($resume, $this->preferences);
        $this->reset('limit');
    }

    public function searchAgain(ResumeMatcher $matcher): void
    {
        $resume = $this->resume();
        abort_unless(self::enabled() && $resume !== null && $resume->profile !== null && ! $resume->status->busy(), 404);

        if ($this->takeRun('run')) {
            $matcher->refresh($resume);
        }
    }

    public function remove(ResumeMatcher $matcher): void
    {
        $resume = $this->resume();
        if ($resume !== null) {
            $matcher->remove($resume);
        }
        $this->reset('preferences', 'limit');
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function render(PostingTracker $tracker): View
    {
        $resume = $this->resume();
        $marks = $tracker->marks($this->currentUser());
        $minScore = config()->integer('radar.matching.min_score');

        // A few hundred at most, so hidden ones are filtered here.
        $matches = $resume === null ? collect() : ResumeMatch::query()
            ->where('resume_id', $resume->id)
            ->whereHas('jobPosting', static fn ($q) => $q->where('active', true))
            ->with('jobPosting.board')
            ->orderByDesc('score')
            ->orderByDesc('job_posting_id')
            ->get()
            ->filter(static function (ResumeMatch $match) use ($marks): bool {
                $posting = $match->jobPosting;

                return $posting instanceof JobPosting
                    && ! isset($marks->hiddenIds[$posting->id])
                    && ! $marks->isEmployerHidden($posting->employerName());
            })
            ->values();

        $strong = $matches->filter(static fn (ResumeMatch $m): bool => $m->score >= $minScore)->values();
        $shown = $this->all ? $matches : $strong;

        return view('livewire.resume-matches', [
            'enabled' => self::enabled(),
            'resume' => $resume,
            'profile' => $resume?->profile(),
            'matches' => $shown->take($this->limit)->all(),
            'hasMore' => $shown->count() > $this->limit,
            'strongCount' => $strong->count(),
            'totalCount' => $matches->count(),
            'minScore' => $minScore,
            'userMarks' => $marks,
            'maxUploadMb' => intdiv(config()->integer('radar.matching.max_upload_kb'), 1024),
        ])->title(__('ui.nav.match'));
    }

    private function resume(): ?Resume
    {
        return $this->currentUser()->resume()->first();
    }

    /**
     * Each run costs money, so users get a few a day.
     */
    private function takeRun(string $errorKey): bool
    {
        $key = 'resume-runs:'.$this->currentUser()->id;
        $max = config()->integer('radar.matching.daily_runs');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->addError($errorKey, __('ui.match.throttled', [
                'max' => $max,
                'hours' => max(1, (int) ceil(RateLimiter::availableIn($key) / 3600)),
            ]));

            return false;
        }

        RateLimiter::hit($key, 24 * 3600);

        return true;
    }
}
