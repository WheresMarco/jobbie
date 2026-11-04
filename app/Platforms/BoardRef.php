<?php

declare(strict_types=1);

namespace App\Platforms;

use App\Enums\Platform;

/**
 * An account on a platform, as recognised from a link.
 */
final readonly class BoardRef
{
    /**
     * @param  string  $externalKey  Identifies the account on the platform (Board::external_key).
     * @param  string  $host  Where the board is served (Board::canonical_host).
     * @param  string  $siteUrl  The account's public list of jobs.
     */
    public function __construct(
        public Platform $platform,
        public string $externalKey,
        public string $host,
        public string $siteUrl,
    ) {
    }
}
