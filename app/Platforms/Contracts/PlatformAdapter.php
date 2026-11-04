<?php

declare(strict_types=1);

namespace App\Platforms\Contracts;

use App\Enums\Platform;
use App\Models\Board;
use App\Platforms\BoardFeed;
use App\Platforms\BoardRef;
use App\Scraping\FeedException;

/**
 * Everything Jobbie knows about one recruitment system: which links belong
 * to it, and how to read an account's open jobs.
 */
interface PlatformAdapter
{
    public function platform(): Platform;

    /**
     * The account a link (a job, an application form or the career site)
     * belongs to, or null when the link is not on this platform.
     */
    public function recognize(string $url): ?BoardRef;

    /**
     * A key that is the same for every link to one job, such as the ad and
     * its application form, or null when the link is not to a job here.
     * Used to find the same ad in Platsbanken.
     */
    public function jobKey(string $url): ?string;

    /**
     * Read all open postings on the board.
     *
     * @throws FeedException When the board cannot be read at all.
     */
    public function fetch(Board $board): BoardFeed;
}
