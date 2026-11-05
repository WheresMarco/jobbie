<?php

declare(strict_types=1);

namespace App\Platforms\Adapters;

use App\Enums\Platform;
use App\Models\Board;
use App\Platforms\BoardFeed;
use App\Platforms\BoardRef;
use App\Platforms\Concerns\ReadsLocations;
use App\Platforms\Concerns\RequestsFeeds;
use App\Platforms\Contracts\CrawledPlatform;
use App\Scraping\JobFeedParser;
use App\Scraping\JobPostingData;

/**
 * Greenhouse, through its public Job Board API (boards-api.greenhouse.io,
 * no key). Accounts are the board token in boards.greenhouse.io/<token>
 * or job-boards.greenhouse.io/<token>/jobs/<id>. The list is read without
 * the texts, which keeps boards with nothing in the country to one small
 * request; postings in the country are then fetched one by one for their
 * text, departments and offices. Locations are free text.
 */
final readonly class GreenhouseAdapter implements CrawledPlatform
{
    use ReadsLocations;
    use RequestsFeeds;

    private const string API = 'https://boards-api.greenhouse.io/v1/boards';

    private const string HOST = 'job-boards.greenhouse.io';

    private const array HOSTS = ['boards.greenhouse.io', 'job-boards.greenhouse.io'];

    private const string TOKEN = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$~';

    /** First path segments on the board hosts that are not boards. */
    private const array RESERVED = ['embed', 'v1', 'api', 'static', 'assets', 'favicon.ico', 'robots.txt', 'sitemap.xml'];

    public function __construct(
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
        private string $country,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Greenhouse;
    }

    public function crawlPatterns(): array
    {
        return array_map(static fn (string $host): string => "{$host}/*", self::HOSTS);
    }

    public function recognize(string $url): ?BoardRef
    {
        if (! in_array(self::host($url), self::HOSTS, true)) {
            return null;
        }

        $segments = self::pathSegments($url);
        // Embedded forms name the board in a parameter.
        $token = ($segments[0] ?? null) === 'embed' ? self::query($url, 'for') : ($segments[0] ?? null);
        if ($token === null || preg_match(self::TOKEN, $token) !== 1 || in_array(strtolower($token), self::RESERVED, true)) {
            return null;
        }

        return new BoardRef(Platform::Greenhouse, strtolower($token), self::HOST, 'https://'.self::HOST.'/'.strtolower($token));
    }

    public function jobKey(string $url): ?string
    {
        // Job ids are unique across boards; career sites on the employer's
        // own domain carry them as gh_jid.
        $id = self::query($url, 'gh_jid');
        if ($id === null && in_array(self::host($url), self::HOSTS, true)) {
            $segments = self::pathSegments($url);
            $id = ($segments[1] ?? null) === 'jobs' ? ($segments[2] ?? null) : self::query($url, 'token');
        }

        return $id !== null && preg_match('~^\d{4,}$~', $id) === 1 ? "greenhouse:{$id}" : null;
    }

    public function fetch(Board $board): BoardFeed
    {
        $token = rawurlencode($board->external_key);
        $list = $this->getJson(self::API."/{$token}/jobs");

        $listed = [];
        $name = null;
        foreach (self::arr($list['jobs'] ?? null) as $item) {
            $item = self::arr($item);
            $id = $item['id'] ?? null;
            $name ??= self::str($item['company_name'] ?? null);
            if (is_int($id) && $this->inCountry(self::locations(self::str(self::arr($item['location'] ?? null)['name'] ?? null)))) {
                $listed[(string) $id] = $item;
            }
        }

        // Prefixed keys: numeric ones would turn into integers.
        $urls = [];
        foreach (array_keys($listed) as $id) {
            $urls["job{$id}"] = self::API."/{$token}/jobs/{$id}";
        }
        $details = $this->getJsonMany($urls);

        $postings = [];
        foreach ($listed as $id => $item) {
            $posting = $this->posting((string) $id, [...$item, ...($details["job{$id}"] ?? [])], $board);
            if ($posting !== null) {
                $postings[$posting->feedId] = $posting;
            }
        }

        return new BoardFeed(
            title: $name ?? $board->name,
            homeUrl: 'https://'.self::HOST.'/'.$board->external_key,
            postings: $postings,
        );
    }

    /**
     * @param  array<mixed>  $item  List entry merged with the detail response.
     */
    private function posting(string $id, array $item, Board $board): ?JobPostingData
    {
        $title = self::str($item['title'] ?? null);
        if ($title === null) {
            return null;
        }

        $place = self::str(self::arr($item['location'] ?? null)['name'] ?? null);
        $locations = self::locations($place);
        foreach (self::arr($item['offices'] ?? null) as $office) {
            $locations = self::merge($locations, self::locations(self::str(self::arr($office)['location'] ?? null)));
        }

        $content = self::str($item['content'] ?? null);

        return new JobPostingData(
            feedId: $id,
            title: $title,
            url: self::str($item['absolute_url'] ?? null) ?? 'https://'.self::HOST."/{$board->external_key}/jobs/{$id}",
            company: self::str($item['company_name'] ?? null) ?? $board->name,
            locations: $locations,
            remote: self::remoteFromText($place),
            department: self::str(self::arr(self::arr($item['departments'] ?? null)[0] ?? null)['name'] ?? null),
            employmentType: null,
            salaryText: null,
            postedAt: self::date($item['first_published'] ?? null) ?? self::date($item['updated_at'] ?? null),
            // The API escapes the HTML of the text.
            description: JobFeedParser::description($content === null ? null : html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        );
    }
}
