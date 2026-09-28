<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Enums\MarkStatus;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A snapshot of what one user has seen and marked, for the job list.
 */
final readonly class UserMarks
{
    /**
     * @param  array<int, string|null>  $statuses  Posting id => MarkStatus value, or null when only seen.
     * @param  string|null  $seenUntil  UTC "Y-m-d H:i:s"; postings first seen up to then count as seen.
     */
    public function __construct(
        public int $userId,
        public array $statuses,
        public ?string $seenUntil,
        public int $version,
    ) {
    }

    public function status(int $postingId): ?MarkStatus
    {
        $value = $this->statuses[$postingId] ?? null;

        return $value === null ? null : MarkStatus::tryFrom($value);
    }

    public function isUnseen(int $postingId, DateTimeInterface|string $firstSeenAt): bool
    {
        if (array_key_exists($postingId, $this->statuses)) {
            return false;
        }

        if ($this->seenUntil === null) {
            return true;
        }

        $firstSeen = is_string($firstSeenAt) ? $firstSeenAt : Carbon::instance($firstSeenAt)->utc()->format('Y-m-d H:i:s');

        return $firstSeen > $this->seenUntil;
    }

    public function cacheKey(): string
    {
        return "u{$this->userId}v{$this->version}";
    }
}
