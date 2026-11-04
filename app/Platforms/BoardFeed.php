<?php

declare(strict_types=1);

namespace App\Platforms;

use App\Scraping\Feed;
use App\Scraping\JobPostingData;

/**
 * The open postings read from one board.
 */
final readonly class BoardFeed
{
    /**
     * @param  array<string, JobPostingData>  $postings  Keyed by feed id.
     * @param  bool  $truncated  True when the read stopped early, so missing postings must not be closed.
     * @param  Feed|null  $teamtailor  The raw Teamtailor feed, for recording redirects (source A).
     */
    public function __construct(
        public ?string $title,
        public ?string $homeUrl,
        public array $postings,
        public bool $truncated = false,
        public ?Feed $teamtailor = null,
    ) {
    }
}
