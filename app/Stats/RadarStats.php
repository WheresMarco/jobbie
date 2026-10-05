<?php

declare(strict_types=1);

namespace App\Stats;

use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Platform;
use App\Models\Board;
use App\Models\BoardAlias;
use App\Models\Candidate;
use App\Models\Domain;
use App\Models\JobPosting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for radar:stats and the statistics page: how well each discovery
 * source works, and where coverage is missing.
 */
final class RadarStats
{
    /**
     * Per source: candidates per stage, and the active boards and postings
     * reached through the source's verified candidates.
     *
     * @return list<SourceStats>
     */
    public function sources(): array
    {
        $stages = [];
        foreach (Candidate::query()->toBase()->select('source', 'stage')->selectRaw('count(*) as candidates')->groupBy('source', 'stage')->get() as $row) {
            $stages[(string) $row->source][(string) $row->stage] = (int) $row->candidates;
        }

        $reach = [];
        foreach ($this->activeBoardsPerSource()->get() as $row) {
            $reach[(string) $row->source] = [(int) $row->boards, (int) $row->postings];
        }

        $stats = [];
        foreach (CandidateSourceType::cases() as $source) {
            if (! isset($stages[$source->value]) && ! isset($reach[$source->value])) {
                continue;
            }

            $stats[] = new SourceStats(
                $source,
                $stages[$source->value] ?? [],
                $reach[$source->value][0] ?? 0,
                $reach[$source->value][1] ?? 0,
            );
        }

        return $stats;
    }

    /**
     * @return array<string, int> BoardKind value => boards
     */
    public function boardsByKind(): array
    {
        return $this->countBy(Board::query()->toBase(), 'kind', array_column(BoardKind::cases(), 'value'));
    }

    /**
     * @return array<string, int> BoardStatus value => boards
     */
    public function boardsByStatus(): array
    {
        return $this->countBy(Board::query()->toBase(), 'status', array_column(BoardStatus::cases(), 'value'));
    }

    public function activePostings(): int
    {
        return JobPosting::query()->where('active', true)->count();
    }

    /**
     * Phase 9: how many active postings on employer boards also appear in
     * Platsbanken. The Platsbanken board's own postings are left out.
     *
     * @return array{active: int, matched: int, apply_url: int, org_title: int}
     */
    public function platsbankenOverlap(): array
    {
        $employerBoards = Board::query()->where('platform', '!=', Platform::Platsbanken)->select('id');
        $active = JobPosting::query()->where('active', true)->whereIn('board_id', $employerBoards)->count();
        $methods = JobPosting::query()->toBase()
            ->where('active', true)
            ->whereIn('board_id', $employerBoards)
            ->where('in_platsbanken', true)
            ->select('platsbanken_match')
            ->selectRaw('count(*) as total')
            ->groupBy('platsbanken_match')
            ->pluck('total', 'platsbanken_match');

        return [
            'active' => $active,
            'matched' => (int) $methods->sum(),
            'apply_url' => (int) ($methods['apply_url'] ?? 0),
            'org_title' => (int) ($methods['org_title'] ?? 0),
        ];
    }

    /**
     * Domains whose root TXT record says they are Teamtailor customers but
     * where no board has been found under the domain: the visible gap.
     *
     * @return list<string>
     */
    public function txtMatchesWithoutBoard(): array
    {
        // "host ends with .base_domain", spelled per driver (|| is OR in MySQL).
        $subdomainOf = DB::connection()->getDriverName() === 'mysql'
            ? "like concat('%.', domains.base_domain)"
            : "like ('%.' || domains.base_domain)";

        /** @var list<string> $domains */
        $domains = Domain::query()
            ->where('txt_match', true)
            ->whereNotExists(fn (Builder $q) => $q->from('boards')
                ->whereColumn('boards.canonical_host', 'domains.base_domain')
                ->orWhereRaw('boards.canonical_host '.$subdomainOf))
            ->whereNotExists(fn (Builder $q) => $q->from('board_aliases')
                ->whereColumn('board_aliases.host', 'domains.base_domain')
                ->orWhereRaw('board_aliases.host '.$subdomainOf))
            ->orderBy('base_domain')
            ->pluck('base_domain')
            ->all();

        return $domains;
    }

    /**
     * Verified prefix candidates per prefix, to prune the prefix list.
     *
     * @return array<string, int>
     */
    public function prefixHits(): array
    {
        $hits = [];
        foreach (Candidate::query()->toBase()
            ->where('source', CandidateSourceType::Prefix->value)
            ->where('stage', CandidateStage::Verified->value)
            ->select('source_ref')->selectRaw('count(*) as hits')
            ->groupBy('source_ref')->orderByDesc('hits')->get() as $row) {
            $hits[(string) $row->source_ref] = (int) $row->hits;
        }

        return $hits;
    }

    private function activeBoardsPerSource(): Builder
    {
        // Every host that leads to a board: canonical hosts and aliases.
        $hostBoards = Board::query()->toBase()->select('canonical_host as host', 'id as board_id')
            ->unionAll(BoardAlias::query()->toBase()->select('host', 'board_id'));

        $reached = DB::query()->fromSub(
            Candidate::query()->toBase()
                ->joinSub($hostBoards, 'hb', 'hb.host', '=', 'candidates.host')
                ->join('boards', 'boards.id', '=', 'hb.board_id')
                ->where('candidates.stage', CandidateStage::Verified->value)
                ->where('boards.status', BoardStatus::Active->value)
                ->distinct()
                ->select('candidates.source', 'boards.id', 'boards.job_count'),
            'reached',
        );

        return $reached->select('source')
            ->selectRaw('count(*) as boards, sum(job_count) as postings')
            ->groupBy('source');
    }

    /**
     * @param  list<string>  $values
     * @return array<string, int>
     */
    private function countBy(Builder $query, string $column, array $values): array
    {
        $counts = array_fill_keys($values, 0);
        foreach ($query->select($column)->selectRaw('count(*) as total')->groupBy($column)->get() as $row) {
            $counts[(string) $row->{$column}] = (int) $row->total;
        }

        return $counts;
    }
}
