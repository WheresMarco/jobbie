<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\BoardResolver;
use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Models\Board;
use App\Models\Candidate;
use App\Net\DomainName;
use App\Scraping\Feed;
use Illuminate\Support\Carbon;

/**
 * Source A. Every scrape tells us where jobs.json ended up after redirects
 * and which host the feed calls home. Both lead to the board, so they are
 * recorded as aliases (and as verified candidates for the statistics)
 * without any extra requests.
 */
final readonly class RedirectSource
{
    public function __construct(
        private BoardResolver $boards,
        private DomainName $domains,
    ) {
    }

    /**
     * @return Board The board after any re-canonicalisation or merge.
     */
    public function record(Board $board, Feed $feed): Board
    {
        $previousHost = $board->canonical_host;
        $board = $this->boards->resolve($board->canonical_host, $feed->finalHost() ?: null, $feed->homePageUrl, $feed->title);

        $hosts = array_filter([
            $feed->finalHost(),
            $feed->homePageUrl === null ? null : $this->domains->normalize($feed->homePageUrl),
        ], static fn (?string $h): bool => $h !== null && $h !== '' && $h !== $previousHost);

        if ($hosts !== []) {
            $now = Carbon::now();
            Candidate::query()->insertOrIgnore(array_map(static fn (string $host): array => [
                'host' => $host,
                'source' => CandidateSourceType::Redirect->value,
                'source_ref' => $previousHost,
                'strength' => Strength::Strong->value,
                'stage' => CandidateStage::Verified->value,
                'discovered_at' => $now,
                'checked_at' => $now,
            ], array_values(array_unique($hosts))));
        }

        return $this->boards->mergeDuplicates($board);
    }
}
