<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Enums\CandidateStage;
use App\Stats\RadarStats as Stats;
use App\Stats\SourceStats;
use Illuminate\Console\Command;

final class RadarStats extends Command
{
    use Translates;

    protected $signature = 'radar:stats';

    protected $description = 'Show candidates, verified sites and postings per discovery source';

    public function handle(Stats $stats): int
    {
        $stages = array_column(CandidateStage::cases(), 'value');

        $this->components->twoColumnDetail('<fg=green>'.$this->t('radar.stats.sources').'</>');
        $this->table(
            [
                $this->t('radar.stats.columns.source'),
                ...array_map(fn (string $stage): string => $this->t("radar.stages.{$stage}"), $stages),
                $this->t('radar.stats.columns.tested'),
                $this->t('radar.stats.columns.active_boards'),
                $this->t('radar.stats.columns.postings'),
            ],
            array_map(fn (SourceStats $s): array => [
                $this->t("radar.sources.{$s->source->value}"),
                ...array_map(static fn (string $stage): string => (string) ($s->stages[$stage] ?? 0), $stages),
                (string) $s->tested(),
                (string) $s->activeBoards,
                (string) $s->postings,
            ], $stats->sources()),
        );

        $this->components->twoColumnDetail('<fg=green>'.$this->t('radar.stats.boards').'</>');
        foreach ($stats->boardsByKind() as $kind => $count) {
            $this->components->twoColumnDetail($this->t("radar.kinds.{$kind}"), (string) $count);
        }
        foreach ($stats->boardsByStatus() as $status => $count) {
            $this->components->twoColumnDetail($this->t("radar.statuses.{$status}"), (string) $count);
        }
        $this->components->twoColumnDetail($this->t('radar.stats.active_postings'), (string) $stats->activePostings());
        $overlap = $stats->platsbankenOverlap();
        $this->components->twoColumnDetail(
            $this->t('radar.stats.in_platsbanken'),
            $overlap['active'] === 0 ? '0 %' : number_format(100 * $overlap['matched'] / $overlap['active'], 1, ',', ' ').' % ('.$overlap['matched'].')',
        );

        $prefixHits = $stats->prefixHits();
        if ($prefixHits !== []) {
            $this->components->twoColumnDetail('<fg=green>'.$this->t('radar.stats.prefix_hits').'</>');
            foreach ($prefixHits as $prefix => $hits) {
                $this->components->twoColumnDetail($prefix, (string) $hits);
            }
        }

        $gap = $stats->txtMatchesWithoutBoard();
        $this->components->twoColumnDetail('<fg=green>'.$this->t('radar.stats.txt_without_board').'</>', (string) count($gap));
        foreach ($gap as $domain) {
            $this->components->twoColumnDetail($domain);
        }

        return self::SUCCESS;
    }
}
