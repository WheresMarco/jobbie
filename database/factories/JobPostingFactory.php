<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    public function definition(): array
    {
        $feedId = (string) fake()->unique()->numberBetween(1_000_000, 9_999_999);
        $title = fake()->jobTitle();

        return [
            'board_id' => Board::factory(),
            'feed_id' => $feedId,
            'title' => $title,
            'url' => "https://example.teamtailor.com/jobs/{$feedId}-".str($title)->slug(),
            'company' => fake()->company(),
            'cities' => ['Stockholm'],
            'countries' => ['SE'],
            'remote' => null,
            'department' => null,
            'employment_type' => 'FULL_TIME',
            'salary_text' => null,
            'posted_at' => now()->subDays(3),
            'description' => fake()->paragraph(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'active' => true,
        ];
    }
}
