<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Discovery\WaybackIndex;
use App\Discovery\WaybackUnavailable;
use App\Discovery\WaybackWalk;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;

/**
 * Source H. Every *.teamtailor.com host the Internet Archive has captured
 * since 2012, many more than Common Crawl's recent indexes. Most of them
 * have closed, which verification sorts out; slug hosts that redirect to a
 * custom domain lead to it through source A.
 *
 * The index holds millions of keys, most of them under a few hosts (the
 * CDN, www). So it is read a slice at a time, and after each slice the
 * walk jumps past the last host seen: a large host costs one request. A
 * walk (WaybackWalk) can stop at a deadline and carry on later. When the
 * index stops answering, the walk is marked unavailable; without a walk,
 * WaybackUnavailable is thrown.
 */
final readonly class WaybackSource implements CandidateSource
{
    public const string DOMAIN = 'teamtailor.com';

    public const int SLICE = 5000;

    public function __construct(
        private WaybackIndex $index,
        private ?WaybackWalk $walk = null,
        private int $slice = self::SLICE,
    ) {
    }

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::Wayback;
    }

    /**
     * The same source moving $walk along instead of reading the whole index.
     */
    public function walking(WaybackWalk $walk): self
    {
        return new self($this->index, $walk, $this->slice);
    }

    public function collect(): LazyCollection
    {
        /** @var LazyCollection<int, CandidateData> $candidates */
        $candidates = LazyCollection::make(function () {
            $walk = $this->walk ?? new WaybackWalk();
            $seen = [];
            $first = true;

            while (! $walk->done && ! $walk->unavailable && ($walk->until === null || Carbon::now()->lessThan($walk->until))) {
                if (! $first) {
                    Sleep::for(1)->second();
                }
                $first = false;

                try {
                    $keys = $this->index->urlKeys(self::DOMAIN, $walk->from, $this->slice);
                } catch (WaybackUnavailable $e) {
                    // A walk of its own has nowhere to carry on from.
                    if ($this->walk === null) {
                        throw $e;
                    }
                    // Ending here keeps what was found for the collector.
                    $walk->unavailable = true;

                    return;
                }
                foreach ($keys as $key) {
                    $host = WaybackIndex::host($key);
                    $host = $host === null ? null : CommonCrawlSource::slugHost($host);
                    if ($host !== null && ! isset($seen[$host])) {
                        $seen[$host] = true;

                        yield new CandidateData($host, CandidateSourceType::Wayback, Strength::Strong);
                    }
                }

                // Only an empty slice marks the end: the index sometimes
                // answers with fewer keys than asked for midway.
                $next = $keys === [] ? null : WaybackIndex::after($keys[array_key_last($keys)]);
                if ($next === null || ($walk->from !== null && strcmp($next, $walk->from) <= 0)) {
                    $walk->done = true;
                } else {
                    $walk->from = $next;
                }
            }
        });

        return $candidates;
    }
}
