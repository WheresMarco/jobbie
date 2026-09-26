<?php

declare(strict_types=1);

namespace App\Scraping;

/**
 * All items read from a board's jobs.json.
 */
final readonly class Feed
{
    /**
     * @param  list<array<string, mixed>>  $items  Unique by id, in feed order.
     * @param  string  $finalUrl  Where the first page ended up after redirects.
     * @param  bool  $truncated  True when paging stopped before the end, so missing ads must not be deactivated.
     */
    public function __construct(
        public ?string $title,
        public ?string $homePageUrl,
        public string $finalUrl,
        public array $items,
        public bool $truncated,
        public int $pages,
    ) {}

    public function finalHost(): string
    {
        return strtolower((string) parse_url($this->finalUrl, PHP_URL_HOST));
    }
}
