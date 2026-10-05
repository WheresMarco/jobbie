<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The system a board's postings are read from. Scraped platforms have an
 * adapter in App\Scraping (see PlatformAdapters); Platsbanken is synced
 * from JobStream instead.
 */
enum Platform: string
{
    case Teamtailor = 'teamtailor';

    case Platsbanken = 'platsbanken';

    /**
     * Whether radar:scrape reads the platform's boards through an adapter.
     */
    public function isScraped(): bool
    {
        return $this !== self::Platsbanken;
    }

    /**
     * @return list<string>
     */
    public static function scrapedValues(): array
    {
        return array_values(array_map(
            static fn (self $p): string => $p->value,
            array_filter(self::cases(), static fn (self $p): bool => $p->isScraped()),
        ));
    }
}
