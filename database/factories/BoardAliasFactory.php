<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardAliasRelation;
use App\Models\Board;
use App\Models\BoardAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardAlias>
 */
class BoardAliasFactory extends Factory
{
    protected $model = BoardAlias::class;

    public function definition(): array
    {
        return [
            'host' => fake()->unique()->slug(2).'.teamtailor.com',
            'board_id' => Board::factory(),
            'relation' => BoardAliasRelation::Redirect,
        ];
    }
}
