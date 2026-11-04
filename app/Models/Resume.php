<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResumeStatus;
use App\Matching\Profile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user's CV for matching. The file itself is deleted once Claude has
 * read it; the extracted profile is kept.
 *
 * @property int $id
 * @property int $user_id
 * @property string $filename
 * @property ResumeStatus $status
 * @property array<string, mixed>|null $profile
 * @property string|null $preferences
 * @property string|null $error
 * @property int $run
 * @property int $to_score
 * @property int $scored
 * @property Carbon|null $matched_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'filename', 'status', 'profile', 'preferences', 'error', 'run', 'to_score', 'scored', 'matched_at'])]
class Resume extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'run' => 0,
        'to_score' => 0,
        'scored' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResumeStatus::class,
            'profile' => 'array',
            'run' => 'integer',
            'to_score' => 'integer',
            'scored' => 'integer',
            'matched_at' => 'datetime',
        ];
    }

    /**
     * Where the uploaded file waits until Claude has read it.
     */
    public function path(): string
    {
        return "resumes/{$this->id}.pdf";
    }

    public function profile(): ?Profile
    {
        return $this->profile === null ? null : Profile::fromArray($this->profile);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ResumeMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(ResumeMatch::class);
    }
}
