<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The system a board's postings are read from. Each platform has an
 * adapter in App\Scraping (see PlatformAdapters).
 */
enum Platform: string
{
    case Teamtailor = 'teamtailor';
}
