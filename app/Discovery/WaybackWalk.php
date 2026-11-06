<?php

declare(strict_types=1);

namespace App\Discovery;

use Carbon\CarbonImmutable;

/**
 * Where a walk through the Wayback index is: the key to read from next,
 * when to stop for now, and whether the end was reached or the index
 * stopped answering. The source moves it along, so a queued job can carry
 * on where the previous one stopped.
 */
final class WaybackWalk
{
    public bool $done = false;

    public bool $unavailable = false;

    public function __construct(
        public ?string $from = null,
        public ?CarbonImmutable $until = null,
    ) {
    }
}
