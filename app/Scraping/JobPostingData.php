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
    ) {}

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
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (?string $v): bool => $v !== null && $v !== '')));
    }
}
