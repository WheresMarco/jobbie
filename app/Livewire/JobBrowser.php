<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Search\JobFilters;
use App\Search\JobSearch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Locale;

/**
 * The job list on /. All filter state lives in the URL.
 */
final class JobBrowser extends Component
{
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
        $this->reset('q', 'country', 'city', 'remote', 'department', 'published', 'new', 'kind', 'limit', 'open');
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
        );
    }

    public static function countryName(string $code): string
    {
        $name = Locale::getDisplayRegion("-{$code}", app()->getLocale());

        return $name === false || $name === '' ? $code : $name;
    }

    public function render(JobSearch $search): View
    {
        $filters = $this->filters();
        $grouped = $this->view !== 'flat';
        $result = $search->search($filters, $grouped, $this->limit, $this->open);

        return view('livewire.job-browser', [
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
