<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Discovery\Fingerprint;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use App\Models\Domain;
use App\Net\Contracts\DnsResolver;
use App\Net\DnsRecordType;
use App\Net\DnsStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Source E. Reads the TXT records on every known base domain. A
 * Teamtailor verification record (Fingerprint::TXT_PATTERNS) marks the
 * domain as a customer and its subdomains are probed with a wider prefix
 * list. Customers where nothing is found show up on the statistics page.
 *
 * The TXT pattern was confirmed against one domain only (see README);
 * domains are rechecked every txt_recheck_days.
 */
final readonly class TxtRecordSource implements CandidateSource
{
    private const int BATCH = 200;

    /**
     * @param  list<string>  $prefixes
     */
    public function __construct(
        private DnsResolver $dns,
        private array $prefixes,
        private int $recheckDays,
    ) {
    }

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::Txt;
    }

    public function collect(): LazyCollection
    {
        /** @var LazyCollection<int, CandidateData> $candidates */
        $candidates = LazyCollection::make(function () {
            $recheckBefore = Carbon::now()->subDays($this->recheckDays);

            $domains = Domain::query()
                ->where(fn ($q) => $q->whereNull('txt_checked_at')->orWhere('txt_checked_at', '<', $recheckBefore))
                ->orderBy('base_domain')
                ->lazyById(self::BATCH, 'base_domain');

            foreach ($domains->chunk(self::BATCH) as $chunk) {
                $bases = $chunk->pluck('base_domain')->map(static fn (mixed $base): string => (string) $base)->values()->all();
                $answers = $this->dns->resolveMany($bases, DnsRecordType::TXT);
                $now = Carbon::now();

                foreach ($bases as $base) {
                    $answer = $answers[$base] ?? null;
                    // Unknown is not negative: try again next run.
                    if ($answer === null || $answer->status === DnsStatus::Error) {
                        continue;
                    }

                    $match = Fingerprint::matchTxt($answer->values) !== null;
                    Domain::query()->whereKey($base)->update(['txt_match' => $match, 'txt_checked_at' => $now]);

                    if ($match) {
                        foreach ($this->prefixes as $prefix) {
                            yield new CandidateData("{$prefix}.{$base}", CandidateSourceType::Txt, Strength::Strong, $prefix);
                        }
                    }
                }
            }
        });

        return $candidates;
    }
}
