<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CandidateSourceType;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        return [
            'base_domain' => fake()->unique()->domainWord().'.se',
            'source' => CandidateSourceType::JobStream,
            'org_nr' => fake()->numerify('55########'),
            'company_name' => fake()->company(),
            'txt_match' => null,
            'probed_at' => null,
        ];
    }
}
