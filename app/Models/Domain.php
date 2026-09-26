<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CandidateSourceType;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A registrable domain known to belong to an employer, used for prefix
 * probing (source C) and TXT checks (source E).
 *
 * @property string $base_domain
 * @property CandidateSourceType $source
 * @property string|null $org_nr
 * @property string|null $company_name
 * @property bool|null $txt_match
 * @property Carbon|null $probed_at
 * @property Carbon|null $txt_checked_at
 */
#[Table(key: 'base_domain', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable(['base_domain', 'source', 'org_nr', 'company_name', 'txt_match', 'probed_at', 'txt_checked_at'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => CandidateSourceType::class,
            'txt_match' => 'boolean',
            'probed_at' => 'datetime',
            'txt_checked_at' => 'datetime',
        ];
    }
}
