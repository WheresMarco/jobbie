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
 * @property array<string, mixed>|null $additions
 * @property array<string, mixed>|null $removals
 * @property bool $edited
 * @property string|null $preferences
 * @property string|null $error
 * @property int $run
 * @property int $to_score
 * @property int $scored
 * @property Carbon|null $matched_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'filename', 'status', 'profile', 'additions', 'removals', 'edited', 'preferences', 'error', 'run', 'to_score', 'scored', 'matched_at'])]
class Resume extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'run' => 0,
        'to_score' => 0,
        'scored' => 0,
        'edited' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResumeStatus::class,
            'profile' => 'array',
            'additions' => 'array',
            'removals' => 'array',
            'edited' => 'boolean',
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

    /**
     * The profile to match with: Claude's, with the user's changes.
     */
    public function profile(): ?Profile
    {
        return $this->extractedProfile()?->edited($this->additions(), $this->removals());
    }

    /**
     * The profile as Claude wrote it.
     */
    public function extractedProfile(): ?Profile
    {
        return $this->profile === null ? null : Profile::fromArray($this->profile);
    }

    /**
     * Roles and skills the user added.
     *
     * @return array{roles: list<string>, skills: list<string>}
     */
    public function additions(): array
    {
        return self::lists($this->additions);
    }

    /**
     * Roles and skills of Claude's that the user removed.
     *
     * @return array{roles: list<string>, skills: list<string>}
     */
    public function removals(): array
    {
        return self::lists($this->removals);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array{roles: list<string>, skills: list<string>}
     */
    private static function lists(?array $stored): array
    {
        $list = static fn (string $key): array => array_values(array_filter(
            is_array($stored[$key] ?? null) ? $stored[$key] : [],
            is_string(...),
        ));

        return ['roles' => $list('roles'), 'skills' => $list('skills')];
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
