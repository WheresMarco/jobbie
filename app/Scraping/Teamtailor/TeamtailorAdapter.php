<?php

declare(strict_types=1);

namespace App\Scraping\Teamtailor;

use App\Discovery\Sources\RedirectSource;
use App\Models\Board;
use App\Scraping\Contracts\PlatformAdapter;
use App\Scraping\JobPostingData;
use App\Scraping\Listing;

/**
 * Teamtailor: jobs.json (JSON Feed) page by page, enriched from jobs.rss.
 */
final readonly class TeamtailorAdapter implements PlatformAdapter
{
    public function __construct(
        private FeedClient $feeds,
        private RssEnricher $rss,
        private JobFeedParser $parser,
        private RedirectSource $redirects,
    ) {
    }

    public function fetch(Board $board): Listing
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

        return new Listing(
            name: $feed->title,
            homeUrl: $feed->homePageUrl,
            finalHost: $feed->finalHost() ?: null,
            postings: $postings,
            truncated: $feed->truncated,
        );
    }

    /**
     * Source A: record where the feed lives and merge duplicate boards.
     */
    public function scraped(Board $board, Listing $listing): void
    {
        $this->redirects->record($board, $listing);
    }

    /**
     * Custom domains and slugs are separate hosts, so one request at a time per host.
     */
    public function concurrencyKey(Board $board): string
    {
        return 'host:'.$board->canonical_host;
    }
}
