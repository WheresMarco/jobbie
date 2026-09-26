<?php

declare(strict_types=1);

use App\Livewire\JobBrowser;
use App\Models\Board;
use App\Models\JobPosting;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;

/*
| Runs only against MySQL (composer test:mysql). FULLTEXT needs committed
| rows, hence DatabaseMigrations instead of RefreshDatabase.
*/

uses(DatabaseMigrations::class);

it('searches with FULLTEXT in boolean mode, prefix per word', function (): void {
    $board = Board::factory()->custom('career.kry.se')->active()->create();
    JobPosting::factory()->for($board)->create(['feed_id' => '1', 'title' => 'Backendutvecklare', 'description' => 'PHP och Laravel', 'company' => 'Kry']);
    JobPosting::factory()->for($board)->create(['feed_id' => '2', 'title' => 'Sjuksköterska', 'description' => 'Vård på distans', 'company' => 'Kry']);
    JobPosting::factory()->for($board)->create(['feed_id' => '3', 'title' => 'IT-tekniker', 'description' => 'Support', 'company' => 'Acme']);

    $found = fn (string $q): array => collect(Livewire::withQueryParams(['q' => $q, 'view' => 'flat'])
        ->test(JobBrowser::class)
        ->viewData('postings'))->pluck('feed_id')->sort()->values()->all();

    expect($found('backend'))->toBe(['1'])
        ->and($found('sjukskö vård'))->toBe(['2'])
        ->and($found('kry'))->toBe(['1', '2'])
        // Words shorter than the FULLTEXT minimum fall back to LIKE.
        ->and($found('it'))->toBe(['3'])
        ->and($found('+laravel -"php"'))->toBe(['1']);
})->group('mysql');

it('answers within 300 ms with 50 000 postings', function (): void {
    $seeder = new DemoSeeder();
    $seeder->postings = 50_000;
    $seeder->boards = 2_000;
    $seeder->run();

    expect(JobPosting::query()->count())->toBe(50_000);

    $urls = [
        '/',
        '/?view=flat',
        '/?country=SE&remote[]=fully&published=7',
        '/?q=systemutvecklare',
        '/?q=patienter&department=Vård',
        '/?view=flat&q=python+aws&kind=custom',
    ];

    // Warm up compiled views, opcodes and the database buffer pool. The
    // result cache is flushed before every measured request.
    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }

    $timings = [];
    foreach ($urls as $url) {
        cache()->flush();
        $start = hrtime(true);
        $this->get($url)->assertOk();
        $timings[$url] = (hrtime(true) - $start) / 1e6;
    }

    fwrite(STDERR, "\n".json_encode($timings, JSON_PRETTY_PRINT)."\n");

    expect(max($timings))->toBeLessThan(300);
})->group('mysql');
