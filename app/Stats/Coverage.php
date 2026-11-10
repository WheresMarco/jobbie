<?php

declare(strict_types=1);

namespace App\Stats;

use Illuminate\Support\Facades\Cache;

/**
 * The headline numbers shown on the landing and welcome pages, cached so
 * that counting never slows those pages down.
 */
final readonly class Coverage
{
    public const int TTL = 600;

    public function __construct(
        public int $postings,
        public int $employers,
    ) {
    }

    public static function cached(RadarStats $stats): self
    {
        /** @var array{int, int} $numbers */
        $numbers = Cache::remember('coverage', self::TTL, static fn (): array => [
            $stats->activePostings(),
            $stats->activeEmployers(),
        ]);

        return new self(...$numbers);
    }
}
