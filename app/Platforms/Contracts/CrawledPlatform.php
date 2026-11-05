<?php

declare(strict_types=1);

namespace App\Platforms\Contracts;

/**
 * A platform whose accounts are paths on a few shared hosts, so Common
 * Crawl lists them: every captured link under the patterns names one.
 */
interface CrawledPlatform extends PlatformAdapter
{
    /**
     * CDX URL patterns, such as "jobs.lever.co/*".
     *
     * @return list<string>
     */
    public function crawlPatterns(): array;
}
