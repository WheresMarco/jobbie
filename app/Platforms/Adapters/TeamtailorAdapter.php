<?php

declare(strict_types=1);

namespace App\Platforms\Adapters;

use App\Enums\Platform;
use App\Models\Board;
use App\Platforms\BoardFeed;
use App\Platforms\BoardRef;
use App\Platforms\Contracts\PlatformAdapter;
use App\Scraping\FeedClient;
use App\Scraping\JobFeedParser;
use App\Scraping\JobPostingData;
use App\Scraping\RssEnricher;

/**
 * Teamtailor: jobs.json (JSON Feed with schema.org JobPosting) plus
 * jobs.rss for department and remote status.
 *
 * Teamtailor boards are not recognised from links here. Career sites on
 * custom domains need DNS and feed checks, so they go through candidates
 * and the Verifier, and BoardResolver keeps one board per site.
 */
final readonly class TeamtailorAdapter implements PlatformAdapter
{
    /** Teamtailor job ids are global, whichever host serves them. */
    private const string JOB_ID = '~/jobs/(\d+)(?:-|/|$)~';

    public function __construct(
        private FeedClient $feeds,
        private RssEnricher $rss,
        private JobFeedParser $parser,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Teamtailor;
    }

    public function recognize(string $url): ?BoardRef
    {
        return null;
    }

    public function jobKey(string $url): ?string
    {
        $path = parse_url(str_contains($url, '://') ? $url : "https://{$url}", PHP_URL_PATH);

        return is_string($path) && preg_match(self::JOB_ID, $path, $m) === 1 ? "teamtailor:{$m[1]}" : null;
    }

    public function fetch(Board $board): BoardFeed
    {
        $feed = $this->feeds->fetch($board->canonical_host);
        $enrichments = $this->rss->fetch($feed->finalHost() ?: $board->canonical_host);

        /** @var array<string, JobPostingData> $postings */
        $postings = [];
        foreach ($feed->items as $item) {
            $data = $this->parser->parse($item, $enrichments[(string) ($item['id'] ?? '')] ?? null, $feed->title);
            if ($data !== null) {
                $postings[$data->feedId] = $data;
            }
        }

        return new BoardFeed(
            title: $feed->title,
            homeUrl: $feed->homePageUrl,
            postings: $postings,
            truncated: $feed->truncated,
            teamtailor: $feed,
        );
    }
}
