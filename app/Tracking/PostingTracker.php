<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Enums\MarkStatus;
use App\Models\JobPosting;
use App\Models\JobPostingMark;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records what a user has seen and how they have marked postings.
 *
 * A posting is unseen until the user marks it, opens it, marks it as seen,
 * or marks everything as seen. The last only moves a timestamp on the
 * user, so it stays cheap with tens of thousands of postings.
 */
final class PostingTracker
{
    public function marks(User $user): UserMarks
    {
        /** @var array<int, string|null> $statuses */
        $statuses = DB::table('job_posting_marks')
            ->where('user_id', $user->id)
            ->pluck('status', 'job_posting_id')
            ->all();

        return new UserMarks(
            userId: $user->id,
            statuses: $statuses,
            seenUntil: $user->postings_seen_until?->utc()->format('Y-m-d H:i:s'),
            version: $user->marks_version,
        );
    }

    /**
     * Set or clear the status. Either way the posting counts as seen.
     */
    public function mark(User $user, int $postingId, ?MarkStatus $status): void
    {
        if (! JobPosting::query()->whereKey($postingId)->exists()) {
            return;
        }

        JobPostingMark::query()->updateOrCreate(
            ['user_id' => $user->id, 'job_posting_id' => $postingId],
            ['status' => $status, 'status_changed_at' => Carbon::now()],
        );

        $user->increment('marks_version');
    }

    /**
     * @param  list<int>  $postingIds
     */
    public function markSeen(User $user, array $postingIds): void
    {
        $existing = JobPosting::query()->whereKey($postingIds)->pluck('id')->all();
        if ($existing === []) {
            return;
        }

        $now = Carbon::now();
        DB::table('job_posting_marks')->insertOrIgnore(array_map(static fn (int $id): array => [
            'user_id' => $user->id,
            'job_posting_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $existing));

        $user->increment('marks_version');
    }

    public function markAllSeen(User $user): void
    {
        $user->postings_seen_until = Carbon::now();
        $user->marks_version++;
        $user->save();
    }

    /**
     * Active postings the user has not seen.
     */
    public function unseenCount(User $user): int
    {
        $since = $user->postings_seen_until;

        // Two counts instead of an anti-join over every posting: this runs
        // on each page load, and a user has few marks.
        $recent = DB::table('job_postings')
            ->where('active', true)
            ->when($since, fn (Builder $query, Carbon $since) => $query->where('first_seen_at', '>', $since))
            ->count();

        $marked = DB::table('job_posting_marks')
            ->join('job_postings', 'job_postings.id', '=', 'job_posting_marks.job_posting_id')
            ->where('job_posting_marks.user_id', $user->id)
            ->where('job_postings.active', true)
            ->when($since, fn (Builder $query, Carbon $since) => $query->where('job_postings.first_seen_at', '>', $since))
            ->count();

        return $recent - $marked;
    }

    /**
     * Postings per status, including closed ones.
     *
     * @return array<string, int> MarkStatus value => postings
     */
    public function statusCounts(User $user): array
    {
        $counts = array_fill_keys(array_map(static fn (MarkStatus $s): string => $s->value, MarkStatus::cases()), 0);

        $rows = DB::table('job_posting_marks')
            ->where('user_id', $user->id)
            ->whereNotNull('status')
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as postings'))
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->postings;
        }

        return $counts;
    }
}
