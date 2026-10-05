<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Enums\Platform;
use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A verified career site on one platform. Create boards through
 * BoardResolver (Phase 6) so that deduplication rules apply everywhere.
 * tt_slug and kind only apply to Teamtailor boards.
 *
 * @property int $id
 * @property string $canonical_host
 * @property Platform $platform
 * @property string|null $tt_slug
 * @property BoardKind $kind
 * @property BoardStatus $status
 * @property string|null $name
 * @property string|null $home_url
 * @property int $job_count
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $checked_at
 * @property string|null $error
 */
#[Fillable(['canonical_host', 'platform', 'tt_slug', 'kind', 'status', 'name', 'home_url', 'job_count', 'first_seen_at', 'checked_at', 'error'])]
class Board extends Model
{
    /** @use HasFactory<BoardFactory> */
    use HasFactory;

    protected $attributes = [
        'platform' => 'teamtailor',
        'status' => 'new',
        'job_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'kind' => BoardKind::class,
            'status' => BoardStatus::class,
            'job_count' => 'integer',
            'first_seen_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BoardAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(BoardAlias::class);
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }
}
