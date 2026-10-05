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
 * @property bool $in_platsbanken
 * @property bool $duplicate
 * @property bool $in_sweden
 * @property string|null $org_nr
 * @property string|null $platsbanken_ad_id
 * @property string|null $platsbanken_match
 */
#[Fillable([
    'board_id', 'feed_id', 'title', 'url', 'company', 'cities', 'countries', 'remote', 'department',
    'employment_type', 'salary_text', 'posted_at', 'description', 'first_seen_at', 'last_seen_at', 'active',
    'in_platsbanken', 'platsbanken_ad_id', 'platsbanken_match', 'org_nr', 'duplicate', 'in_sweden',
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
            'in_platsbanken' => 'boolean',
            'duplicate' => 'boolean',
            'in_sweden' => 'boolean',
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
     * The name the job list groups this posting under: its company, else
     * the career site's name or host. Hidden employers are matched on it.
     */
    public function employerName(): string
    {
        $board = $this->getRelationValue('board');

        return $this->company ?? ($board instanceof Board ? ($board->name ?? $board->canonical_host) : '');
    }

    /**
     * @return HasMany<JobPostingLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(JobPostingLocation::class);
    }
}
