<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Enums\Platform;
use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A career site: one employer account on one platform. Create Teamtailor
 * boards through BoardResolver (Phase 6) so that deduplication rules apply
 * everywhere; boards on other platforms come from PlatformBoardDiscovery.
 *
 * The external key identifies the account on its platform: the canonical
 * host on Teamtailor, the account name elsewhere (see the adapters in
 * App\Platforms). The canonical host is where the board is served, which
 * several boards may share.
 *
 * @property int $id
 * @property Platform $platform
 * @property string $external_key
 * @property string $canonical_host
 * @property string|null $tt_slug
 * @property BoardKind|null $kind
 * @property BoardStatus $status
 * @property string|null $name
 * @property string|null $home_url
 * @property int $job_count
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $checked_at
 * @property string|null $error
 */
#[Fillable(['platform', 'external_key', 'canonical_host', 'tt_slug', 'kind', 'status', 'name', 'home_url', 'job_count', 'first_seen_at', 'checked_at', 'error'])]
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

    protected static function booted(): void
    {
        // Teamtailor boards are keyed by host, which BoardResolver changes
        // when it re-canonicalises or merges.
        static::saving(static function (self $board): void {
            if ($board->platform === Platform::Teamtailor) {
                $board->external_key = $board->canonical_host;
            }
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function teamtailor(Builder $query): void
    {
        $query->where('platform', Platform::Teamtailor);
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

    public function feedUrl(): string
    {
        return "https://{$this->canonical_host}/jobs.json";
    }

    /**
     * The employer's list of open jobs, for links in the job list.
     */
    public function siteUrl(): ?string
    {
        return match ($this->platform) {
            Platform::Teamtailor => "https://{$this->canonical_host}/",
            Platform::Platsbanken => null,
            default => $this->home_url,
        };
    }
}
