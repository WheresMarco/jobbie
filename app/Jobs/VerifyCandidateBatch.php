<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\BoardResolver;
use App\Discovery\Verifier;
use App\Enums\CandidateStage;
use App\Models\Candidate;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Verifies up to 200 candidates and creates boards for the verified ones.
 */
final class VerifyCandidateBatch implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public const int SIZE = 200;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<string>  $hosts
     */
    public function __construct(public array $hosts)
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
        foreach ($verifier->verifyMany($strengths) as $host => $verdict) {
            $candidate = $candidates[$host];

            $candidate->forceFill([
                'stage' => $verdict->stage,
                'reason' => $verdict->reason === null ? null : mb_substr($verdict->reason, 0, 255),
                'checked_at' => $now,
            ])->save();

            if ($verdict->stage === CandidateStage::Verified) {
                $boards->resolve($verdict->host, $verdict->finalHost, $verdict->homePageUrl, $verdict->feedTitle);
            }
        }
    }
}
