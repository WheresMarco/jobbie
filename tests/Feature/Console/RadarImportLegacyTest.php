<?php

declare(strict_types=1);

use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->legacy = tempnam(sys_get_temp_dir(), 'legacy').'.sqlite';
    $pdo = new PDO('sqlite:'.$this->legacy);
    $pdo->exec('CREATE TABLE boards (id INTEGER PRIMARY KEY, host TEXT, url TEXT, status TEXT)');
    $pdo->exec("INSERT INTO boards (host, url, status) VALUES
        ('kry.teamtailor.com', 'https://kry.teamtailor.com/jobs', 'active'),
        ('Career.Kry.se.', 'https://career.kry.se/', 'active'),
        ('karriär.företag.se', NULL, 'active'),
        ('not a host', NULL, 'dead'),
        (NULL, NULL, 'dead')");
    $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, url TEXT, title TEXT)');
    $pdo->exec("INSERT INTO jobs (url, title) VALUES
        ('https://jobs.timefold.ai/jobs/1-dev', 'Dev'),
        ('https://kry.teamtailor.com/jobs/2-x', 'X')");
    $pdo->exec('CREATE TABLE meta (key TEXT, value TEXT)');
});

afterEach(function (): void {
    @unlink($this->legacy);
});

it('imports hosts from every table with a host or URL column', function (): void {
    $this->artisan('radar:import-legacy', ['path' => $this->legacy])->assertSuccessful();

    expect(Candidate::query()->orderBy('host')->pluck('host')->all())->toBe([
        'career.kry.se',
        'jobs.timefold.ai',
        'kry.teamtailor.com',
        'xn--karrir-fua.xn--fretag-wxa.se',
    ]);

    $candidate = Candidate::query()->findOrFail('career.kry.se');
    expect($candidate->source)->toBe(CandidateSourceType::Seed)
        ->and($candidate->strength)->toBe(Strength::Strong)
        ->and($candidate->stage)->toBe(CandidateStage::New)
        ->and($candidate->source_ref)->toBe('legacy:boards.host')
        ->and(Candidate::query()->findOrFail('jobs.timefold.ai')->source_ref)->toBe('legacy:jobs.url');
});

it('is idempotent and never overwrites existing candidates', function (): void {
    Candidate::factory()->create(['host' => 'career.kry.se', 'stage' => CandidateStage::Verified]);

    $this->artisan('radar:import-legacy', ['path' => $this->legacy])->assertSuccessful();
    $this->artisan('radar:import-legacy', ['path' => $this->legacy])
        ->expectsOutputToContain('0 new candidates imported')
        ->assertSuccessful();

    expect(Candidate::query()->count())->toBe(4)
        ->and(Candidate::query()->findOrFail('career.kry.se')->stage)->toBe(CandidateStage::Verified);
});

it('fails for a missing file', function (): void {
    $this->artisan('radar:import-legacy', ['path' => '/nonexistent/radar.sqlite'])->assertFailed();
});

it('fails when no table has a host column', function (): void {
    $empty = tempnam(sys_get_temp_dir(), 'legacy');
    (new PDO('sqlite:'.$empty))->exec('CREATE TABLE meta (key TEXT)');

    $this->artisan('radar:import-legacy', ['path' => $empty])->assertFailed();

    @unlink($empty);
});
