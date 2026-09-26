<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\FollowsBatches;
use App\Console\Concerns\Translates;
use App\Enums\BoardStatus;
use App\Jobs\ScrapeBoard;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Net\DomainName;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;

final class RadarScrape extends Command
{
    use FollowsBatches;
    use Translates;

    protected $signature = 'radar:scrape
        {--only-active : Only boards that had postings last time}
        {--host=* : Only these hosts (canonical or alias)}
        {--no-wait : Dispatch the batch and exit without showing progress}';

    protected $description = 'Queue a scrape of every known career site';

    public function handle(DomainName $domains): int
    {
        $boards = $this->boards($domains)->get();

        if ($boards->isEmpty()) {
            $this->components->warn($this->t('radar.scrape.nothing'));

            return self::SUCCESS;
        }

        $batch = Bus::batch($boards->map(static fn (Board $board): ScrapeBoard => new ScrapeBoard($board))->all())
            ->name('radar:scrape')
            ->allowFailures()
            ->dispatch();

        $this->components->info($this->t('radar.scrape.dispatched', ['count' => $boards->count(), 'batch' => $batch->id]));

        if ($this->option('no-wait') !== true) {
            $this->follow($batch);
        }

        $this->summary($boards->pluck('id')->all());

        return self::SUCCESS;
    }

    /**
     * @return Builder<Board>
     */
    private function boards(DomainName $domains): Builder
    {
        $query = Board::query()->orderBy('id');

        /** @var list<string> $hostOption */
        $hostOption = (array) $this->option('host');
        $hosts = array_values(array_filter(array_map($domains->normalize(...), $hostOption)));

        if ($hosts !== []) {
            $aliased = BoardAlias::query()->whereIn('host', $hosts)->pluck('board_id');

            return $query->where(fn ($q) => $q->whereIn('canonical_host', $hosts)->orWhereIn('id', $aliased));
        }

        if ($this->option('only-active') === true) {
            return $query->where('status', BoardStatus::Active);
        }

        // Dead boards are only rechecked now and then.
        $recheckBefore = now()->subDays(config()->integer('radar.dead_recheck_days'));

        return $query->where(fn ($q) => $q
            ->where('status', '!=', BoardStatus::Dead)
            ->orWhereNull('checked_at')
            ->orWhere('checked_at', '<', $recheckBefore));
    }

    /**
     * @param  list<int>  $ids
     */
    private function summary(array $ids): void
    {
        $counts = Board::query()->whereIn('id', $ids)
            ->selectRaw('status, count(*) as boards, sum(job_count) as jobs')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $this->table(
            [$this->t('radar.scrape.columns.status'), $this->t('radar.scrape.columns.boards'), $this->t('radar.scrape.columns.jobs')],
            $counts->map(static fn (Board $row): array => [
                $row->status->value,
                (string) $row->getAttribute('boards'),
                (string) $row->getAttribute('jobs'),
            ])->all(),
        );
    }
}
