<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Discovery\CandidateCollector;
use App\Discovery\Sources\WaybackSource;
use App\Discovery\WaybackUnavailable;
use App\Discovery\WaybackWalk;
use App\Jobs\Concerns\RunsOnLongQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Source H, walked for at most RUN_MINUTES per job: a full walk can take
 * hours. Each job queues the next one, from where it stopped, ahead of the
 * rest of its chain. When the index is unavailable the next job waits
 * RETRY_AFTER seconds; after MAX_FAILURES in a row without progress the
 * walk fails.
 */
final class CollectWaybackCandidates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsOnLongQueue;

    public const int RUN_MINUTES = 40;

    public const int RETRY_AFTER = 1800;

    public const int MAX_FAILURES = 6;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public ?string $from = null,
        public int $failures = 0,
    ) {
        $this->useLongQueue();
    }

    public function uniqueId(): string
    {
        return 'wayback:'.($this->from ?? '');
    }

    public function handle(WaybackSource $source, CandidateCollector $collector): void
    {
        $walk = new WaybackWalk($this->from, Carbon::now()->addMinutes(self::RUN_MINUTES)->toImmutable());

        $counts = $collector->collect($source->walking($walk));

        Log::info('Candidates collected', ['source' => $source->type()->value, 'from' => $this->from, 'to' => $walk->from, 'done' => $walk->done, ...$counts]);

        if ($walk->unavailable) {
            // Failures only count in a row: progress resets them.
            $failures = $walk->from === $this->from ? $this->failures + 1 : 1;
            if ($failures >= self::MAX_FAILURES) {
                throw new WaybackUnavailable("The Wayback CDX API did not answer {$failures} times in a row");
            }

            Log::warning('Wayback unavailable, retrying later', ['from' => $walk->from, 'failures' => $failures]);
            $this->prependToChain((new self($walk->from, $failures))->delay(self::RETRY_AFTER));
        } elseif (! $walk->done) {
            $this->prependToChain(new self($walk->from));
        }
    }
}
