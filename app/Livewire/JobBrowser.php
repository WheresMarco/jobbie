<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\MarksPostings;
use App\Matching\Criteria;
use App\Matching\Matcher;
use App\Matching\MatchResult;
use App\Matching\ProfileScorer;
use App\Models\JobPosting;
use App\Models\User;
use App\Search\JobFilters;
use App\Search\JobSearch;
use App\Tracking\PostingTracker;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Locale;

/**
 * The job list on /. All filter state lives in the URL.
 */
final class JobBrowser extends Component
{
    use MarksPostings;

    public const int PAGE_SIZE = 25;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $country = '';

    #[Url(except: '')]
    public string $city = '';

    /** @var list<string> */
    #[Url(except: [])]
    public array $remote = [];

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $published = '';

    #[Url(except: false)]
    public bool $new = false;

    #[Url(except: '')]
    public string $kind = '';

    /** Only postings not found in Platsbanken (Phase 9). */
    #[Url(except: false)]
    public bool $missing = false;

    /** Only postings the viewer has not seen. */
    #[Url(except: false)]
    public bool $unseen = false;

    /** Only postings matching the viewer's profile. */
    #[Url(except: false)]
    public bool $matching = false;

    /** "" sorts by best match when the viewer has a profile, "newest" by date. */
    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'grouped')]
    public string $view = 'grouped';

    public int $limit = self::PAGE_SIZE;

    /**
     * Employer groups the viewer has opened. Null until the first toggle,
     * which means only the first group is open.
     *
     * @var list<string>|null
     */
    public ?array $open = null;

    /**
     * Changing a filter starts over from the first page.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['limit', 'open'], true)) {
            $this->limit = self::PAGE_SIZE;
            $this->open = null;
        }
    }

    /**
     * Open or close an employer group; its postings load when it opens.
     *
     * @param  array<mixed>  $currentlyOpen  The groups open on screen, so the first group's default state is kept.
     */
    public function toggleGroup(string $employer, array $currentlyOpen): void
    {
        $open = array_values(array_filter($currentlyOpen, is_string(...)));
        $this->open = in_array($employer, $open, true)
            ? array_values(array_diff($open, [$employer]))
            : [...$open, $employer];
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'country', 'city', 'remote', 'department', 'published', 'new', 'kind', 'missing', 'unseen', 'matching', 'limit', 'open');
    }

    /**
     * Everything discovered so far counts as seen, whatever the filters.
     */
    public function markAllSeen(): void
    {
        app(PostingTracker::class)->markAllSeen($this->currentUser());
        $this->dispatch(UnseenCounter::MARKS_CHANGED);
    }

    public function filters(): JobFilters
    {
        return JobFilters::fromInput(
            query: $this->q,
            country: $this->country,
            city: $this->city,
            remote: $this->remote,
            department: $this->department,
            published: $this->published,
            newOnly: $this->new,
            kind: $this->kind,
            notInPlatsbanken: $this->missing,
            unseenOnly: $this->unseen,
            matchingOnly: $this->matching,
        );
    }

    private static function explain(Matcher $matcher, Criteria $criteria, JobPosting $posting): MatchResult
    {
        return $matcher->match(
            $criteria,
            $posting->title,
            $posting->department,
            $posting->description,
            $posting->cities,
            $posting->remote?->value,
        );
    }

    public static function countryName(string $code): string
    {
        $name = Locale::getDisplayRegion("-{$code}", app()->getLocale());

        return $name === false || $name === '' ? $code : $name;
    }

    public function render(JobSearch $search, PostingTracker $tracker, ProfileScorer $scorer, Matcher $matcher): View
    {
        $user = auth()->user();
        $marks = $user instanceof User ? $tracker->marks($user) : null;
        $profile = $user instanceof User ? $user->profile : null;
        $matches = $profile === null ? null : $scorer->scores($profile);
        $byMatch = $matches !== null && $this->sort !== 'newest';

        $filters = $this->filters();
        $grouped = $this->view !== 'flat';
        $result = $search->search($filters, $grouped, $this->limit, $this->open, $marks, $matches, $byMatch);

        // Why each shown posting matches, against the profile as it is now.
        $matchResults = [];
        if ($profile !== null) {
            $criteria = Criteria::fromProfile($profile);
            $shown = $grouped ? array_merge([], ...array_map(static fn ($g): array => $g->postings, $result->groups)) : $result->postings;
            foreach ($shown as $posting) {
                $matchResults[$posting->id] = self::explain($matcher, $criteria, $posting);
            }
        }

        return view('livewire.job-browser', [
            'profile' => $profile,
            'byMatch' => $byMatch,
            'matchResults' => $matchResults,
            'matchThreshold' => $scorer->threshold(),
            'userMarks' => $marks,
            'filters' => $filters,
            'terms' => $filters->terms(),
            'total' => $result->total,
            'facets' => $result->facets,
            'grouped' => $grouped,
            'groups' => $result->groups,
            'postings' => $result->postings,
            'hasMore' => $result->hasMore,
        ]);
    }
}
