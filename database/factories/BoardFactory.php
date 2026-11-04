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

    /**
     * A board on another platform, keyed by its account.
     */
    public function on(Platform $platform, ?string $key = null): static
    {
        return $this->state(function () use ($platform, $key): array {
            $key ??= fake()->unique()->slug(1);

            [$host, $site] = match ($platform) {
                Platform::Varbi => ["{$key}.varbi.com", "https://{$key}.varbi.com/se/"],
                Platform::SmartRecruiters => ['jobs.smartrecruiters.com', "https://jobs.smartrecruiters.com/{$key}"],
                Platform::Workday => [explode('/', $key)[0], "https://{$key}"],
                Platform::Platsbanken => ['arbetsformedlingen.se', 'https://arbetsformedlingen.se/platsbanken/'],
                Platform::Teamtailor => ["{$key}.teamtailor.com", "https://{$key}.teamtailor.com/"],
            };

            return [
                'platform' => $platform,
                'external_key' => $key,
                'canonical_host' => $host,
                'tt_slug' => null,
                'kind' => null,
                'home_url' => $site,
            ];
        });
    }

    public function active(): static
    {
        return $this->state(['status' => BoardStatus::Active, 'checked_at' => now()]);
    }
}
