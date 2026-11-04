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
 * SmartRecruiters, through its public Posting API (api.smartrecruiters.com,
 * no key). Accounts are the company identifier in
 * jobs.smartrecruiters.com/<company>/<posting id>-<slug>. Most accounts
 * here are multinationals, so only postings in the configured country are
 * read; the API filters on country itself. The list has no text, so each
 * posting is fetched once more for its description.
 */
final readonly class SmartRecruitersAdapter implements PlatformAdapter
{
    use RequestsFeeds;

    public const int PAGE_SIZE = 100;

    public const int MAX_PAGES = 20;

    private const string API = 'https://api.smartrecruiters.com/v1/companies';

    private const array HOSTS = ['jobs.smartrecruiters.com', 'careers.smartrecruiters.com'];

    private const string COMPANY = '~^[A-Za-z0-9][A-Za-z0-9_-]{1,99}$~';

    /** First path segments on the job hosts that are not companies. */
    private const array RESERVED = ['oneclick-ui', 'sr-jobs', 'api', 'static', 'search'];

    /** Sections of a job ad in the order they are shown. */
    private const array SECTIONS = ['jobDescription', 'qualifications', 'additionalInformation', 'companyDescription'];

    public function __construct(
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
        private string $country,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::SmartRecruiters;
    }

    public function recognize(string $url): ?BoardRef
    {
        $company = $this->segments($url)[0] ?? null;
        if ($company === null || preg_match(self::COMPANY, $company) !== 1 || in_array(strtolower($company), self::RESERVED, true)) {
            return null;
        }

        return new BoardRef(Platform::SmartRecruiters, $company, self::HOSTS[0], 'https://'.self::HOSTS[0]."/{$company}");
    }

    public function jobKey(string $url): ?string
    {
        $job = $this->segments($url)[1] ?? null;

        // Posting ids are unique across companies.
        return $job !== null && preg_match('~^(\d{6,})(?:-|$)~', $job, $m) === 1 ? "smartrecruiters:{$m[1]}" : null;
    }

    public function fetch(Board $board): BoardFeed
    {
        $company = rawurlencode($board->external_key);
        $listed = [];
        $truncated = false;

        for ($page = 0; ; $page++) {
            try {
                $json = $this->getJson(self::API."/{$company}/postings", [
                    'country' => strtolower($this->country),
                    'limit' => self::PAGE_SIZE,
                    'offset' => $page * self::PAGE_SIZE,
                ]);
            } catch (FeedException $e) {
                if ($page === 0) {
                    throw $e;
                }
                $truncated = true;

                break;
            }

            foreach (self::arr($json['content'] ?? null) as $item) {
                $item = self::arr($item);
                $id = $item['id'] ?? null;
                if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                    $listed[(string) $id] = $item;
                }
            }

            $total = is_int($json['totalFound'] ?? null) ? $json['totalFound'] : 0;
            if (($page + 1) * self::PAGE_SIZE >= $total) {
                break;
            }
            if ($page + 1 >= self::MAX_PAGES) {
                $truncated = true;

                break;
            }
        }

        $details = $this->getJsonMany(array_map(
            static fn (int|string $id): string => self::API."/{$company}/postings/".rawurlencode((string) $id),
            array_combine(array_keys($listed), array_keys($listed)),
        ));

        /** @var array<string, JobPostingData> $postings */
        $postings = [];
        $name = null;
        foreach ($listed as $id => $item) {
            $posting = $this->posting((string) $id, [...$item, ...($details[(string) $id] ?? [])], $board);
            if ($posting !== null) {
                $postings[$posting->feedId] = $posting;
                $name ??= $posting->company;
            }
        }

        return new BoardFeed(
            title: $name ?? $board->name,
            homeUrl: 'https://'.self::HOSTS[0].'/'.$board->external_key,
            postings: $postings,
            truncated: $truncated,
        );
    }

    /**
     * @param  array<mixed>  $item  List entry merged with the detail response.
     */
    private function posting(string $id, array $item, Board $board): ?JobPostingData
    {
        $title = self::str($item['name'] ?? null);
        $location = self::arr($item['location'] ?? null);
        $country = CountryCode::normalize(self::str($location['country'] ?? null));
        if ($title === null || $country !== strtoupper($this->country)) {
            return null;
        }

        $city = self::str($location['city'] ?? null);
        $remote = match (true) {
            ($location['remote'] ?? false) === true => RemoteStatus::Fully,
            ($location['hybrid'] ?? false) === true => RemoteStatus::Hybrid,
            default => RemoteStatus::Onsite,
        };

        $sections = self::arr(self::arr($item['jobAd'] ?? null)['sections'] ?? null);
        $html = implode("\n", array_filter(array_map(
            static fn (string $key): ?string => self::str(self::arr($sections[$key] ?? null)['text'] ?? null),
            self::SECTIONS,
        )));

        return new JobPostingData(
            feedId: $id,
            title: $title,
            url: self::str($item['postingUrl'] ?? null) ?? 'https://'.self::HOSTS[0]."/{$board->external_key}/{$id}",
            company: self::str(self::arr($item['company'] ?? null)['name'] ?? null) ?? $board->name,
            locations: [new JobLocation($city, $country)],
            remote: $remote,
            department: self::str(self::arr($item['department'] ?? null)['label'] ?? null),
            employmentType: self::str(self::arr($item['typeOfEmployment'] ?? null)['label'] ?? null),
            salaryText: null,
            postedAt: self::date($item['releasedDate'] ?? null),
            description: JobFeedParser::description($html === '' ? null : $html),
        );
    }

    /**
     * Path segments of a link on one of the job hosts, else none.
     *
     * @return list<string>
     */
    private function segments(string $url): array
    {
        if (! in_array(self::host($url), self::HOSTS, true)) {
            return [];
        }

        $path = (string) parse_url(self::withScheme($url), PHP_URL_PATH);

        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }
}
