<?php

declare(strict_types=1);

namespace App\Scraping;

/**
 * All open postings read from one board, whatever the platform.
 */
final readonly class Listing
{
    /**
     * @param  array<string, JobPostingData>  $postings  Keyed by feed id.
     * @param  string|null  $finalHost  Where the listing was read from after redirects.
     * @param  bool  $truncated  True when reading stopped before the end, so missing ads must not be deactivated.
     */
    public function __construct(
        public ?string $name,
        public ?string $homeUrl,
        public ?string $finalHost,
        public array $postings,
        public bool $truncated,
    ) {
    }
}
