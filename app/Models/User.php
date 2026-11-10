<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $postings_seen_until
 * @property int $marks_version
 * @property bool $is_admin
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'marks_version' => 0,
        'is_admin' => false,
    ];

    /**
     * @return HasMany<JobPostingMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(JobPostingMark::class);
    }

    /**
     * @return HasMany<HiddenEmployer, $this>
     */
    public function hiddenEmployers(): HasMany
    {
        return $this->hasMany(HiddenEmployer::class);
    }

    /**
     * @return HasOne<Resume, $this>
     */
    public function resume(): HasOne
    {
        return $this->hasOne(Resume::class);
    }

    /**
     * Up to two letters for the avatar: "Anna Svensson" is "AS".
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [$this->email];

        return mb_strtoupper(implode('', array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            [reset($words), ...(count($words) > 1 ? [end($words)] : [])],
        )));
    }

    /**
     * The user's Gravatar at $size pixels. Gravatar answers 404 when there
     * is none, so the page can fall back to the initials.
     */
    public function gravatarUrl(int $size = 80): string
    {
        return 'https://gravatar.com/avatar/'.hash('sha256', mb_strtolower(trim($this->email))).'?'.http_build_query(['s' => $size, 'd' => '404']);
    }

    /**
     * The name to greet the user with.
     */
    public function firstName(): string
    {
        return strtok(trim($this->name), " \t") ?: $this->name;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'postings_seen_until' => 'datetime',
            'marks_version' => 'integer',
            'is_admin' => 'boolean',
        ];
    }
}
