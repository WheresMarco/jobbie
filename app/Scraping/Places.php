<?php

declare(strict_types=1);

namespace App\Scraping;

use Normalizer;

/**
 * Places from the free text that recruitment systems put in a location
 * field: "Stockholm, Sweden", "Remote - Sweden", "London; Stockholm" or
 * just "Göteborg". A part that names a country gives the country; else a
 * known place name does, from the lists in resources/places (one file per
 * country, named by its ISO code).
 */
final class Places
{
    /** Words around a place that are not part of it. */
    private const array NOISE = ['remote', 'hybrid', 'onsite', 'on-site', 'office', 'hq', 'headquarters', 'area', 'region', 'county', 'metropolitan'];

    /** @var array<string, array{0: string, 1: string}>|null folded name => [name, country] */
    private static ?array $known = null;

    /**
     * Every place in the text, which may list several.
     *
     * @return list<JobLocation>
     */
    public static function parseAll(?string $text): array
    {
        $locations = [];
        foreach (preg_split('~\s*(?:;|\||/|\bor\b|\beller\b|&)\s*~iu', (string) $text) ?: [] as $segment) {
            $location = self::parse($segment);
            if ($location !== null && ! in_array($location, $locations)) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * One place, or null when the text has neither a city nor a country.
     */
    public static function parse(?string $text): ?JobLocation
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', (string) $text));
        if ($text === '') {
            return null;
        }

        $country = null;
        $city = null;
        foreach (preg_split('~\s*(?:,|\s-\s|–|\(|\))\s*~u', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || in_array(mb_strtolower($part), self::NOISE, true)) {
                continue;
            }

            $code = CountryCode::normalize($part);
            if ($code !== null) {
                $country ??= $code;

                continue;
            }

            $city ??= $part;
        }

        if ($city !== null && ($known = self::known($city)) !== null) {
            [$name, $knownCountry] = $known;
            if ($country === null || $country === $knownCountry) {
                return new JobLocation($name, $knownCountry);
            }
        }

        return $city === null && $country === null ? null : new JobLocation($city, $country);
    }

    /**
     * A known place: the whole text, or its first word ("Stockholm HQ").
     *
     * @return array{0: string, 1: string}|null [name, country]
     */
    private static function known(string $city): ?array
    {
        $places = self::places();
        $folded = self::fold($city);

        return $places[$folded] ?? $places[explode(' ', $folded)[0]] ?? null;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private static function places(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }

        $known = [];
        foreach (glob(resource_path('places/*.txt')) ?: [] as $file) {
            $country = strtoupper(pathinfo($file, PATHINFO_FILENAME));
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                [$alias, $name] = array_map(trim(...), explode('=', $line, 2)) + [1 => null];
                $known[self::fold($alias)] = [$name ?? $alias, $country];
            }
        }

        return self::$known = $known;
    }

    /**
     * Lower case without diacritics, so "Malmo" finds Malmö.
     */
    private static function fold(string $value): string
    {
        $decomposed = Normalizer::normalize(mb_strtolower(trim($value)), Normalizer::FORM_D);

        return (string) preg_replace('~\p{Mn}+~u', '', $decomposed === false ? mb_strtolower(trim($value)) : $decomposed);
    }
}
