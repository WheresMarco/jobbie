<?php

declare(strict_types=1);

namespace App\Platforms\Adapters;

use App\Enums\Platform;
use App\Enums\RemoteStatus;
use App\Models\Board;
use App\Platforms\BoardFeed;
use App\Platforms\BoardRef;
use App\Platforms\Concerns\ReadsLocations;
use App\Platforms\Concerns\RequestsFeeds;
use App\Platforms\Contracts\CrawledPlatform;
use App\Scraping\CountryCode;
use App\Scraping\FeedException;
use App\Scraping\JobFeedParser;
use App\Scraping\JobLocation;
use App\Scraping\JobPostingData;
use Carbon\CarbonImmutable;

/**
 * Lever, through its public Postings API (api.lever.co, or api.eu.lever.co
 * for accounts in the EU region; no key). Accounts are the company name in
 * jobs.lever.co/<company>/<posting id>. One request returns every posting
 * with its text; each has an ISO country and a list of places. The API has
 * no company name, so it is read once from the title of the job list.
 */
final readonly class LeverAdapter implements CrawledPlatform
{
    use ReadsLocations;
    use RequestsFeeds;

    /** Job list host => API host. */
    private const array HOSTS = ['jobs.lever.co' => 'api.lever.co', 'jobs.eu.lever.co' => 'api.eu.lever.co'];

    private const string COMPANY = '~^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$~';

    private const array RESERVED = ['favicon.ico', 'robots.txt', 'sitemap.xml'];

    public function __construct(
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
        private string $country,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Lever;
    }

    public function crawlPatterns(): array
    {
        return array_map(static fn (string $host): string => "{$host}/*", array_keys(self::HOSTS));
    }

    public function recognize(string $url): ?BoardRef
    {
        $host = self::host($url);
        $company = $host !== null && isset(self::HOSTS[$host]) ? (self::pathSegments($url)[0] ?? null) : null;
        if ($company === null || preg_match(self::COMPANY, $company) !== 1 || in_array(strtolower($company), self::RESERVED, true)) {
            return null;
        }

        return new BoardRef(Platform::Lever, $company, $host, "https://{$host}/{$company}");
    }

    public function jobKey(string $url): ?string
    {
        $host = self::host($url);
        $job = $host !== null && isset(self::HOSTS[$host]) ? (self::pathSegments($url)[1] ?? null) : null;

        // Posting ids are UUIDs.
        return $job !== null && preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $job) === 1 ? 'lever:'.strtolower($job) : null;
    }

    public function fetch(Board $board): BoardFeed
    {
        $host = isset(self::HOSTS[$board->canonical_host]) ? $board->canonical_host : array_key_first(self::HOSTS);
        $list = $this->getJson('https://'.self::HOSTS[$host].'/v0/postings/'.rawurlencode($board->external_key), ['mode' => 'json']);

        $postings = [];
        foreach ($list as $item) {
            $posting = $this->posting(self::arr($item), $board);
            if ($posting !== null) {
                $postings[$posting->feedId] = $posting;
            }
        }

        $homeUrl = "https://{$host}/{$board->external_key}";

        return new BoardFeed(
            title: $board->name ?? ($postings === [] ? null : $this->title($homeUrl)),
            homeUrl: $homeUrl,
            postings: $postings,
        );
    }

    /**
     * @param  array<mixed>  $item
     */
    private function posting(array $item, Board $board): ?JobPostingData
    {
        $id = self::str($item['id'] ?? null);
        $title = self::str($item['text'] ?? null);
        if ($id === null || $title === null) {
            return null;
        }

        $categories = self::arr($item['categories'] ?? null);
        $places = array_values(array_filter(array_map(self::str(...), self::arr($categories['allLocations'] ?? null))));
        $primary = self::str($categories['location'] ?? null);
        $locations = [];
        foreach ($primary === null ? $places : [$primary, ...$places] as $place) {
            $locations = self::merge($locations, self::locations($place));
        }
        // The country belongs to the primary place.
        $country = CountryCode::normalize(self::str($item['country'] ?? null));
        if ($country !== null && $locations !== [] && $locations[0]->country === null) {
            $locations[0] = new JobLocation($locations[0]->city, $country);
        } elseif ($country !== null && $locations === []) {
            $locations = [new JobLocation(null, $country)];
        }

        if (! $this->inCountry($locations)) {
            return null;
        }

        $html = implode("\n", array_filter([
            self::str($item['description'] ?? null),
            ...array_map(static function (mixed $list): ?string {
                $list = self::arr($list);
                $heading = self::str($list['text'] ?? null);
                $content = self::str($list['content'] ?? null);

                return $content === null ? null : ($heading === null ? '' : "<h3>{$heading}</h3>")."<ul>{$content}</ul>";
            }, self::arr($item['lists'] ?? null)),
            self::str($item['additional'] ?? null),
        ]));

        $created = $item['createdAt'] ?? null;

        return new JobPostingData(
            feedId: $id,
            title: $title,
            url: self::str($item['hostedUrl'] ?? null) ?? "https://{$board->canonical_host}/{$board->external_key}/{$id}",
            company: $board->name,
            locations: $locations,
            remote: match (self::str($item['workplaceType'] ?? null)) {
                'remote' => RemoteStatus::Fully,
                'hybrid' => RemoteStatus::Hybrid,
                'onsite' => RemoteStatus::Onsite,
                default => null,
            },
            department: self::str($categories['department'] ?? null) ?? self::str($categories['team'] ?? null),
            employmentType: self::str($categories['commitment'] ?? null),
            salaryText: self::str($item['salaryDescriptionPlain'] ?? null),
            postedAt: is_int($created) ? CarbonImmutable::createFromTimestampMs($created)->utc() : null,
            description: JobFeedParser::description($html === '' ? null : $html),
        );
    }

    /**
     * The company name: the job list's page title.
     */
    private function title(string $url): ?string
    {
        try {
            $html = $this->checked(static fn ($request) => $request->get($url))->body();
        } catch (FeedException) {
            return null;
        }

        return preg_match('~<title>([^<]+)</title>~i', $html, $m) === 1
            ? self::str(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : null;
    }
}
