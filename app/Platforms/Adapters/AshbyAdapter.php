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

/**
 * Ashby, through its public Job Posting API (api.ashbyhq.com, no key).
 * Accounts are the job board name in jobs.ashbyhq.com/<name>/<posting id>.
 * One request returns every posting with its text, a postal address and
 * secondary places. The API has no company name, so it is read once from
 * the title of the job list ("Lovable Jobs").
 */
final readonly class AshbyAdapter implements CrawledPlatform
{
    use ReadsLocations;
    use RequestsFeeds;

    private const string API = 'https://api.ashbyhq.com/posting-api/job-board';

    private const string HOST = 'jobs.ashbyhq.com';

    private const string NAME = '~^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,99}$~u';

    private const array RESERVED = ['api', 'favicon.ico', 'robots.txt', 'sitemap.xml'];

    private const array EMPLOYMENT = [
        'FullTime' => 'Full-time',
        'PartTime' => 'Part-time',
        'Intern' => 'Internship',
        'Contract' => 'Contract',
        'Temporary' => 'Temporary',
    ];

    public function __construct(
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
        private string $country,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Ashby;
    }

    public function crawlPatterns(): array
    {
        return [self::HOST.'/*'];
    }

    public function recognize(string $url): ?BoardRef
    {
        $name = self::host($url) === self::HOST ? (self::pathSegments($url)[0] ?? null) : null;
        if ($name === null || preg_match(self::NAME, $name) !== 1 || in_array(strtolower($name), self::RESERVED, true)) {
            return null;
        }

        return new BoardRef(Platform::Ashby, $name, self::HOST, 'https://'.self::HOST.'/'.rawurlencode($name));
    }

    public function jobKey(string $url): ?string
    {
        // Career sites on the employer's own domain carry the id as ashby_jid.
        $job = self::query($url, 'ashby_jid') ?? (self::host($url) === self::HOST ? (self::pathSegments($url)[1] ?? null) : null);

        return $job !== null && preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $job) === 1 ? 'ashby:'.strtolower($job) : null;
    }

    public function fetch(Board $board): BoardFeed
    {
        $json = $this->getJson(self::API.'/'.rawurlencode($board->external_key), ['includeCompensation' => 'true']);

        $postings = [];
        foreach (self::arr($json['jobs'] ?? null) as $item) {
            $posting = $this->posting(self::arr($item), $board);
            if ($posting !== null) {
                $postings[$posting->feedId] = $posting;
            }
        }

        $homeUrl = 'https://'.self::HOST.'/'.rawurlencode($board->external_key);

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
        $title = self::str($item['title'] ?? null);
        if ($id === null || $title === null || ($item['isListed'] ?? true) === false) {
            return null;
        }

        $locations = self::place(self::str($item['location'] ?? null), self::arr($item['address'] ?? null));
        foreach (self::arr($item['secondaryLocations'] ?? null) as $secondary) {
            $secondary = self::arr($secondary);
            $locations = self::merge($locations, self::place(self::str($secondary['location'] ?? null), self::arr($secondary['address'] ?? null)));
        }

        if (! $this->inCountry($locations)) {
            return null;
        }

        $compensation = self::arr($item['compensation'] ?? null);

        return new JobPostingData(
            feedId: $id,
            title: $title,
            url: self::str($item['jobUrl'] ?? null) ?? 'https://'.self::HOST.'/'.rawurlencode($board->external_key)."/{$id}",
            company: $board->name,
            locations: $locations,
            remote: match (true) {
                self::str($item['workplaceType'] ?? null) === 'Remote', ($item['isRemote'] ?? false) === true => RemoteStatus::Fully,
                self::str($item['workplaceType'] ?? null) === 'Hybrid' => RemoteStatus::Hybrid,
                self::str($item['workplaceType'] ?? null) === 'OnSite' => RemoteStatus::Onsite,
                default => null,
            },
            department: self::str($item['department'] ?? null) ?? self::str($item['team'] ?? null),
            employmentType: self::EMPLOYMENT[self::str($item['employmentType'] ?? null) ?? ''] ?? null,
            salaryText: ($item['shouldDisplayCompensationOnJobPostings'] ?? false) === true
                ? self::str($compensation['compensationTierSummary'] ?? null)
                : null,
            postedAt: self::date($item['publishedAt'] ?? null),
            description: JobFeedParser::description(self::str($item['descriptionHtml'] ?? null)),
        );
    }

    /**
     * A place from its postal address, else from its name.
     *
     * @param  array<mixed>  $address
     * @return list<JobLocation>
     */
    private static function place(?string $name, array $address): array
    {
        $postal = self::arr($address['postalAddress'] ?? null);
        $country = CountryCode::normalize(self::str($postal['addressCountry'] ?? null));
        $city = self::str($postal['addressLocality'] ?? null);
        if ($country !== null || $city !== null) {
            // Some accounts put the country in the locality as well.
            $parsed = self::locations($city ?? $name)[0] ?? null;

            return [new JobLocation($parsed === null ? $city : $parsed->city, $country ?? $parsed?->country)];
        }

        return self::locations($name);
    }

    /**
     * The company name: the job list's page title, without " Jobs".
     */
    private function title(string $url): ?string
    {
        try {
            $html = $this->checked(static fn ($request) => $request->get($url))->body();
        } catch (FeedException) {
            return null;
        }

        if (preg_match('~<title>([^<]+)</title>~i', $html, $m) !== 1) {
            return null;
        }

        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::str((string) preg_replace('~\s+Jobs$~i', '', trim($title)));
    }
}
