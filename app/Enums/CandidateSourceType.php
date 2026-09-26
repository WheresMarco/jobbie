<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Discovery source of a candidate host.
 */
enum CandidateSourceType: string
{
    case Seed = 'seed';

    case Redirect = 'redirect';

    case JobStream = 'jobstream';

    case Prefix = 'prefix';

    case CommonCrawl = 'commoncrawl';

    case Txt = 'txt';

    case HttpArchive = 'http_archive';

    case SearchApi = 'search_api';
}
