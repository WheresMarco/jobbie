<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\MarkStatus;
use App\Livewire\UnseenCounter;
use App\Models\User;
use App\Tracking\PostingTracker;

/**
 * Actions on a single posting, shared by the pages that list postings.
 */
trait MarksPostings
{
    /**
     * An empty or unknown status clears the mark.
     */
    public function mark(int $postingId, string $status): void
    {
        app(PostingTracker::class)->mark($this->currentUser(), $postingId, MarkStatus::tryFrom($status));
        $this->dispatch(UnseenCounter::MARKS_CHANGED);
    }

    public function markSeen(int $postingId): void
    {
        app(PostingTracker::class)->markSeen($this->currentUser(), [$postingId]);
        $this->dispatch(UnseenCounter::MARKS_CHANGED);
    }

    protected function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
