<?php

declare(strict_types=1);

namespace App\Platforms\Concerns;

use App\Enums\RemoteStatus;
use App\Scraping\JobLocation;
use App\Scraping\Places;

/**
 * Locations for adapters on platforms used across the world, where only
 * postings in the configured country are read. The using class has a
 * $country property.
 */
trait ReadsLocations
{
    /**
     * @return list<JobLocation>
     */
    protected static function locations(?string $text): array
    {
        return Places::parseAll($text);
    }

    /**
     * @param  list<JobLocation>  $locations
     * @param  list<JobLocation>  $more
     * @return list<JobLocation>
     */
    protected static function merge(array $locations, array $more): array
    {
        foreach ($more as $location) {
            if (! in_array($location, $locations)) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * A posting counts when any of its places is in the country: many are
     * advertised in several countries.
     *
     * @param  list<JobLocation>  $locations
     */
    protected function inCountry(array $locations): bool
    {
        foreach ($locations as $location) {
            if ($location->country === strtoupper($this->country)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remote or hybrid from a location such as "Remote - Sweden", or null
     * when the text does not say.
     */
    protected static function remoteFromText(?string $text): ?RemoteStatus
    {
        $text = mb_strtolower((string) $text);

        return match (true) {
            str_contains($text, 'hybrid') => RemoteStatus::Hybrid,
            str_contains($text, 'remote') || str_contains($text, 'distans') => RemoteStatus::Fully,
            default => null,
        };
    }
}
