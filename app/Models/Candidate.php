<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A host that might be a Teamtailor career site. Kept apart from boards so
 * that large volumes of DNS probes never mix with verified sites.
 *
 * @property string $host
 * @property CandidateSourceType $source
 * @property string|null $source_ref
 * @property Strength $strength
 * @property CandidateStage $stage
 * @property string|null $reason
 * @property Carbon|null $discovered_at
 * @property Carbon|null $checked_at
 */
#[Table(key: 'host', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable(['host', 'source', 'source_ref', 'strength', 'stage', 'reason', 'discovered_at', 'checked_at'])]
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    protected $attributes = [
        'stage' => 'new',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => CandidateSourceType::class,
            'strength' => Strength::class,
            'stage' => CandidateStage::class,
            'discovered_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
