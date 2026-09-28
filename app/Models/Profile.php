<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a user is looking for. Every list holds lowercase terms, except
 * cities, which keep the spelling of the job list's city filter.
 *
 * @property int $id
 * @property int $user_id
 * @property list<string> $titles Wanted job titles, matched against posting titles.
 * @property list<string> $skills Matched against title, department and description.
 * @property list<string> $excluded A posting whose title contains one of these scores zero.
 * @property list<string> $cities
 * @property list<string> $remote JobFilters::REMOTE_OPTIONS the user accepts.
 * @property Carbon|null $scored_at
 * @property int $scores_version
 */
#[Fillable(['user_id', 'titles', 'skills', 'excluded', 'cities', 'remote', 'scored_at'])]
class Profile extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'titles' => '[]',
        'skills' => '[]',
        'excluded' => '[]',
        'cities' => '[]',
        'remote' => '[]',
        'scores_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'titles' => 'array',
            'skills' => 'array',
            'excluded' => 'array',
            'cities' => 'array',
            'remote' => 'array',
            'scored_at' => 'datetime',
            'scores_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
