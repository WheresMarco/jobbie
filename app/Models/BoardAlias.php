<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardAliasRelation;
use Database\Factories\BoardAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $host
 * @property int $board_id
 * @property BoardAliasRelation $relation
 */
#[Table(key: 'host', keyType: 'string', incrementing: false)]
#[Fillable(['host', 'board_id', 'relation'])]
class BoardAlias extends Model
{
    /** @use HasFactory<BoardAliasFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => BoardAliasRelation::class,
        ];
    }

    /**
     * @return BelongsTo<Board, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }
}
