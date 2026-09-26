<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\RemoteStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Maps a JSON Feed item with its schema.org _jobposting block (plus
 * optional RSS enrichment) to JobPosting attributes.
 */
final class JobFeedParser
{
    public const int DESCRIPTION_LIMIT = 8000;

    /**
     * @param  array<mixed>  $item
     */
    public function parse(array $item, ?RssEnrichment $rss = null, ?string $feedTitle = null): ?JobPostingData
    {
        $posting = self::arr($item['_jobposting'] ?? null);

        $id = $item['id'] ?? null;
        $title = self::str($item['title'] ?? null) ?? self::str($posting['title'] ?? null);
        $url = self::str($item['url'] ?? null) ?? self::str($posting['url'] ?? null);

        if ((! is_string($id) && ! is_int($id)) || $title === null || $url === null) {
            return null;
        }

        return new JobPostingData(
            feedId: (string) $id,
            title: self::clean($title),
            url: $url,
            company: self::str(self::arr($posting['hiringOrganization'] ?? null)['name'] ?? null) ?? $feedTitle,
            locations: self::locations($posting['jobLocation'] ?? null),
            remote: $rss?->remote ?? (self::isTelecommute($posting) ? RemoteStatus::Fully : null),
            department: $rss?->department,
            employmentType: self::employmentType($posting['employmentType'] ?? null),
            salaryText: self::salary($posting['baseSalary'] ?? null),
            postedAt: self::date($posting['datePosted'] ?? null) ?? self::date($item['date_published'] ?? null),
            description: self::description(
                self::str($item['content_html'] ?? null) ?? self::str($posting['description'] ?? null),
            ),
        );
    }

    /**
     * Editors often paste already encoded HTML, so entities are decoded twice
     * before tags are stripped.
     */
    public static function description(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = html_entity_decode(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text);
        $text = (string) preg_replace('~<br\s*/?>|</(p|div|li|h[1-6]|tr|ul|ol)>~i', "\n", $text);
        $text = strip_tags($text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t\r\f]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::DESCRIPTION_LIMIT
            ? rtrim(mb_substr($text, 0, self::DESCRIPTION_LIMIT - 1)).'…'
            : $text;
    }

    /**
     * @return list<JobLocation>
     */
    private static function locations(mixed $value): array
    {
        $places = self::arr($value);
        if ($places !== [] && ! array_is_list($places)) {
            $places = [$places];
        }

        $locations = [];
        foreach ($places as $place) {
            $address = self::arr($place)['address'] ?? null;
            if (is_string($address)) {
                $locations[] = new JobLocation(self::clean($address), null);

                continue;
            }

            $address = self::arr($address);
            $country = $address['addressCountry'] ?? null;
            if (is_array($country)) {
                $country = $country['name'] ?? null;
            }

            $city = self::str($address['addressLocality'] ?? null);
            $location = new JobLocation(
                $city === null ? null : self::clean($city),
                CountryCode::normalize(is_string($country) ? $country : null),
            );

            if (($location->city !== null || $location->country !== null) && ! in_array($location, $locations)) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * @param  array<mixed>  $posting
     */
    private static function isTelecommute(array $posting): bool
    {
        $types = $posting['jobLocationType'] ?? null;

        return in_array('TELECOMMUTE', array_map(strtoupper(...), array_filter((array) $types, is_string(...))), true);
    }

    private static function employmentType(mixed $value): ?string
    {
        $types = array_values(array_filter((array) $value, static fn (mixed $v): bool => is_string($v) && $v !== ''));

        return $types === [] ? null : implode(', ', $types);
    }

    /**
     * For example "30 000–40 000 SEK/MONTH".
     */
    private static function salary(mixed $value): ?string
    {
        $salary = self::arr($value);
        $quantity = self::arr($salary['value'] ?? null);

        $min = self::number($quantity['minValue'] ?? null);
        $max = self::number($quantity['maxValue'] ?? null);
        $single = self::number($quantity['value'] ?? null) ?? self::number($salary['value'] ?? null);

        $amount = match (true) {
            $min !== null && $max !== null && $min !== $max => self::format($min).'–'.self::format($max),
            $min !== null => self::format($min),
            $max !== null => self::format($max),
            $single !== null => self::format($single),
            default => null,
        };

        if ($amount === null) {
            return null;
        }

        $currency = self::str($salary['currency'] ?? null);
        $unit = self::str($quantity['unitText'] ?? null);

        return $amount.($currency === null ? '' : " {$currency}").($unit === null ? '' : '/'.strtoupper($unit));
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }

        return is_string($value) && is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function format(float $value): string
    {
        return str_replace(',', ' ', number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2, '.', ','));
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
