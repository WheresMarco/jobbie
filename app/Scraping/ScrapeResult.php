<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\BoardStatus;

final readonly class ScrapeResult
{
    public function __construct(
        public BoardStatus $status,
        public int $jobCount = 0,
        public int $deactivated = 0,
        public bool $truncated = false,
        public ?string $error = null,
        public ?Feed $feed = null,
    ) {
    }
}
