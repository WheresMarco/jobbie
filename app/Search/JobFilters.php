<?php

declare(strict_types=1);

namespace App\Search;

use App\Enums\BoardKind;

/**
 * Validated filter state of the job browser.
 */
final readonly class JobFilters
{
    public const array REMOTE_OPTIONS = ['fully', 'hybrid', 'onsite', 'unknown'];

    public const array PUBLISHED_OPTIONS = [1, 7, 30];

    private const int MAX_TERMS = 8;

    /**
     * @param  list<string>  $remote  Values from REMOTE_OPTIONS.
     */
    public function __construct(
        public string $query = '',
        public ?string $country = null,
        public ?string $city = null,
        public array $remote = [],
        public ?string $department = null,
        public ?int $publishedDays = null,
        public bool $newOnly = false,
        public ?BoardKind $kind = null,
        public bool $notInPlatsbanken = false,
        public bool $unseenOnly = false,
        public bool $matchingOnly = false,
    ) {
    }

    /**
     * Build from untrusted input (URL parameters), dropping invalid values.
     *
     * @param  array<mixed>  $remote
     */
    public static function fromInput(
        string $query = '',
        string $country = '',
        string $city = '',
        array $remote = [],
        string $department = '',
        string $published = '',
        bool $newOnly = false,
        string $kind = '',
        bool $notInPlatsbanken = false,
        bool $unseenOnly = false,
        bool $matchingOnly = false,
    ): self {
        $country = strtoupper(trim($country));

        return new self(
            query: mb_substr(trim($query), 0, 200),
            country: preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            city: self::text($city),
            remote: array_values(array_intersect(self::REMOTE_OPTIONS, array_map(strval(...), array_filter($remote, is_scalar(...))))),
            department: self::text($department),
            publishedDays: in_array((int) $published, self::PUBLISHED_OPTIONS, true) ? (int) $published : null,
            newOnly: $newOnly,
            kind: BoardKind::tryFrom($kind),
            notInPlatsbanken: $notInPlatsbanken,
            unseenOnly: $unseenOnly,
            matchingOnly: $matchingOnly,
        );
    }

    /**
     * Search words without boolean operators or LIKE wildcards. Words are
     * matched as prefixes, so "utveckl" finds "utvecklare".
     *
     * @return list<string>
     */
    public function terms(): array
    {
        $words = preg_split('/[\s+\-<>()~*"@%_]+/u', mb_strtolower($this->query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_TERMS);
    }

    public function cacheKey(): string
    {
        return md5(serialize([
            $this->terms(), $this->country, $this->city, $this->remote, $this->department,
            $this->publishedDays, $this->newOnly, $this->kind?->value, $this->notInPlatsbanken, $this->unseenOnly, $this->matchingOnly,
        ]));
    }

    public function isEmpty(): bool
    {
        return $this->cacheKey() === (new self())->cacheKey();
    }

    private static function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
