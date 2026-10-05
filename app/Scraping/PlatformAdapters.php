<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\Platform;
use App\Scraping\Contracts\PlatformAdapter;
use App\Scraping\Teamtailor\TeamtailorAdapter;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Maps each scraped platform to its adapter.
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
        $class = self::ADAPTERS[$platform->value] ?? throw new InvalidArgumentException("Platform {$platform->value} is not scraped");

        return $this->container->make($class);
    }
}
