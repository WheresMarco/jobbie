<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A job ad from Platsbanken, synced through JobTech JobStream (source B).
 *
 * @property string $id
 * @property string|null $apply_host
 * @property string|null $apply_url
 * @property string|null $org_nr
 * @property string|null $employer_name
 * @property string|null $title_normalized
 * @property Carbon|null $published_at
 * @property Carbon|null $removed_at
 */
#[Table(key: 'id', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable(['id', 'apply_host', 'apply_url', 'org_nr', 'employer_name', 'title_normalized', 'published_at', 'removed_at'])]
class PlatsbankenAd extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
