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
     * Changing a filter starts over from the first page.
     */
    public function updated(string $property): void
    {
        if ($property !== 'limit') {
            $this->limit = self::PAGE_SIZE;
        }
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function clearFilters(): void
    {
        $this->reset('q', 'country', 'city', 'remote', 'department', 'published', 'new', 'kind', 'limit');
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

        $groups = $grouped ? $search->groups($filters, $this->limit + 1) : [];
        $postings = $grouped ? [] : $search->postings($filters, $this->limit + 1);
        $hasMore = count($grouped ? $groups : $postings) > $this->limit;

        return view('livewire.job-browser', [
            'filters' => $filters,
            'terms' => $filters->terms(),
            'total' => $search->count($filters),
            'facets' => $search->facets($filters),
            'grouped' => $grouped,
            'groups' => array_slice($groups, 0, $this->limit),
            'postings' => array_slice($postings, 0, $this->limit),
            'hasMore' => $hasMore,
        ]);
    }
}
