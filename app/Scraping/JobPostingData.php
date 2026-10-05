<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\RemoteStatus;
use Carbon\CarbonImmutable;

/**
 * One feed item mapped to JobPosting attributes.
 */
final readonly class JobPostingData
{
    /**
     * @param  list<JobLocation>  $locations
     */
    public function __construct(
        public string $feedId,
        public string $title,
        public string $url,
        public ?string $company,
        public array $locations,
        public ?RemoteStatus $remote,
        public ?string $department,
        public ?string $employmentType,
        public ?string $salaryText,
        public ?CarbonImmutable $postedAt,
        public ?string $description,
        public ?string $orgNr = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function cities(): array
    {
        return self::unique(array_map(static fn (JobLocation $l): ?string => $l->city, $this->locations));
    }

    /**
     * @return list<string>
     */
    public function countries(): array
    {
        return self::unique(array_map(static fn (JobLocation $l): ?string => $l->country, $this->locations));
    }

    /**
     * A JSON list of strings as stored in job_postings.cities and countries.
     *
     * @return list<string>
     */
    public static function decodeList(string $json): array
    {
        $values = json_decode($json, true);

        return is_array($values) ? self::unique(array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, array_values($values))) : [];
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (?string $v): bool => $v !== null && $v !== '')));
    }
}
