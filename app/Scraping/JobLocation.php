<?php

declare(strict_types=1);

namespace App\Scraping;

final readonly class JobLocation
{
    public function __construct(
        public ?string $city,
        public ?string $country,
    ) {}
}
