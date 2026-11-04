<?php

declare(strict_types=1);

namespace App\Platforms\Adapters;

use App\Enums\Platform;
use App\Enums\RemoteStatus;
use App\Models\Board;
use App\Platforms\BoardFeed;
use App\Platforms\BoardRef;
use App\Platforms\Concerns\RequestsFeeds;
use App\Platforms\Contracts\PlatformAdapter;
use App\Scraping\CountryCode;
use App\Scraping\FeedException;
use App\Scraping\JobFeedParser;
use App\Scraping\JobLocation;
use App\Scraping\JobPostingData;

/**
 * Workday career sites, <tenant>.wd<N>.myworkdayjobs.com/<site>, through
 * the JSON API the site itself uses (/wday/cxs/<tenant>/<site>/jobs, POST,
 * at most 20 postings per page; checked 3 November 2026 against hitachi
 * and boliden). Tenants are mostly multinationals, so the list is narrowed
 * to the configured country with the site's own country facet, and each
 * posting is fetched once more for its text, place and date.
 *
 * The external key is "<host>/<site>": one tenant can run several sites.
 */
final readonly class WorkdayAdapter implements PlatformAdapter
{
    use RequestsFeeds;

    public const int PAGE_SIZE = 20;

    public const int MAX_PAGES = 100;

    private const string HOST = '~^([a-z0-9-]+)\.wd\d+\.myworkdayjobs\.com$~';

    private const string LOCALE = '~^[a-z]{2}-[a-z]{2}$~i';

    /** Path segments that are part of the site's routes, not site names. */
    private const array RESERVED = ['wday', 'job', 'details', 'login', 'apply', 'userhome', 'introduceyourself'];

    public function __construct(
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
        private string $country,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Workday;
    }

    public function recognize(string $url): ?BoardRef
    {
        $host = self::host($url);
        if ($host === null || preg_match(self::HOST, $host) !== 1) {
            return null;
        }

        $segments = self::segments($url);
        if (isset($segments[0]) && preg_match(self::LOCALE, $segments[0]) === 1) {
            array_shift($segments);
        }

        $site = $segments[0] ?? null;
        if ($site === null || in_array(strtolower($site), self::RESERVED, true)) {
            return null;
        }

        return new BoardRef(Platform::Workday, "{$host}/{$site}", $host, "https://{$host}/{$site}");
    }

    public function jobKey(string $url): ?string
    {
        $host = self::host($url);
        if ($host === null || preg_match(self::HOST, $host, $m) !== 1) {
            return null;
        }

        // .../job/<place>/<title>_<requisition>[/apply...]: the last segment
        // before any application step identifies the posting.
        $segments = self::segments($url);
        $at = array_search('job', $segments, true);
        if ($at === false) {
            return null;
        }

        $tail = array_slice($segments, $at + 1);
        $apply = array_search('apply', $tail, true);
        $tail = $apply === false ? $tail : array_slice($tail, 0, $apply);
        $slug = end($tail);

        return $slug === false ? null : 'workday:'.$m[1].':'.strtolower($slug);
    }

    public function fetch(Board $board): BoardFeed
    {
        $host = $board->canonical_host;
        $tenant = explode('.', $host)[0];
        $site = substr($board->external_key, strlen($host) + 1);
        $api = "https://{$host}/wday/cxs/".rawurlencode($tenant).'/'.rawurlencode($site);
        $country = strtoupper($this->country);

        $first = $this->page($api, [], 0);
        $facet = self::countryFacet(self::arr($first['facets'] ?? null), $country);

        $applied = [];
        if ($facet !== null) {
            [$parameter, $valueId] = $facet;
            if ($valueId === null) {
                // The site lists its countries, and this is not one of them.
                return new BoardFeed(title: $board->name, homeUrl: "https://{$host}/{$site}", postings: []);
            }
            $applied = [$parameter => [$valueId]];
            $first = $this->page($api, $applied, 0);
        }

        [$listed, $truncated] = $this->listAll($api, $applied, $first);

        $details = $this->getJsonMany(array_map(static fn (string $path): string => $api.$path, array_map(
            static fn (array $item): string => (string) $item['externalPath'],
            $listed,
        )));

        /** @var array<string, JobPostingData> $postings */
        $postings = [];
        $name = null;
        foreach ($listed as $key => $item) {
            $posting = $this->posting($item, $details[$key] ?? null, $board, $site, filtered: $applied !== []);
            if ($posting !== null) {
                $postings[$posting->feedId] = $posting;
                $name ??= $posting->company;
            }
        }

        return new BoardFeed(
            title: $name ?? $board->name,
            homeUrl: "https://{$host}/{$site}",
            postings: $postings,
            truncated: $truncated,
        );
    }

    /**
     * @param  array<string, list<string>>  $applied
     * @return array<mixed>
     *
     * @throws FeedException
     */
    private function page(string $api, array $applied, int $offset): array
    {
        return $this->postJson("{$api}/jobs", [
            'appliedFacets' => (object) $applied,
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'searchText' => '',
        ]);
    }

    /**
     * Every listed posting, keyed by external path. Only the first page
     * carries the total.
     *
     * @param  array<string, list<string>>  $applied
     * @param  array<mixed>  $first
     * @return array{array<string, array{externalPath: string, title?: mixed, bulletFields?: mixed}>, bool}
     */
    private function listAll(string $api, array $applied, array $first): array
    {
        $total = is_int($first['total'] ?? null) ? $first['total'] : 0;
        $listed = [];
        $truncated = false;
        $json = $first;

        for ($page = 0; ; $page++) {
            foreach (self::arr($json['jobPostings'] ?? null) as $item) {
                $item = self::arr($item);
                $path = self::str($item['externalPath'] ?? null);
                if ($path !== null) {
                    $listed[$path] = ['externalPath' => $path, 'title' => $item['title'] ?? null, 'bulletFields' => $item['bulletFields'] ?? null];
                }
            }

            if (($page + 1) * self::PAGE_SIZE >= $total) {
                break;
            }
            if ($page + 1 >= self::MAX_PAGES) {
                $truncated = true;

                break;
            }

            try {
                $json = $this->page($api, $applied, ($page + 1) * self::PAGE_SIZE);
            } catch (FeedException) {
                $truncated = true;

                break;
            }
        }

        return [$listed, $truncated];
    }

    /**
     * A posting counts when any of its places is in the country: many are
     * advertised for several countries with Sweden as an extra location.
     *
     * @param  array{externalPath: string, title?: mixed, bulletFields?: mixed}  $item
     * @param  array<mixed>|null  $detail
     * @param  bool  $filtered  Whether the list was narrowed to the country, so postings without a known place still count.
     */
    private function posting(array $item, ?array $detail, Board $board, string $site, bool $filtered): ?JobPostingData
    {
        $info = self::arr($detail['jobPostingInfo'] ?? null);
        $target = strtoupper($this->country);
        $locations = self::locations($info);
        $countries = array_filter(array_map(static fn (JobLocation $l): ?string => $l->country, $locations));

        if ($countries === [] ? ! $filtered : ! in_array($target, $countries, true)) {
            return null;
        }

        $title = self::str($info['title'] ?? null) ?? self::str($item['title'] ?? null);
        $bullets = self::arr($item['bulletFields'] ?? null);
        $feedId = self::str($info['jobReqId'] ?? null) ?? self::str($bullets[0] ?? null) ?? basename($item['externalPath']);
        if ($title === null) {
            return null;
        }

        $remoteType = strtolower((string) self::str($info['remoteType'] ?? null));

        return new JobPostingData(
            feedId: mb_substr($feedId, 0, 100),
            title: $title,
            url: self::str($info['externalUrl'] ?? null) ?? "https://{$board->canonical_host}/{$site}{$item['externalPath']}",
            company: self::str(self::arr($detail['hiringOrganization'] ?? null)['name'] ?? null) ?? $board->name,
            locations: $locations === [] ? [new JobLocation(null, $target)] : $locations,
            remote: match (true) {
                str_contains($remoteType, 'remote') => RemoteStatus::Fully,
                str_contains($remoteType, 'hybrid') => RemoteStatus::Hybrid,
                str_contains($remoteType, 'onsite'), str_contains($remoteType, 'on-site') => RemoteStatus::Onsite,
                default => null,
            },
            department: null,
            employmentType: self::str($info['timeType'] ?? null),
            salaryText: null,
            postedAt: self::date($info['startDate'] ?? null),
            description: JobFeedParser::description(self::str($info['jobDescription'] ?? null)),
        );
    }

    /**
     * Places as Workday writes them: "Ludvika, Dalarna County, Sweden",
     * "Remote - Sweden" or "Remote - London, United Kingdom". The country is
     * the last part; the primary place falls back to the posting's country.
     *
     * @param  array<mixed>  $info
     * @return list<JobLocation>
     */
    private static function locations(array $info): array
    {
        $primaryCountry = CountryCode::normalize(self::str(self::arr($info['country'] ?? null)['descriptor'] ?? null));
        $places = [self::str($info['location'] ?? null), ...array_map(self::str(...), self::arr($info['additionalLocations'] ?? null))];

        $locations = [];
        foreach ($places as $index => $place) {
            if ($place === null) {
                continue;
            }

            $parts = array_values(array_filter(array_map(trim(...), explode(',', (string) preg_replace('~^remote\s*-\s*~i', '', $place))), static fn (string $p): bool => $p !== ''));
            $country = CountryCode::normalize(end($parts) ?: null) ?? ($index === 0 ? $primaryCountry : null);
            $city = count($parts) > 1 || CountryCode::normalize($parts[0] ?? null) === null ? ($parts[0] ?? null) : null;

            $location = new JobLocation($city, $country);
            if (($city !== null || $country !== null) && ! in_array($location, $locations)) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * The site's country facet and the id of the wanted country in it.
     * Null when the site has no country facet; a null id when the country
     * is not among its values. Facets may be nested in groups.
     *
     * @param  array<mixed>  $facets
     * @return array{string, string|null}|null
     */
    private static function countryFacet(array $facets, string $country): ?array
    {
        foreach ($facets as $facet) {
            $facet = self::arr($facet);
            $parameter = self::str($facet['facetParameter'] ?? null);
            $values = self::arr($facet['values'] ?? null);

            if ($parameter !== null && str_contains(strtolower($parameter), 'country')) {
                foreach ($values as $value) {
                    $value = self::arr($value);
                    if (CountryCode::normalize(self::str($value['descriptor'] ?? null)) === $country) {
                        return [$parameter, self::str($value['id'] ?? null)];
                    }
                }

                return [$parameter, null];
            }

            if (($nested = self::countryFacet($values, $country)) !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $url): array
    {
        $path = (string) parse_url(self::withScheme($url), PHP_URL_PATH);

        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }
}
