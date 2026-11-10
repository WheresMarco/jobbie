<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Stats\Coverage;
use App\Stats\RadarStats;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The welcome page on /valkommen: shown after registering and reachable from
 * the account menu. Explains what Jobbie does and where to start.
 */
final class Welcome extends Component
{
    public function render(RadarStats $stats): View
    {
        $coverage = Coverage::cached($stats);
        $user = auth()->user();

        return view('livewire.welcome', [
            'postings' => $coverage->postings,
            'employers' => $coverage->employers,
            'name' => $user instanceof User ? $user->firstName() : '',
            'matching' => ResumeMatches::enabled(),
        ])->title(__('ui.welcome.title'));
    }
}
