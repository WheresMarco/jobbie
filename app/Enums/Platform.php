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

    /**
     * Platforms whose boards are read by ScrapeBoard.
     *
     * @return list<self>
     */
    public static function scraped(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p !== self::Platsbanken));
    }
}
