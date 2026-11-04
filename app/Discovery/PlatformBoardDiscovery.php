<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\BoardStatus;
use App\Models\Board;
use App\Models\PlatsbankenAd;
use App\Platforms\BoardRef;
use App\Platforms\PlatformRegistry;
use Illuminate\Support\Carbon;

/**
 * Creates boards on the enabled platforms from the apply links in open
 * Platsbanken ads. The link names the account, so no DNS or fingerprint
 * check is needed: the first scrape shows whether the board can be read,
 * and marks it dead otherwise. Existing boards are left alone.
 */
final readonly class PlatformBoardDiscovery
{
    private const int CHUNK = 500;

    public function __construct(private PlatformRegistry $platforms)
    {
    }

    /**
     * @return array<string, array{accounts: int, created: int}> Platform value => counts
     */
    public function discover(): array
    {
        /** @var array<string, BoardRef> $refs */
        $refs = [];

        $ads = PlatsbankenAd::query()->whereNull('removed_at')->whereNotNull('apply_url')->select('id', 'apply_url');
        foreach ($ads->lazyById(2000) as $ad) {
            $ref = $this->platforms->recognize((string) $ad->apply_url);
            if ($ref !== null) {
                $refs[$ref->platform->value.'|'.mb_strtolower($ref->externalKey)] ??= $ref;
            }
        }

        $before = $this->boardsPerPlatform();
        $counts = [];
        foreach ($this->platforms->enabled() as $platform) {
            $counts[$platform->value] = ['accounts' => 0, 'created' => 0];
        }

        $now = Carbon::now();
        foreach (array_chunk($refs, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $ref) {
                $counts[$ref->platform->value]['accounts']++;
                $rows[] = [
                    'platform' => $ref->platform->value,
                    'external_key' => mb_substr($ref->externalKey, 0, 255),
                    'canonical_host' => $ref->host,
                    'status' => BoardStatus::New->value,
                    'home_url' => $ref->siteUrl,
                    'job_count' => 0,
                    'first_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Board::query()->insertOrIgnore($rows);
        }

        $after = $this->boardsPerPlatform();
        foreach (array_keys($counts) as $platform) {
            $counts[$platform]['created'] = ($after[$platform] ?? 0) - ($before[$platform] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function boardsPerPlatform(): array
    {
        /** @var array<string, int> $counts */
        $counts = Board::query()->toBase()->selectRaw('platform, count(*) as boards')->groupBy('platform')->pluck('boards', 'platform')
            ->map(static fn (mixed $n): int => (int) $n)->all();

        return $counts;
    }
}
