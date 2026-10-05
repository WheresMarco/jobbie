<?php

declare(strict_types=1);

namespace App\Scraping\Teamtailor;

use App\Enums\RemoteStatus;

/**
 * Fields only the RSS feed has, keyed by the item's guid (same as the JSON id).
 */
final readonly class RssEnrichment
{
    public function __construct(
        public ?string $department,
        public ?RemoteStatus $remote,
    ) {
    }
}
