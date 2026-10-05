<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\Platform;
use App\Scraping\Contracts\PlatformAdapter;
use App\Scraping\Teamtailor\TeamtailorAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Maps each platform to its adapter.
 */
final readonly class PlatformAdapters
{
    /** @var array<string, class-string<PlatformAdapter>> */
    private const array ADAPTERS = [
        Platform::Teamtailor->value => TeamtailorAdapter::class,
    ];

    public function __construct(private Container $container)
    {
    }

    public function for(Platform $platform): PlatformAdapter
    {
        return $this->container->make(self::ADAPTERS[$platform->value]);
    }
}
