<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Models\Board;
use App\Models\PlatsbankenAd;
use App\Platforms\BoardRef;
use App\Platforms\Contracts\CrawledPlatform;
use App\Platforms\PlatformRegistry;
use Illuminate\Support\Carbon;

/**
 * Creates boards on the enabled platforms from links that name an account:
 * the apply links in open Platsbanken ads, and for platforms whose accounts
 * are paths on shared hosts (CrawledPlatform) every link Common Crawl has
 * captured there. The link names the account, so no DNS or fingerprint
 * check is needed: the first scrape shows whether the board can be read,
 * and marks it dead otherwise. Existing boards are left alone.
 */
final readonly class PlatformBoardDiscovery
{
    private const int CHUNK = 500;

    public function __construct(
        private PlatformRegistry $platforms,
        private CommonCrawlIndex $commonCrawl,
    ) {
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
                $refs[self::key($ref)] ??= $ref;
            }
        }

        return $this->create($refs, $this->platforms->enabled());
    }

    /**
     * Accounts on one platform from Common Crawl.
     *
     * @return array<string, array{accounts: int, created: int}> Platform value => counts
     */
    public function crawl(Platform $platform): array
    {
        $adapter = $this->platforms->adapter($platform);
        if (! $adapter instanceof CrawledPlatform) {
            return [];
        }

        /** @var array<string, BoardRef> $refs */
        $refs = [];
        foreach ($adapter->crawlPatterns() as $pattern) {
            foreach ($this->commonCrawl->urls($pattern) as ['url' => $url]) {
                $ref = $adapter->recognize($url);
                if ($ref !== null) {
                    $refs[self::key($ref)] ??= $ref;
                }
            }
        }

        return $this->create($refs, [$platform]);
    }

    /**
     * Enabled platforms whose accounts Common Crawl can list.
     *
     * @return list<Platform>
     */
    public function crawlable(): array
    {
        return array_values(array_filter(
            $this->platforms->enabled(),
            fn (Platform $p): bool => $this->platforms->adapter($p) instanceof CrawledPlatform,
        ));
    }

    /**
     * @param  array<string, BoardRef>  $refs
     * @param  list<Platform>  $platforms  Platforms to report on.
     * @return array<string, array{accounts: int, created: int}>
     */
    private function create(array $refs, array $platforms): array
    {
        $before = $this->boardsPerPlatform();
        $counts = [];
        foreach ($platforms as $platform) {
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

    private static function key(BoardRef $ref): string
    {
        return $ref->platform->value.'|'.mb_strtolower($ref->externalKey);
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
