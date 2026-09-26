<?php

declare(strict_types=1);

namespace App\Discovery\JobStream;

final readonly class JobStreamResult
{
    public function __construct(
        public bool $snapshot,
        public int $ads,
        public int $removed,
        public int $newDomains,
    ) {
    }
}
