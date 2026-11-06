<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\BoardResolver;
use App\Discovery\Verifier;
use App\Enums\CandidateStage;
use App\Models\Candidate;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies up to 200 candidates and creates boards for the verified ones.
 * Hosts Teamtailor throttled are verified again in a later job, up to
 * MAX_ROUNDS; after that they wait for the next radar:verify. Teamtailor
 * lets about a thousand requests through and then blocks for minutes, so
 * retries are spread out: at most one every RETRY_SPACING seconds.
 */
final class VerifyCandidateBatch implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public const int SIZE = 200;

    public const int MAX_ROUNDS = 5;

    public const int RETRY_SPACING = 90;

    private const string RETRY_SLOT = 'radar:verify:retry-slot';

    /** Seconds before a retry of throttled hosts, long enough for the block to lift. */
    public const int RETRY_AFTER = 600;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<string>  $hosts
     */
    public function __construct(public array $hosts, public int $round = 0)
    {
    }

    public function handle(Verifier $verifier, BoardResolver $boards): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $candidates = Candidate::query()->whereIn('host', $this->hosts)->get()->keyBy('host');
        if ($candidates->isEmpty()) {
            return;
        }

        $strengths = [];
        foreach ($candidates as $host => $candidate) {
            $strengths[(string) $host] = $candidate->strength;
        }

        $now = Carbon::now();
        $throttled = [];
        foreach ($verifier->verifyMany($strengths) as $host => $verdict) {
            $candidate = $candidates[$host];

            $candidate->forceFill([
                'stage' => $verdict->stage,
                'reason' => $verdict->reason === null ? null : mb_substr($verdict->reason, 0, 255),
                'checked_at' => $now,
            ])->save();

            if ($verdict->stage === CandidateStage::Verified) {
                $boards->resolve($verdict->host, $verdict->finalHost, $verdict->homePageUrl, $verdict->feedTitle);
            } elseif ($verdict->stage === CandidateStage::New && in_array($verdict->reason, self::throttledReasons(), true)) {
                $throttled[] = (string) $host;
            }
        }

        if ($throttled !== [] && $this->round < self::MAX_ROUNDS) {
            $retry = (new self($throttled, $this->round + 1))->delay($this->retryAt());
            $batch = $this->batch();
            $batch === null ? dispatch($retry) : $batch->add([$retry]);
        }
    }

    /**
     * @return list<string>
     */
    private static function throttledReasons(): array
    {
        return array_map(static fn (int $status): string => "http_{$status}", Verifier::THROTTLED);
    }

    /**
     * The first free retry slot, RETRY_AFTER from now at the earliest.
     */
    private function retryAt(): CarbonInterface
    {
        return Cache::lock(self::RETRY_SLOT.':lock', 10)->block(10, function (): CarbonInterface {
            $earliest = Carbon::now()->addSeconds(self::RETRY_AFTER)->getTimestamp();
            $at = max($earliest, Cache::integer(self::RETRY_SLOT, 0));
            Cache::put(self::RETRY_SLOT, $at + self::RETRY_SPACING, Carbon::now()->addDay());

            return Carbon::createFromTimestamp($at);
        });
    }
}
