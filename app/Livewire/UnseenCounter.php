<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Tracking\PostingTracker;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The unseen count in the navigation. The layout is not re-rendered by
 * Livewire updates, so this refreshes itself when marks change. Loaded
 * after the page, so counting never delays it.
 */
#[Lazy]
final class UnseenCounter extends Component
{
    public const string MARKS_CHANGED = 'marks-changed';

    public function placeholder(): string
    {
        return '<span></span>';
    }

    #[On(self::MARKS_CHANGED)]
    public function refresh(): void
    {
        // Rendering again is all it takes.
    }

    public function render(PostingTracker $tracker): View
    {
        $user = auth()->user();

        return view('livewire.unseen-counter', [
            'count' => $user instanceof User ? $tracker->unseenCount($user) : 0,
        ]);
    }
}
