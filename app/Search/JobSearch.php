<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\Board;
use App\Models\JobPosting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything behind the job browser.
 *
 * Free text is matched in SQL (MySQL FULLTEXT in boolean mode with +word*,
 * LIKE for short words and on other databases). All other filters, the
 * facet counts and the employer groups are then computed in one pass over
 * a narrow row per matching posting. That is one scan instead of one query
 * per facet, and each facet can ignore its own filter for free. Only the
 * postings that are shown are loaded in full.
 */
final readonly class JobSearch
{
    public const int POSTINGS_PER_GROUP = 20;

    public const int FACET_LIMIT = 30;

    public const int CACHE_SECONDS = 60;

    /** InnoDB's default innodb_ft_min_token_size; shorter words use LIKE. */
    private const int FULLTEXT_MIN_LENGTH = 3;

    /** RemoteStatus value => filter option. Teamtailor's "none" means no remote work, which is on site. */
    private const array REMOTE_OPTIONS = ['fully' => 'fully', 'hybrid' => 'hybrid', 'onsite' => 'onsite', 'none' => 'onsite'];

    public function __construct(private int $newJobDays)
    {
    }

    /**
     * @param  list<string>|null  $openEmployers  Groups whose postings are loaded; null loads only the first group.
     */
    public function search(JobFilters $filters, bool $grouped, int $limit, ?array $openEmployers = null): SearchResult
    {
        $key = 'jobsearch:'.$filters->cacheKey().':'.($grouped ? 'g' : 'f').':'.$limit;

        /** @var array{total: int, facets: Facets, groups: list<array{employer: string, total: int, remote: int, cities: list<string>, ids: list<int>}>, ids: list<int>, hasMore: bool} $summary */
        $summary = Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->summarize($filters, $grouped, $limit));

        $open = $openEmployers ?? array_slice(array_column($summary['groups'], 'employer'), 0, 1);
        $openGroups = array_filter($summary['groups'], static fn (array $g): bool => in_array($g['employer'], $open, true));
        $ids = $grouped ? array_merge([], ...array_column($openGroups, 'ids')) : $summary['ids'];
        $postings = $ids === [] ? collect() : JobPosting::query()->with('board')->whereIn('id', $ids)->get()->keyBy('id');

        $groups = [];
        foreach ($summary['groups'] as $group) {
            $items = array_values(array_filter(array_map(static fn (int $id): ?JobPosting => $postings->get($id), $group['ids'])));
            $board = $items === [] ? null : $items[0]->getRelationValue('board');
            $host = $board instanceof Board ? $board->canonical_host : null;

            $groups[] = new EmployerGroup(
                employer: $group['employer'],
                total: $group['total'],
                remote: $group['remote'],
                postings: $items,
                cities: $group['cities'],
                siteUrl: $host === null ? null : "https://{$host}/",
                open: in_array($group['employer'], $open, true),
            );
        }

        return new SearchResult(
            total: $summary['total'],
            facets: $summary['facets'],
            groups: $groups,
            postings: $grouped ? [] : array_values(array_filter(array_map(static fn (int $id): ?JobPosting => $postings->get($id), $summary['ids']))),
            hasMore: $summary['hasMore'],
        );
    }

    /**
     * @return array{total: int, facets: Facets, groups: list<array{employer: string, total: int, remote: int, cities: list<string>, ids: list<int>}>, ids: list<int>, hasMore: bool}
     */
    private function summarize(JobFilters $filters, bool $grouped, int $limit): array
    {
        $now = Carbon::now()->utc();
        $newSince = $now->copy()->subDays($this->newJobDays)->format('Y-m-d H:i:s');
        $publishedSince = [];
        foreach (JobFilters::PUBLISHED_OPTIONS as $days) {
            $publishedSince[$days] = $now->copy()->subDays($days)->format('Y-m-d H:i:s');
        }
        $filterPublishedSince = $filters->publishedDays === null ? null : $publishedSince[$filters->publishedDays];

        $boardKind = $boardName = [];
        foreach (DB::table('boards')->select('id', 'kind', 'name', 'canonical_host')->get() as $board) {
            $boardKind[(int) $board->id] = (string) $board->kind;
            $boardName[(int) $board->id] = (string) ($board->name ?? $board->canonical_host);
        }

        $country = $filters->country;
        $city = $filters->city;
        $remoteFilter = $filters->remote === [] ? null : array_flip($filters->remote);
        $department = $filters->department;
        $newOnly = $filters->newOnly;
        $kind = $filters->kind?->value;

        $rows = $this->rows($filters)->get()->all();
        [$rowCountriesById, $rowCitiesById] = $this->locations($rows);

        $countries = $cities = $remotes = $departments = $published = $kinds = [];
        $newCount = $total = 0;
        // Per employer: postings, fully remote postings and row indexes.
        $employerTotal = $employerRemote = $employerRows = [];
        // Row index => posted_at, for the flat list.
        $flat = [];

        // Local copies: this loop runs once per posting.
        $remoteOptions = self::REMOTE_OPTIONS;

        foreach ($rows as $index => $row) {
            /** @var \stdClass $row */
            $id = $row->id;
            $boardId = $row->board_id;
            $rowKind = $boardKind[$boardId] ?? '';
            $rowCountries = $rowCountriesById[$id] ?? [];
            $rowCities = $rowCitiesById[$id] ?? [];
            $remote = $remoteOptions[$row->remote ?? ''] ?? 'unknown';
            $rowDepartment = $row->department;
            $postedAt = $row->posted_at ?? '';
            $isNew = $row->first_seen_at >= $newSince;

            // The one active filter this row fails, or null. Rows failing
            // two or more filters count nowhere.
            $failed = null;
            $failures = 0;
            if ($country !== null && ! isset($rowCountries[$country])) {
                $failed = 'country';
                $failures++;
            }
            if ($city !== null && ! isset($rowCities[$city])) {
                $failed = 'city';
                $failures++;
            }
            if ($remoteFilter !== null && ! isset($remoteFilter[$remote])) {
                $failed = 'remote';
                $failures++;
            }
            if ($department !== null && $rowDepartment !== $department) {
                $failed = 'department';
                $failures++;
            }
            if ($filterPublishedSince !== null && $postedAt < $filterPublishedSince) {
                $failed = 'published';
                $failures++;
            }
            if ($newOnly && ! $isNew) {
                $failed = 'new';
                $failures++;
            }
            if ($kind !== null && $rowKind !== $kind) {
                $failed = 'kind';
                $failures++;
            }

            if ($failures > 1) {
                continue;
            }

            // Each facet counts rows that pass every filter except its own.
            if ($failed === null || $failed === 'country') {
                foreach ($rowCountries as $value => $_) {
                    $countries[$value] = ($countries[$value] ?? 0) + 1;
                }
            }
            if ($failed === null || $failed === 'city') {
                foreach ($rowCities as $value => $_) {
                    $cities[$value] = ($cities[$value] ?? 0) + 1;
                }
            }
            if ($failed === null || $failed === 'remote') {
                $remotes[$remote] = ($remotes[$remote] ?? 0) + 1;
            }
            if (($failed === null || $failed === 'department') && $rowDepartment !== null) {
                $departments[$rowDepartment] = ($departments[$rowDepartment] ?? 0) + 1;
            }
            if ($failed === null || $failed === 'published') {
                foreach ($publishedSince as $days => $since) {
                    if ($postedAt >= $since) {
                        $published[$days] = ($published[$days] ?? 0) + 1;
                    }
                }
            }
            if (($failed === null || $failed === 'new') && $isNew) {
                $newCount++;
            }
            if ($failed === null || $failed === 'kind') {
                $kinds[$rowKind] = ($kinds[$rowKind] ?? 0) + 1;
            }

            if ($failed !== null) {
                continue;
            }

            $total++;

            if ($grouped) {
                $employer = $row->company ?? ($boardName[$boardId] ?? '');
                $employerTotal[$employer] = ($employerTotal[$employer] ?? 0) + 1;
                if ($remote === 'fully') {
                    $employerRemote[$employer] = ($employerRemote[$employer] ?? 0) + 1;
                }
                $employerRows[$employer][] = $index;
            } else {
                $flat[$index] = $postedAt;
            }
        }

        $groups = [];
        $ids = [];
        $hasMore = false;

        if ($grouped) {
            // Largest first, then by name.
            uksort($employerTotal, static fn (int|string $a, int|string $b): int => [$employerTotal[$b], (string) $a] <=> [$employerTotal[$a], (string) $b]);
            $hasMore = count($employerTotal) > $limit;

            foreach (array_slice($employerTotal, 0, $limit, true) as $employer => $count) {
                $newest = self::newest($rows, $employerRows[$employer], self::POSTINGS_PER_GROUP);

                $groupCities = [];
                foreach ($employerRows[$employer] as $index) {
                    foreach ($rowCitiesById[(int) $rows[$index]->id] ?? [] as $value => $_) {
                        $groupCities[$value] = ($groupCities[$value] ?? 0) + 1;
                    }
                }

                $groups[] = [
                    'employer' => (string) $employer,
                    'total' => $count,
                    'remote' => $employerRemote[$employer] ?? 0,
                    'cities' => array_keys(self::top($groupCities)),
                    'ids' => $newest,
                ];
            }
        } else {
            $hasMore = count($flat) > $limit;
            // Stable sort: equal dates keep the scan order.
            arsort($flat, SORT_STRING);
            $ids = array_map(static fn (int $index): int => (int) $rows[$index]->id, array_slice(array_keys($flat), 0, $limit));
        }

        $publishedCounts = [];
        foreach (JobFilters::PUBLISHED_OPTIONS as $days) {
            $publishedCounts[$days] = $published[$days] ?? 0;
        }

        return [
            'total' => $total,
            'facets' => new Facets(
                countries: self::top($countries),
                cities: self::top($cities),
                remote: array_merge(array_fill_keys(JobFilters::REMOTE_OPTIONS, 0), $remotes),
                departments: self::top($departments),
                published: $publishedCounts,
                new: $newCount,
                kinds: $kinds,
            ),
            'groups' => $groups,
            'ids' => $ids,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Countries and cities per posting id, as sets.
     *
     * @param  array<int, \stdClass>  $rows
     * @return array{array<int, array<string, true>>, array<int, array<string, true>>}
     */
    private function locations(array $rows): array
    {
        $query = DB::table('job_posting_locations')->select('job_posting_id', 'city', 'country');

        // Reading every location is cheaper than a long IN list, unless the
        // free text left only a few postings.
        if (count($rows) <= 2000) {
            $query->whereIn('job_posting_id', array_map(static fn (\stdClass $row): int => (int) $row->id, $rows));
        }

        $countries = $cities = [];
        foreach ($query->get() as $location) {
            $id = (int) $location->job_posting_id;
            if ($location->country !== null) {
                $countries[$id][(string) $location->country] = true;
            }
            if ($location->city !== null) {
                $cities[$id][(string) $location->city] = true;
            }
        }

        return [$countries, $cities];
    }

    /**
     * One narrow row per active posting that matches the free text.
     */
    private function rows(JobFilters $filters): Builder
    {
        $query = DB::table('job_postings')
            ->where('job_postings.active', true)
            ->select([
                'job_postings.id', 'job_postings.board_id', 'job_postings.company', 'job_postings.remote',
                'job_postings.department', 'job_postings.posted_at', 'job_postings.first_seen_at',
            ])
        ;

        $terms = $filters->terms();
        if ($terms === []) {
            // MySQL prefers the clustered index; the covering index avoids
            // reading the wide rows with descriptions.
            if (DB::connection()->getDriverName() === 'mysql') {
                $query->forceIndex('job_postings_search_index');
            }

            return $query;
        }

        $long = array_values(array_filter($terms, static fn (string $t): bool => mb_strlen($t) >= self::FULLTEXT_MIN_LENGTH));
        $like = array_values(array_diff($terms, $long));

        if (DB::connection()->getDriverName() === 'mysql') {
            if ($long !== []) {
                $query->whereFullText(
                    ['job_postings.title', 'job_postings.company', 'job_postings.description'],
                    implode(' ', array_map(static fn (string $t): string => "+{$t}*", $long)),
                    ['mode' => 'boolean'],
                );
            }
        } else {
            $like = $terms;
        }

        foreach ($like as $term) {
            // Terms never contain % or _ (see JobFilters::terms()).
            $pattern = '%'.$term.'%';
            $query->where(fn (Builder $q) => $q
                ->where('job_postings.title', 'like', $pattern)
                ->orWhere('job_postings.company', 'like', $pattern)
                ->orWhere('job_postings.description', 'like', $pattern));
        }

        return $query;
    }

    /**
     * Ids of the newest postings among the given rows: posted_at, then id.
     *
     * @param  array<int, \stdClass>  $rows
     * @param  list<int>  $indexes
     * @return list<int>
     */
    private static function newest(array $rows, array $indexes, int $count): array
    {
        $keys = [];
        foreach ($indexes as $index) {
            $row = $rows[$index];
            $keys[] = $row->posted_at.str_pad((string) $row->id, 12, '0', STR_PAD_LEFT);
        }

        rsort($keys, SORT_STRING);

        return array_map(static fn (string $key): int => (int) substr($key, -12), array_slice($keys, 0, $count));
    }

    /**
     * The FACET_LIMIT largest counts, ties by name.
     *
     * @param  array<array-key, int>  $counts
     * @return array<string, int>
     */
    private static function top(array $counts): array
    {
        uksort($counts, static fn (int|string $a, int|string $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        $top = [];
        foreach (array_slice($counts, 0, self::FACET_LIMIT, true) as $key => $count) {
            $top[(string) $key] = $count;
        }

        return $top;
    }
}
