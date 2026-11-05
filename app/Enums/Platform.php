<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a board's postings come from: a recruitment system with one board
 * per employer account, or Platsbanken, which is a single board filled by
 * the JobStream sync rather than scraped.
 */
enum Platform: string
{
    case Teamtailor = 'teamtailor';

    case Platsbanken = 'platsbanken';

    case Varbi = 'varbi';

    case SmartRecruiters = 'smartrecruiters';

    case Workday = 'workday';

    case Greenhouse = 'greenhouse';

    case Lever = 'lever';

    case Ashby = 'ashby';

    /**
     * Platforms whose boards are read by ScrapeBoard.
     *
     * @return list<self>
     */
    public static function scraped(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p !== self::Platsbanken));
    }

    /**
     * Platforms used by companies across the world, where only postings in
     * the configured country are read. A board there with none is empty
     * most of the time, so empty boards are only checked now and then.
     *
     * @return list<self>
     */
    public static function countryFiltered(): array
    {
        return [self::SmartRecruiters, self::Workday, self::Greenhouse, self::Lever, self::Ashby];
    }

    /**
     * How many boards on the platform may be scraped at once. Boards share
     * one API host here, which takes more than one request at a time.
     */
    public function parallelScrapes(): int
    {
        return match ($this) {
            self::Greenhouse, self::Lever, self::Ashby => 4,
            default => 1,
        };
    }
}
