<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        return [
            'host' => 'jobb.'.fake()->unique()->domainWord().'.se',
            'source' => CandidateSourceType::Prefix,
            'source_ref' => null,
            'strength' => Strength::Weak,
            'stage' => CandidateStage::New,
            'discovered_at' => now(),
        ];
    }
}
