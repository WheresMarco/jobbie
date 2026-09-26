<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RemoteStatus;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A job ad from a board's feed. Named JobPosting so it does not clash with
 * queue jobs in App\Jobs.
 *
 * @property int $id
 * @property int $board_id
 * @property string $feed_id
 * @property string $title
 * @property string $url
 * @property string|null $company
 * @property list<string> $cities
 * @property list<string> $countries
 * @property RemoteStatus|null $remote
 * @property string|null $department
 * @property string|null $employment_type
 * @property string|null $salary_text
 * @property Carbon|null $posted_at
 * @property string|null $description
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property bool $active
 */
#[Fillable([
    'board_id', 'feed_id', 'title', 'url', 'company', 'cities', 'countries', 'remote', 'department',
    'employment_type', 'salary_text', 'posted_at', 'description', 'first_seen_at', 'last_seen_at', 'active',
])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cities' => 'array',
            'countries' => 'array',
            'remote' => RemoteStatus::class,
            'posted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Board, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * @return HasMany<JobPostingLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(JobPostingLocation::class);
    }
}
