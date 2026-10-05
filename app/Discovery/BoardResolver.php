<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\BoardAliasRelation;
use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\JobPosting;
use App\Net\DomainName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place boards are created. Keeps exactly one board per career
 * site, however many hosts lead to it.
 *
 * The canonical host is the host of the feed's home_page_url, or the host
 * jobs.json ended up on after redirects when there is no home page. Every
 * other host that leads there is stored as an alias.
 */
final readonly class BoardResolver
{
    /** Boards must share at least this many postings to be merged. */
    public const int MIN_SHARED_POSTINGS = 3;

    public function __construct(private DomainName $domains)
    {
    }

    /**
     * Find or create the board for a verified feed and record the aliases.
     *
     * @param  string  $host  The host that was checked.
     * @param  string|null  $finalHost  Where jobs.json ended up after redirects.
     * @param  string|null  $homePageUrl  The feed's home_page_url.
     */
    public function resolve(string $host, ?string $finalHost = null, ?string $homePageUrl = null, ?string $name = null): Board
    {
        $host = $this->domains->normalize($host) ?? $host;
        $finalHost = $finalHost === null ? $host : ($this->domains->normalize($finalHost) ?? $host);
        $homeHost = $homePageUrl === null ? null : $this->domains->normalize($homePageUrl);
        $canonical = $homeHost ?? $finalHost;

        return DB::transaction(function () use ($host, $finalHost, $canonical, $homePageUrl, $name): Board {
            $board = $this->findByHost($canonical)
                ?? $this->findByHost($finalHost)
                ?? $this->findByHost($host);

            if ($board === null) {
                $board = Board::query()->create([
                    'canonical_host' => $canonical,
                    'tt_slug' => self::slugOf($canonical) ?? self::slugOf($finalHost) ?? self::slugOf($host),
                    'kind' => self::kindOf($canonical),
                    'status' => BoardStatus::New,
                    'name' => $name,
                    'home_url' => $homePageUrl,
                    'first_seen_at' => Carbon::now(),
                ]);
            } else {
                if ($homePageUrl !== null && $board->home_url !== $homePageUrl) {
                    $board->forceFill(['home_url' => $homePageUrl])->save();
                }
                if ($board->canonical_host !== $canonical) {
                    $board = $this->recanonicalize($board, $canonical);
                }
            }

            $board = $this->alias($board, $finalHost, BoardAliasRelation::FeedHome);
            $board = $this->alias($board, $host, $host === $finalHost ? BoardAliasRelation::FeedHome : BoardAliasRelation::Redirect);

            // A merge may have kept a board with another canonical host.
            if ($board->canonical_host !== $canonical) {
                $board = $this->recanonicalize($board, $canonical);
            }

            return $board->refresh();
        });
    }

    public function findByHost(string $host): ?Board
    {
        return Board::query()->where('canonical_host', $host)->first()
            ?? BoardAlias::query()->find($host)?->board;
    }

    /**
     * Point a host at a board. A board that has the host as its canonical
     * host is the same career site and is merged.
     */
    public function alias(Board $board, string $host, BoardAliasRelation $relation): Board
    {
        if ($host === $board->canonical_host) {
            return $board;
        }

        $other = Board::query()->where('canonical_host', $host)->whereKeyNot($board->id)->first();
        if ($other !== null) {
            return $this->merge($board, $other);
        }

        BoardAlias::query()->updateOrCreate(['host' => $host], ['board_id' => $board->id, 'relation' => $relation]);

        return $board;
    }

    /**
     * Merge boards on the same platform whose active postings are exactly
     * the same set of feed ids (at least MIN_SHARED_POSTINGS). Returns the
     * board that is kept.
     */
    public function mergeDuplicates(Board $board): Board
    {
        $feedIds = $board->jobPostings()->where('active', true)->pluck('feed_id')->all();
        $count = count($feedIds);
        if ($count < self::MIN_SHARED_POSTINGS) {
            return $board;
        }

        $candidates = JobPosting::query()
            ->where('active', true)
            ->where('board_id', '!=', $board->id)
            ->whereIn('board_id', Board::query()->where('platform', $board->platform)->select('id'))
            ->whereIn('feed_id', $feedIds)
            ->groupBy('board_id')
            ->havingRaw('count(*) = ?', [$count])
            ->pluck('board_id');

        foreach ($candidates as $otherId) {
            $other = Board::query()->find($otherId);
            if ($other !== null && $other->jobPostings()->where('active', true)->count() === $count) {
                $board = $this->merge($board, $other);
            }
        }

        return $board;
    }

    /**
     * Merge two boards. The one first seen earliest is kept; the other's
     * postings and aliases move over and its host becomes an alias.
     */
    public function merge(Board $a, Board $b): Board
    {
        [$keeper, $loser] = self::keeperFirst($a, $b);

        return DB::transaction(function () use ($keeper, $loser): Board {
            $keeperFeedIds = $keeper->jobPostings()->pluck('feed_id')->all();
            $loser->jobPostings()->whereIn('feed_id', $keeperFeedIds)->delete();
            $loser->jobPostings()->update(['board_id' => $keeper->id]);
            BoardAlias::query()->where('board_id', $loser->id)->update(['board_id' => $keeper->id]);

            $loserHost = $loser->canonical_host;
            $firstSeen = self::earliest($keeper->first_seen_at, $loser->first_seen_at);
            $loser->delete();

            // The kept board takes over the loser's host when that host is
            // where the feed says its home page is.
            $homeHost = $keeper->home_url === null ? null : $this->domains->normalize($keeper->home_url);
            if ($homeHost === $loserHost) {
                $oldHost = $keeper->canonical_host;
                BoardAlias::query()->whereKey($loserHost)->delete();
                $keeper->forceFill(['canonical_host' => $loserHost, 'kind' => self::kindOf($loserHost)])->save();
                BoardAlias::query()->updateOrCreate(['host' => $oldHost], ['board_id' => $keeper->id, 'relation' => BoardAliasRelation::Merged]);
            } else {
                BoardAlias::query()->updateOrCreate(['host' => $loserHost], ['board_id' => $keeper->id, 'relation' => BoardAliasRelation::Merged]);
            }

            $jobCount = $keeper->jobPostings()->where('active', true)->count();
            $keeper->forceFill([
                'first_seen_at' => $firstSeen,
                'job_count' => $jobCount,
                'status' => $jobCount > 0 ? BoardStatus::Active : $keeper->status,
            ])->save();

            return $keeper->refresh();
        });
    }

    private function recanonicalize(Board $board, string $canonical): Board
    {
        $other = Board::query()->where('canonical_host', $canonical)->whereKeyNot($board->id)->first();
        if ($other !== null) {
            $board = $this->merge($board, $other);
            if ($board->canonical_host === $canonical) {
                return $board;
            }
        }

        $oldHost = $board->canonical_host;
        BoardAlias::query()->whereKey($canonical)->delete();
        $board->forceFill([
            'canonical_host' => $canonical,
            'kind' => self::kindOf($canonical),
            'tt_slug' => $board->tt_slug ?? self::slugOf($canonical),
        ])->save();

        BoardAlias::query()->updateOrCreate(['host' => $oldHost], ['board_id' => $board->id, 'relation' => BoardAliasRelation::Redirect]);

        return $board;
    }

    /**
     * @return array{Board, Board}
     */
    private static function keeperFirst(Board $a, Board $b): array
    {
        $aSeen = $a->first_seen_at?->getTimestamp() ?? PHP_INT_MAX;
        $bSeen = $b->first_seen_at?->getTimestamp() ?? PHP_INT_MAX;

        if ($aSeen !== $bSeen) {
            return $aSeen < $bSeen ? [$a, $b] : [$b, $a];
        }

        return $a->id < $b->id ? [$a, $b] : [$b, $a];
    }

    private static function earliest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    public static function kindOf(string $host): BoardKind
    {
        return Fingerprint::isSlugHost($host) ? BoardKind::TtSubdomain : BoardKind::Custom;
    }

    public static function slugOf(string $host): ?string
    {
        return Fingerprint::isSlugHost($host) ? explode('.', $host)[0] : null;
    }
}
