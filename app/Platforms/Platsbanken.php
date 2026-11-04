<?php

declare(strict_types=1);

namespace App\Platforms;

use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Models\Board;
use App\Scraping\CountryCode;
use App\Scraping\JobFeedParser;
use App\Scraping\JobLocation;
use App\Scraping\JobPostingData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Platsbanken as a job source: one board, filled by JobStreamSync from the
 * same JobTech JobStream ads that discovery reads apply links from.
 */
final class Platsbanken
{
    public const string KEY = 'platsbanken';

    public const string HOST = 'arbetsformedlingen.se';

    /** JobStream timestamps have no offset and are Swedish local time. */
    private const string TIMEZONE = 'Europe/Stockholm';

    public static function board(): Board
    {
        return Board::query()->firstOrCreate(
            ['platform' => Platform::Platsbanken, 'external_key' => self::KEY],
            [
                'canonical_host' => self::HOST,
                'status' => BoardStatus::New,
                'name' => 'Platsbanken',
                'home_url' => 'https://arbetsformedlingen.se/platsbanken/',
                'first_seen_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Map a JobStream ad (not a removal) to posting attributes.
     *
     * @param  array<mixed>  $ad
     */
    public static function posting(array $ad): ?JobPostingData
    {
        $id = is_scalar($ad['id'] ?? null) ? (string) $ad['id'] : '';
        $title = self::str($ad['headline'] ?? null);
        if ($id === '' || $title === null) {
            return null;
        }

        $employer = self::arr($ad['employer'] ?? null);
        $description = self::arr($ad['description'] ?? null);
        $address = self::arr($ad['workplace_address'] ?? null);

        $city = self::str($address['city'] ?? null) ?? self::str($address['municipality'] ?? null);
        $country = CountryCode::normalize(self::str($address['country'] ?? null));
        $location = new JobLocation($city, $country);

        return new JobPostingData(
            feedId: $id,
            title: $title,
            url: self::str($ad['webpage_url'] ?? null) ?? "https://arbetsformedlingen.se/platsbanken/annonser/{$id}",
            company: self::str($employer['name'] ?? null) ?? self::str($employer['workplace'] ?? null),
            locations: $city === null && $country === null ? [] : [$location],
            remote: null,
            department: null,
            employmentType: self::str(self::arr($ad['employment_type'] ?? null)['label'] ?? null),
            salaryText: self::str($ad['salary_description'] ?? null),
            postedAt: self::date($ad['publication_date'] ?? null),
            description: JobFeedParser::description(self::str($description['text_formatted'] ?? null) ?? self::str($description['text'] ?? null)),
        );
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, self::TIMEZONE)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
