<?php

declare(strict_types=1);

namespace App\Discovery\JobStream;

use App\Scraping\CountryCode;
use App\Scraping\JobLocation;
use App\Scraping\JobPostingData;
use App\Scraping\Teamtailor\JobFeedParser;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Maps one JobStream ad to JobPosting attributes, for the Platsbanken
 * board. Field names follow the JobTech ad schema (headline, description,
 * employer, workplace_address, employment_type and so on).
 */
final readonly class PlatsbankenAdParser
{
    public const string AD_URL = 'https://arbetsformedlingen.se/platsbanken/annonser/';

    /** JobTech timestamps have no offset and are Swedish local time. */
    private const string TIMEZONE = 'Europe/Stockholm';

    /**
     * @param  array<mixed>  $ad
     */
    public function parse(array $ad): ?JobPostingData
    {
        $id = is_scalar($ad['id'] ?? null) ? trim((string) $ad['id']) : '';
        $title = self::string($ad['headline'] ?? null);
        if ($id === '' || $title === null) {
            return null;
        }

        $employer = self::array($ad['employer'] ?? null);
        $description = self::array($ad['description'] ?? null);
        $orgNr = is_scalar($employer['organization_number'] ?? null)
            ? (string) preg_replace('/\D/', '', (string) $employer['organization_number'])
            : '';

        return new JobPostingData(
            feedId: $id,
            title: $title,
            url: self::string($ad['webpage_url'] ?? null) ?? self::AD_URL.rawurlencode($id),
            company: self::string($employer['name'] ?? null),
            locations: self::locations(self::array($ad['workplace_address'] ?? null)),
            remote: null,
            department: null,
            employmentType: self::label($ad['employment_type'] ?? null),
            salaryText: self::string($ad['salary_description'] ?? null) ?? self::label($ad['salary_type'] ?? null),
            postedAt: self::date($ad['publication_date'] ?? null),
            description: JobFeedParser::description(self::string($description['text'] ?? null)),
            orgNr: $orgNr === '' ? null : substr($orgNr, -12),
        );
    }

    /**
     * The workplace municipality. Ads with a municipality but no country
     * are in Sweden: JobTech only gives municipalities for Swedish places.
     *
     * @param  array<mixed>  $address
     * @return list<JobLocation>
     */
    private static function locations(array $address): array
    {
        $city = self::string($address['municipality'] ?? null) ?? self::string($address['city'] ?? null);
        $country = CountryCode::normalize(self::string($address['country'] ?? null));
        if ($country === null && self::string($address['municipality'] ?? null) !== null) {
            $country = 'SE';
        }

        return $city === null && $country === null ? [] : [new JobLocation($city, $country)];
    }

    private static function label(mixed $concept): ?string
    {
        return is_array($concept) ? self::string($concept['label'] ?? null) : null;
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::TIMEZONE)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
