<?php

declare(strict_types=1);

namespace App\Platforms;

use App\Enums\Platform;
use App\Platforms\Adapters\AshbyAdapter;
use App\Platforms\Adapters\GreenhouseAdapter;
use App\Platforms\Adapters\LeverAdapter;
use App\Platforms\Adapters\SmartRecruitersAdapter;
use App\Platforms\Adapters\TeamtailorAdapter;
use App\Platforms\Adapters\VarbiAdapter;
use App\Platforms\Adapters\WorkdayAdapter;
use App\Platforms\Contracts\PlatformAdapter;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The adapter for each scraped platform. Discovery only creates boards on
 * the platforms enabled in config (radar.platforms.enabled); boards that
 * already exist are scraped whatever the setting.
 */
final class PlatformRegistry
{
    /** @var array<string, class-string<PlatformAdapter>> */
    private const array ADAPTERS = [
        Platform::Teamtailor->value => TeamtailorAdapter::class,
        Platform::Varbi->value => VarbiAdapter::class,
        Platform::SmartRecruiters->value => SmartRecruitersAdapter::class,
        Platform::Workday->value => WorkdayAdapter::class,
        Platform::Greenhouse->value => GreenhouseAdapter::class,
        Platform::Lever->value => LeverAdapter::class,
        Platform::Ashby->value => AshbyAdapter::class,
    ];

    /** @var array<string, PlatformAdapter> */
    private array $adapters = [];

    /**
     * @param  list<Platform>  $enabled  Platforms discovery creates boards on.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $enabled,
    ) {
    }

    public function adapter(Platform $platform): PlatformAdapter
    {
        $class = self::ADAPTERS[$platform->value] ?? throw new InvalidArgumentException("Platform {$platform->value} is not scraped");

        return $this->adapters[$platform->value] ??= $this->container->make($class);
    }

    /**
     * @return list<Platform>
     */
    public function enabled(): array
    {
        return $this->enabled;
    }

    /**
     * The account an outside link belongs to, on an enabled platform.
     */
    public function recognize(string $url): ?BoardRef
    {
        foreach ($this->enabled as $platform) {
            if (($ref = $this->adapter($platform)->recognize($url)) !== null) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * The job a link points at, on any scraped platform. Teamtailor goes
     * last: its job links (/jobs/<id>-...) are on any host, so they also
     * fit other platforms' links.
     */
    public function jobKey(string $url): ?string
    {
        $platforms = array_keys(self::ADAPTERS);
        usort($platforms, static fn (string $a, string $b): int => ($a === Platform::Teamtailor->value) <=> ($b === Platform::Teamtailor->value));

        foreach ($platforms as $platform) {
            if (($key = $this->adapter(Platform::from($platform))->jobKey($url)) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     * @return list<Platform>
     */
    public static function parseNames(array $names): array
    {
        $platforms = [];
        foreach ($names as $name) {
            $platform = Platform::tryFrom(strtolower(trim($name)));
            if ($platform !== null && isset(self::ADAPTERS[$platform->value])) {
                $platforms[$platform->value] = $platform;
            }
        }

        return array_values($platforms);
    }
}
