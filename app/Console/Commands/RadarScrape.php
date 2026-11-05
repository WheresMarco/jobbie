<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\FollowsBatches;
use App\Console\Concerns\Translates;
use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Jobs\ScrapeBoard;
use App\Jobs\SyncJobStream;
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
        {--platform=* : Only boards on these platforms (teamtailor, varbi, smartrecruiters, workday, greenhouse, lever, ashby, platsbanken)}
        {--no-wait : Dispatch the batch and exit without showing progress}';

    protected $description = 'Queue a scrape of every known career site, and a sync of Platsbanken';

    public function handle(DomainName $domains): int
    {
        // Platsbanken is read from JobStream rather than scraped: one long
        // job for the whole board, on the queue for long jobs.
        $platsbanken = $this->includesPlatsbanken();
        if ($platsbanken) {
            SyncJobStream::dispatch();
            $this->components->info($this->t('radar.scrape.platsbanken'));
        }

        $boards = $this->boards($domains)->get();

        if ($boards->isEmpty()) {
            if (! $platsbanken) {
                $this->components->warn($this->t('radar.scrape.nothing'));
            }

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
        $query = Board::query()->whereIn('platform', Platform::scraped())->orderBy('id');

        $platforms = $this->platforms();
        if ($platforms !== []) {
            $query->whereIn('platform', $platforms);
        }

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

        // Dead boards are only rechecked now and then, and so are empty ones
        // on platforms used across the world: most have nothing here.
        $recheckBefore = now()->subDays(config()->integer('radar.dead_recheck_days'));
        $emptyBefore = now()->subDays(config()->integer('radar.platforms.empty_recheck_days'));

        return $query
            ->where(fn ($q) => $q
                ->where('status', '!=', BoardStatus::Dead)
                ->orWhereNull('checked_at')
                ->orWhere('checked_at', '<', $recheckBefore))
            ->where(fn ($q) => $q
                ->where('status', '!=', BoardStatus::Empty)
                ->orWhereNotIn('platform', Platform::countryFiltered())
                ->orWhereNull('checked_at')
                ->orWhere('checked_at', '<', $emptyBefore));
    }

    /**
     * Platform names from --platform, or none for all.
     *
     * @return list<string>
     */
    private function platforms(): array
    {
        /** @var list<string> $option */
        $option = (array) $this->option('platform');

        return array_map(
            static fn (string $name): string => strtolower(trim($name)),
            array_merge([], ...array_map(static fn (string $o): array => explode(',', $o), $option)),
        );
    }

    /**
     * Whether this run covers the Platsbanken board: once it exists, it is
     * synced along with every scrape that is not limited to other boards.
     */
    private function includesPlatsbanken(): bool
    {
        $platforms = $this->platforms();

        return (array) $this->option('host') === []
            && ($platforms === [] || in_array(Platform::Platsbanken->value, $platforms, true))
            && Board::query()->where('platform', Platform::Platsbanken)->exists();
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
