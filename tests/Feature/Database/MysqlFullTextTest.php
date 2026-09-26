<?php

declare(strict_types=1);

use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Runs only against MySQL (composer test:mysql). Guards the migrations and
| the FULLTEXT index that the job browser depends on.
*/

uses(RefreshDatabase::class);

it('runs the migrations on MySQL 8 with a FULLTEXT index on job postings', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    JobPosting::factory()->create(['title' => 'Backendutvecklare till Stockholm', 'description' => 'PHP och Laravel']);
    JobPosting::factory()->create(['title' => 'Sjuksköterska i Göteborg', 'company' => 'Vårdbolaget', 'description' => 'Vård']);

    $search = fn (string $terms): array => JobPosting::query()
        ->whereFullText(['title', 'company', 'description'], $terms, ['mode' => 'boolean'])
        ->pluck('title')
        ->all();

    expect($search('+backend*'))->toBe(['Backendutvecklare till Stockholm'])
        ->and($search('+laravel*'))->toBe(['Backendutvecklare till Stockholm'])
        ->and($search('+vårdbolaget*'))->toBe(['Sjuksköterska i Göteborg']);
})->group('mysql');
