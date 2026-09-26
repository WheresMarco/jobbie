<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Values of remoteStatus in the Teamtailor RSS feed.
 */
enum RemoteStatus: string
{
    case Fully = 'fully';

    case Hybrid = 'hybrid';

    case None = 'none';

    case Onsite = 'onsite';
}
