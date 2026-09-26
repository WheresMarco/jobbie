<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Discovery\Contracts\CandidateSource;
use App\Enums\CandidateStage;
use App\Models\Candidate;
use App\Net\DomainName;
use Illuminate\Support\Carbon;

/**
 * Stores what a source yields as new candidates. Existing candidates are
 * never touched, so running a source twice is harmless.
 */
final readonly class CandidateCollector
{
    private const int CHUNK = 1000;

    public function __construct(private DomainName $domains)
    {
    }

    /**
     * @return array{seen: int, invalid: int, created: int}
     */
    public function collect(CandidateSource $source): array
    {
        $seen = $invalid = $created = 0;
        $now = Carbon::now();

        // Sources may yield duplicate keys; chunking must not drop items.
        foreach ($source->collect()->values()->chunk(self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $candidate) {
                $seen++;
                $host = $this->domains->normalize($candidate->host);
                if ($host === null) {
                    $invalid++;

                    continue;
                }

                $rows[$host] ??= [
                    'host' => $host,
                    'source' => $candidate->source->value,
                    'source_ref' => $candidate->sourceRef === null ? null : mb_substr($candidate->sourceRef, 0, 2048),
                    'strength' => $candidate->strength->value,
                    'stage' => CandidateStage::New->value,
                    'discovered_at' => $now,
                ];
            }

            if ($rows !== []) {
                $created += Candidate::query()->insertOrIgnore(array_values($rows));
            }
        }

        return ['seen' => $seen, 'invalid' => $invalid, 'created' => $created];
    }
}
