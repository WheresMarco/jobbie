<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Models\Board;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Board>
 */
class BoardFactory extends Factory
{
    protected $model = Board::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'canonical_host' => "{$slug}.teamtailor.com",
            'platform' => Platform::Teamtailor,
            'tt_slug' => $slug,
            'kind' => BoardKind::TtSubdomain,
            'status' => BoardStatus::New,
            'name' => fake()->company(),
            'home_url' => "https://{$slug}.teamtailor.com/",
            'job_count' => 0,
            'first_seen_at' => now(),
        ];
    }

    public function custom(?string $host = null): static
    {
        return $this->state(function () use ($host): array {
            $host ??= 'career.'.fake()->unique()->domainWord().'.se';

            return [
                'canonical_host' => $host,
                'tt_slug' => null,
                'kind' => BoardKind::Custom,
                'home_url' => "https://{$host}/",
            ];
        });
    }

    public function active(): static
    {
        return $this->state(['status' => BoardStatus::Active, 'checked_at' => now()]);
    }
}
