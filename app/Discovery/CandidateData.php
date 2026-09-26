<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\CandidateSourceType;
use App\Enums\Strength;

final readonly class CandidateData
{
    public function __construct(
        public string $host,
        public CandidateSourceType $source,
        public Strength $strength,
        public ?string $sourceRef = null,
    ) {
    }
}
