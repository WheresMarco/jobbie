<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Enums\BoardKind;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use App\Models\Board;
use App\Models\Domain;
use App\Net\DomainName;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Source C. Tries the configured prefixes (career, jobb, karriar and so
 * on) on every known base domain: domains from JobStream and seeds, plus
 * the registrable domains of known custom career sites. Weak: only hosts
 * with a DNS fingerprint are checked over HTTP.
 *
 * source_ref holds the prefix, so hits can be counted per prefix and the
 * list pruned from data.
 */
final readonly class PrefixSource implements CandidateSource
{
    /**
     * @param  list<string>  $prefixes
     */
    public function __construct(
        private array $prefixes,
        private DomainName $domains,
    ) {
    }

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::Prefix;
    }

    public function collect(): LazyCollection
    {
        /** @var LazyCollection<int, CandidateData> $candidates */
        $candidates = LazyCollection::make(function () {
            $now = Carbon::now();

            foreach (Domain::query()->orderBy('base_domain')->lazyById(1000, 'base_domain') as $domain) {
                foreach ($this->candidatesFor($domain->base_domain) as $candidate) {
                    yield $candidate;
                }
                Domain::query()->whereKey($domain->base_domain)->update(['probed_at' => $now]);
            }

            $customBases = Board::query()->where('kind', BoardKind::Custom)->pluck('canonical_host')
                ->map(fn ($host): ?string => $this->domains->registrable((string) $host))
                ->filter()
                ->unique();

            foreach ($customBases as $base) {
                foreach ($this->candidatesFor((string) $base) as $candidate) {
                    yield $candidate;
                }
            }
        });

        return $candidates;
    }

    /**
     * @return list<CandidateData>
     */
    private function candidatesFor(string $baseDomain): array
    {
        return array_map(
            static fn (string $prefix): CandidateData => new CandidateData("{$prefix}.{$baseDomain}", CandidateSourceType::Prefix, Strength::Weak, $prefix),
            $this->prefixes,
        );
    }
}
