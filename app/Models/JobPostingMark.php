<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MarkStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A posting a user has seen, and possibly marked.
 *
 * @property int $id
 * @property int $user_id
 * @property int $job_posting_id
 * @property MarkStatus|null $status
 * @property Carbon|null $status_changed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'job_posting_id', 'status', 'status_changed_at'])]
class JobPostingMark extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MarkStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
