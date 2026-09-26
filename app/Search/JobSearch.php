<?php

declare(strict_types=1);

namespace App\Search;

use App\Enums\RemoteStatus;
use App\Models\JobPosting;
use App\Models\JobPostingLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Queries behind the job browser: filtered postings, employer groups and
 * facet counts. Free text uses the MySQL FULLTEXT index in boolean mode
 * (+word* per word); other databases fall back to LIKE.
 */
final readonly class JobSearch
{
    public const int POSTINGS_PER_GROUP = 20;

    public const int FACET_LIMIT = 30;

    public const int FACET_CACHE_SECONDS = 60;

    /** InnoDB's default innodb_ft_min_token_size; shorter words use LIKE. */
    private const int FULLTEXT_MIN_LENGTH = 3;

    /** Employer name: the posting's company, else the career site's name or host. */
    private const string EMPLOYER = 'coalesce(job_postings.company, boards.name, boards.canonical_host)';

    public function __construct(private int $newJobDays)
    {
    }

    /**
     * Active postings matching the filters.
     *
     * @return Builder<JobPosting>
     */
    public function query(JobFilters $filters): Builder
    {
        $query = JobPosting::query()->where('job_postings.active', true);

        $this->applyTerms($query, $filters->terms());

        if ($filters->country !== null) {
            $query->whereIn('job_postings.id', $this->locationIds('country', $filters->country));
        }

        if ($filters->city !== null) {
            $query->whereIn('job_postings.id', $this->locationIds('city', $filters->city));
        }

        if ($filters->remote !== []) {
            $query->where(function (Builder $q) use ($filters): void {
                foreach ($filters->remote as $option) {
                    match ($option) {
                        'fully' => $q->orWhere('job_postings.remote', RemoteStatus::Fully->value),
                        'hybrid' => $q->orWhere('job_postings.remote', RemoteStatus::Hybrid->value),
                        // Teamtailor's "none" means no remote work, which is on site.
                        'onsite' => $q->orWhereIn('job_postings.remote', [RemoteStatus::Onsite->value, RemoteStatus::None->value]),
                        default => $q->orWhereNull('job_postings.remote'),
                    };
                }
            });
        }

        if ($filters->department !== null) {
            $query->where('job_postings.department', $filters->department);
        }

        if ($filters->publishedDays !== null) {
            $query->where('job_postings.posted_at', '>=', Carbon::now()->subDays($filters->publishedDays));
        }

        if ($filters->newOnly) {
            $query->where('job_postings.first_seen_at', '>=', $this->newSince());
        }

        if ($filters->kind !== null) {
            $query->whereIn('job_postings.board_id', DB::table('boards')->select('id')->where('kind', $filters->kind->value));
        }

        return $query;
    }

    public function count(JobFilters $filters): int
    {
        return $this->query($filters)->count();
    }

    /**
     * Newest postings first.
     *
     * @return list<JobPosting>
     */
    public function postings(JobFilters $filters, int $limit): array
    {
        return array_values($this->query($filters)
            ->with('board')
            ->orderByDesc('job_postings.posted_at')
            ->orderByDesc('job_postings.id')
            ->limit($limit)
            ->get()
            ->all());
    }

    /**
     * Employers with the most matching postings first.
     *
     * @return list<EmployerGroup>
     */
    public function groups(JobFilters $filters, int $limit): array
    {
        $rows = $this->query($filters)->toBase()
            ->join('boards', 'boards.id', '=', 'job_postings.board_id')
            ->selectRaw(self::EMPLOYER.' as employer')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when job_postings.remote = ? then 1 else 0 end) as remote_count', [RemoteStatus::Fully->value])
            ->groupBy('employer')
            ->orderByDesc('total')
            ->orderBy('employer')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $employers = $rows->pluck('employer')->map(strval(...))->all();

        $ranked = $this->query($filters)
            ->join('boards', 'boards.id', '=', 'job_postings.board_id')
            ->whereIn(DB::raw(self::EMPLOYER), $employers)
            ->select('job_postings.*', 'boards.canonical_host as board_host')
            ->selectRaw(self::EMPLOYER.' as employer')
            ->selectRaw('row_number() over (partition by '.self::EMPLOYER.' order by job_postings.posted_at desc, job_postings.id desc) as position');

        $postings = JobPosting::query()
            ->fromSub($ranked, 'job_postings')
            ->where('position', '<=', self::POSTINGS_PER_GROUP)
            ->orderBy('position')
            ->get()
            ->groupBy(fn (JobPosting $p): string => (string) $p->getAttribute('employer'));

        $groups = [];
        foreach ($rows as $row) {
            $employer = (string) $row->employer;
            /** @var list<JobPosting> $items */
            $items = array_values($postings->get($employer)?->all() ?? []);

            $cities = [];
            foreach ($items as $posting) {
                foreach ($posting->cities as $city) {
                    $cities[$city] = true;
                }
            }

            $host = $items === [] ? null : $items[0]->getAttribute('board_host');

            $groups[] = new EmployerGroup(
                employer: $employer,
                total: (int) $row->total,
                remote: (int) $row->remote_count,
                postings: $items,
                cities: array_keys($cities),
                siteUrl: is_string($host) ? "https://{$host}/" : null,
            );
        }

        return $groups;
    }

    /**
     * Option counts per facet, each ignoring its own filter. Cached for a
     * minute per filter combination.
     */
    public function facets(JobFilters $filters): Facets
    {
        return new Facets(
            countries: $this->cached($filters, 'country', fn (JobFilters $f): array => $this->locationFacet($f, 'country')),
            cities: $this->cached($filters, 'city', fn (JobFilters $f): array => $this->locationFacet($f, 'city')),
            remote: $this->cached($filters, 'remote', $this->remoteFacet(...)),
            departments: $this->cached($filters, 'department', $this->departmentFacet(...)),
            published: $this->cached($filters, 'published', $this->publishedFacet(...)),
            new: (int) (Cache::remember(
                'facets:new:'.$filters->without('new')->cacheKey(),
                self::FACET_CACHE_SECONDS,
                fn (): int => $this->query($filters->without('new'))->where('job_postings.first_seen_at', '>=', $this->newSince())->count(),
            )),
            kinds: $this->cached($filters, 'kind', $this->kindFacet(...)),
        );
    }

    /**
     * @param  callable(JobFilters): array<array-key, int>  $compute
     * @return array<array-key, int>
     */
    private function cached(JobFilters $filters, string $facet, callable $compute): array
    {
        $without = $filters->without($facet);

        /** @var array<array-key, int> $counts */
        $counts = Cache::remember("facets:{$facet}:".$without->cacheKey(), self::FACET_CACHE_SECONDS, fn (): array => $compute($without));

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function locationFacet(JobFilters $filters, string $column): array
    {
        $rows = JobPostingLocation::query()->toBase()
            ->whereIn('job_posting_id', $this->query($filters)->select('job_postings.id')->toBase())
            ->whereNotNull($column)
            ->select($column)
            ->selectRaw('count(distinct job_posting_id) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->limit(self::FACET_LIMIT)
            ->get();

        return $this->pairs($rows->all(), $column);
    }

    /**
     * @return array<string, int>
     */
    private function remoteFacet(JobFilters $filters): array
    {
        $counts = array_fill_keys(JobFilters::REMOTE_OPTIONS, 0);

        foreach ($this->query($filters)->toBase()->select('remote')->selectRaw('count(*) as total')->groupBy('remote')->get() as $row) {
            $option = match ($row->remote) {
                RemoteStatus::Fully->value => 'fully',
                RemoteStatus::Hybrid->value => 'hybrid',
                RemoteStatus::Onsite->value, RemoteStatus::None->value => 'onsite',
                default => 'unknown',
            };
            $counts[$option] += (int) $row->total;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function departmentFacet(JobFilters $filters): array
    {
        $rows = $this->query($filters)->toBase()
            ->whereNotNull('department')
            ->select('department')
            ->selectRaw('count(*) as total')
            ->groupBy('department')
            ->orderByDesc('total')
            ->orderBy('department')
            ->limit(self::FACET_LIMIT)
            ->get();

        return $this->pairs($rows->all(), 'department');
    }

    /**
     * @return array<int, int>
     */
    private function publishedFacet(JobFilters $filters): array
    {
        $counts = [];
        foreach (JobFilters::PUBLISHED_OPTIONS as $days) {
            $counts[$days] = $this->query($filters)->where('job_postings.posted_at', '>=', Carbon::now()->subDays($days))->count();
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function kindFacet(JobFilters $filters): array
    {
        $rows = $this->query($filters)->toBase()
            ->join('boards', 'boards.id', '=', 'job_postings.board_id')
            ->select('boards.kind')
            ->selectRaw('count(*) as total')
            ->groupBy('boards.kind')
            ->get();

        return $this->pairs($rows->all(), 'kind');
    }

    /**
     * @param  list<string>  $terms
     * @param  Builder<JobPosting>  $query
     */
    private function applyTerms(Builder $query, array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $long = array_values(array_filter($terms, static fn (string $t): bool => mb_strlen($t) >= self::FULLTEXT_MIN_LENGTH));
        $short = array_values(array_diff($terms, $long));

        if ($query->getConnection()->getDriverName() === 'mysql') {
            if ($long !== []) {
                $query->whereFullText(
                    ['job_postings.title', 'job_postings.company', 'job_postings.description'],
                    implode(' ', array_map(static fn (string $t): string => "+{$t}*", $long)),
                    ['mode' => 'boolean'],
                );
            }
        } else {
            $short = $terms;
        }

        foreach ($short as $term) {
            // Terms never contain % or _ (see JobFilters::terms()).
            $like = '%'.$term.'%';
            $query->where(fn (Builder $q) => $q
                ->where('job_postings.title', 'like', $like)
                ->orWhere('job_postings.company', 'like', $like)
                ->orWhere('job_postings.description', 'like', $like));
        }
    }

    private function locationIds(string $column, string $value): QueryBuilder
    {
        return DB::table('job_posting_locations')->select('job_posting_id')->where($column, $value);
    }

    private function newSince(): Carbon
    {
        return Carbon::now()->subDays($this->newJobDays);
    }

    /**
     * @param  array<mixed>  $rows
     * @return array<string, int>
     */
    private function pairs(array $rows, string $column): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $pairs[(string) $row->{$column}] = (int) $row->total;
            }
        }

        return $pairs;
    }
}
