<?php

declare(strict_types=1);

namespace App\Scraping;

use Locale;

/**
 * Maps country names in English or Swedish (as found in schema.org
 * addressCountry) and ISO 3166-1 alpha-2 codes to upper case alpha-2 codes,
 * using the region names that ship with ICU.
 */
final class CountryCode
{
    /**
     * Region codes ICU knows that are not current countries: groupings and
     * withdrawn codes that share a name with their successor (UK and GB are
     * both "United Kingdom", DD and DE both "Germany" and so on).
     */
    private const array SKIPPED = [
        'EU', 'EZ', 'UN', 'QO', 'ZZ',
        'AN', 'BU', 'CS', 'DD', 'DY', 'FX', 'HV', 'NH', 'RH', 'SU', 'TP', 'UK', 'VD', 'YD', 'YU', 'ZR',
    ];

    /** Common spellings that ICU does not produce. */
    private const array ALIASES = [
        'usa' => 'US',
        'uk' => 'GB',
        'england' => 'GB',
        'scotland' => 'GB',
        'great britain' => 'GB',
        'holland' => 'NL',
        'czech republic' => 'CZ',
        'tjeckien' => 'CZ',
    ];

    /** @var array<string, string>|null lower case name or code => code */
    private static ?array $map = null;

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = mb_strtolower(trim($value));
        if ($key === '') {
            return null;
        }

        return self::map()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [];
        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $code = $first.$second;
                if (in_array($code, self::SKIPPED, true)) {
                    continue;
                }

                $english = Locale::getDisplayRegion("-{$code}", 'en');
                if ($english === false || $english === $code) {
                    continue;
                }

                $map[strtolower($code)] = $code;
                $map[mb_strtolower($english)] = $code;

                $swedish = Locale::getDisplayRegion("-{$code}", 'sv');
                if ($swedish !== false && $swedish !== $code) {
                    $map[mb_strtolower($swedish)] = $code;
                }
            }
        }

        return self::$map = [...$map, ...self::ALIASES];
    }
}
