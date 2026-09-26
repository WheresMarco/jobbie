<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CandidateStage;
use App\Stats\RadarStats;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The statistics page on /stats.
 */
final class StatsPage extends Component
{
    public function render(RadarStats $stats): View
    {
        return view('livewire.stats-page', [
            'sources' => $stats->sources(),
            'stages' => array_column(CandidateStage::cases(), 'value'),
            'byKind' => $stats->boardsByKind(),
            'byStatus' => $stats->boardsByStatus(),
            'activePostings' => $stats->activePostings(),
            'platsbanken' => $stats->platsbankenOverlap(),
            'prefixHits' => $stats->prefixHits(),
            'txtWithoutBoard' => $stats->txtMatchesWithoutBoard(),
        ]);
    }
}
