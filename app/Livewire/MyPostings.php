<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\MarkStatus;
use App\Livewire\Concerns\MarksPostings;
use App\Models\JobPosting;
use App\Models\JobPostingMark;
use App\Tracking\PostingTracker;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The user's marked postings on /mina, one tab per status. Closed postings
 * stay listed, unlike in the job list.
 */
final class MyPostings extends Component
{
    use MarksPostings;

    public const int PAGE_SIZE = 50;

    #[Url(except: 'saved')]
    public string $status = 'saved';

    public int $limit = self::PAGE_SIZE;

    public function updated(string $property): void
    {
        if ($property === 'status') {
            $this->limit = self::PAGE_SIZE;
        }
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function render(PostingTracker $tracker): View
    {
        $user = $this->currentUser();
        $current = MarkStatus::tryFrom($this->status) ?? MarkStatus::Saved;

        $marks = $user->marks()
            ->where('status', $current->value)
            ->with('jobPosting.board')
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $postings = $marks->take($this->limit)
            ->map(static fn (JobPostingMark $mark): ?JobPosting => $mark->jobPosting)
            ->filter()
            ->values()
            ->all();

        return view('livewire.my-postings', [
            'current' => $current,
            'counts' => $tracker->statusCounts($user),
            'postings' => $postings,
            'userMarks' => $tracker->marks($user),
            'hasMore' => $marks->count() > $this->limit,
        ])->title(__('ui.nav.mine'));
    }
}
