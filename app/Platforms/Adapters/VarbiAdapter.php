<?php

declare(strict_types=1);

namespace App\Platforms\Adapters;

use App\Enums\Platform;
use App\Models\Board;
use App\Platforms\BoardFeed;
use App\Platforms\BoardRef;
use App\Platforms\Concerns\RequestsFeeds;
use App\Platforms\Contracts\PlatformAdapter;
use App\Scraping\FeedException;
use App\Scraping\JobFeedParser;
use App\Scraping\JobPostingData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use SimpleXMLElement;

/**
 * Varbi, used by most municipalities and regions. Each account lives on
 * <account>.varbi.com and publishes every open job in one RSS feed at
 * /what:rssfeed/ (checked 3 November 2026 against gotene and
 * regionstockholm). The feed has title, link, text and date but no
 * location; PlatsbankenMatcher fills in places from the same ad in
 * Platsbanken.
 */
final readonly class VarbiAdapter implements PlatformAdapter
{
    use RequestsFeeds;

    private const string HOST = '~^([a-z0-9][a-z0-9-]*)\.varbi\.com$~';

    private const string JOB_ID = '~/jobID:(\d+)~i';

    /** Varbi's own hosts, which are not customer accounts. */
    private const array RESERVED = ['www', 'api', 'cdn', 'static', 'help', 'support', 'status'];

    /** The channel title is "Nya lediga jobb hos <employer>". */
    private const string TITLE_PREFIX = '~^(?:nya lediga jobb hos|new (?:jobs|vacancies) (?:at|with))\s+~iu';

    public function __construct(
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Varbi;
    }

    public function recognize(string $url): ?BoardRef
    {
        $host = self::host($url);
        if ($host === null || preg_match(self::HOST, $host, $m) !== 1 || in_array($m[1], self::RESERVED, true)) {
            return null;
        }

        return new BoardRef(Platform::Varbi, $m[1], $host, "https://{$host}/se/");
    }

    public function jobKey(string $url): ?string
    {
        $host = self::host($url);
        if ($host === null || preg_match(self::HOST, $host) !== 1) {
            return null;
        }

        // Job ids are numbered across all accounts.
        return preg_match(self::JOB_ID, (string) parse_url(self::withScheme($url), PHP_URL_PATH), $m) === 1 ? "varbi:{$m[1]}" : null;
    }

    public function fetch(Board $board): BoardFeed
    {
        $url = "https://{$board->canonical_host}/what:rssfeed/";
        $response = $this->checked(static fn (PendingRequest $r): Response => $r->get($url));

        $channel = self::parse($response->body());
        $title = self::str((string) $channel->title);
        $employer = $title === null ? $board->name : (self::str((string) preg_replace(self::TITLE_PREFIX, '', $title)) ?? $board->name);

        /** @var array<string, JobPostingData> $postings */
        $postings = [];
        foreach ($channel->item as $item) {
            $link = self::str((string) $item->link) ?? self::str((string) $item->guid);
            $jobTitle = self::str((string) $item->title);
            if ($link === null || $jobTitle === null || preg_match(self::JOB_ID, $link, $m) !== 1) {
                continue;
            }

            $postings[$m[1]] = new JobPostingData(
                feedId: $m[1],
                title: $jobTitle,
                // The feed links to the English page; the ads are Swedish.
                url: (string) preg_replace('~^(https://[^/]+)/en/~', '$1/se/', $link),
                company: $employer,
                locations: [],
                remote: null,
                department: null,
                employmentType: null,
                salaryText: null,
                postedAt: self::date((string) $item->pubDate),
                description: JobFeedParser::description(self::str((string) $item->description)),
            );
        }

        return new BoardFeed(title: $employer, homeUrl: "https://{$board->canonical_host}/se/", postings: $postings);
    }

    /**
     * @throws FeedException When the body is not an RSS feed.
     */
    private static function parse(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || ! isset($document->channel)) {
            throw FeedException::dead('Not an RSS feed');
        }

        return $document->channel;
    }
}
