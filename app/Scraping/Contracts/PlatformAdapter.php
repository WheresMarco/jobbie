<?php

declare(strict_types=1);

namespace App\Scraping\Contracts;

use App\Models\Board;
use App\Scraping\FeedException;
use App\Scraping\Listing;

/**
 * Reads the open postings of a board on one platform. BoardScraper does
 * the storing, so an adapter only fetches and maps.
 */
interface PlatformAdapter
{
    /**
     * @throws FeedException When the board cannot be read. Dead means the
     *                       board is gone and its postings are closed.
     */
    public function fetch(Board $board): Listing;

    /**
     * Called after the postings are stored, for platform specific follow
     * up such as recording redirects as aliases.
     */
    public function scraped(Board $board, Listing $listing): void;

    /**
     * Scrapes sharing this key never run at the same time. Platforms that
     * serve every customer from one host should return the same key for
     * all their boards.
     */
    public function concurrencyKey(Board $board): string;
}
