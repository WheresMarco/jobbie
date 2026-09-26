<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an alias host was found to lead to a board.
 */
enum BoardAliasRelation: string
{
    case Redirect = 'redirect';

    case FeedHome = 'feed_home';

    case Seed = 'seed';

    case Cname = 'cname';

    /** A board merged into another because both served the same feed. */
    case Merged = 'merged';
}
