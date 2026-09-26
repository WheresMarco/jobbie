<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\FollowsBatches;
use App\Console\Concerns\Translates;
use App\Discovery\SourceRegistry;
use App\Enums\CandidateStage;
use App\Jobs\VerifyCandidateBatch;
use App\Models\Candidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class RadarVerify extends Command
{
    use FollowsBatches;
    use Translates;

    protected $signature = 'radar:verify
        {--source= : Only candidates from this source}
        {--limit= : At most this many candidates}
        {--no-wait : Dispatch the batch and exit without showing progress}';

    protected $description = 'Queue verification of new candidates and old rejections';

    public function handle(): int
    {
        $query = Candidate::query()
            ->where(function ($q): void {
                $recheckBefore = now()->subDays(config()->integer('radar.rejected_recheck_days'));
                $q->where('stage', CandidateStage::New)
                    ->orWhere(fn ($q) => $q
                        ->whereIn('stage', [CandidateStage::Rejected, CandidateStage::FingerprintMatch])
                        ->where('checked_at', '<', $recheckBefore));
            })
            // Strong candidates first.
            ->orderBy('strength')
            ->orderBy('discovered_at');

        $sourceOption = $this->option('source');
        if (is_string($sourceOption) && $sourceOption !== '') {
            $source = SourceRegistry::typeFromName($sourceOption);
            if ($source === null) {
                $this->components->error($this->t('radar.discover.unknown_source', ['source' => $sourceOption]));

                return self::INVALID;
            }
            $query->where('source', $source);
        }

        $limit = $this->option('limit');
        if (is_numeric($limit) && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        /** @var list<string> $hosts */
        $hosts = $query->pluck('host')->all();
        if ($hosts === []) {
            $this->components->info($this->t('radar.verify.nothing'));

            return self::SUCCESS;
        }

        $jobs = array_map(
            static fn (array $chunk): VerifyCandidateBatch => new VerifyCandidateBatch($chunk),
            array_chunk($hosts, VerifyCandidateBatch::SIZE),
        );

        $batch = Bus::batch($jobs)->name('radar:verify')->allowFailures()->dispatch();
        $this->components->info($this->t('radar.verify.dispatched', ['count' => count($hosts), 'batches' => count($jobs)]));

        if ($this->option('no-wait') !== true) {
            $this->follow($batch);
            $this->summary($hosts);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function summary(array $hosts): void
    {
        $counts = [];
        foreach (array_chunk($hosts, 1000) as $chunk) {
            foreach (Candidate::query()->whereIn('host', $chunk)->get(['host', 'stage']) as $candidate) {
                $counts[$candidate->stage->value] = ($counts[$candidate->stage->value] ?? 0) + 1;
            }
        }
        ksort($counts);

        $this->table(
            [$this->t('radar.verify.columns.stage'), $this->t('radar.verify.columns.candidates')],
            array_map(static fn (string $stage, int $count): array => [$stage, (string) $count], array_keys($counts), $counts),
        );
    }
}
