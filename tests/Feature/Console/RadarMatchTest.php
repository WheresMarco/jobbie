<?php

declare(strict_types=1);

use App\Enums\ResumeStatus;
use App\Matching\Contracts\LanguageModel;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLanguageModel;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->model = new FakeLanguageModel();
    $this->app->instance(LanguageModel::class, $this->model);

    $board = Board::factory()->active()->create();
    JobPosting::factory()->for($board)->create(['title' => 'Backendutvecklare', 'company' => 'Kry', 'description' => 'PHP']);
    JobPosting::factory()->for($board)->create(['title' => 'Kock', 'company' => 'Krogen', 'description' => 'Mat']);

    $this->user = User::factory()->create(['email' => 'anna@example.se']);
    $this->pdf = tempnam(sys_get_temp_dir(), 'cv');
    file_put_contents($this->pdf, '%PDF-1.4');
});

afterEach(function (): void {
    @unlink($this->pdf);
});

it('matches a CV file and lists the best matches', function (): void {
    $this->artisan('radar:match', ['email' => 'anna@example.se', 'cv' => $this->pdf, '--preferences' => 'Distans'])
        ->expectsOutputToContain('Senior webbutvecklare')
        ->expectsOutputToContain('1 nya annonser att bedöma.')
        ->expectsOutputToContain('Passar som Backendutvecklare.')
        ->assertSuccessful();

    $resume = Resume::query()->sole();
    expect($resume->status)->toBe(ResumeStatus::Ready)
        ->and($resume->preferences)->toBe('Distans')
        ->and($this->model->requests[0]['content'][0]['source']['data'])->toBe(base64_encode('%PDF-1.4'));
});

it('matches the current profile again without a file', function (): void {
    $this->artisan('radar:match', ['email' => 'anna@example.se', 'cv' => $this->pdf])->assertSuccessful();
    $requests = count($this->model->requests);

    // Nothing new to score.
    $this->artisan('radar:match', ['email' => 'anna@example.se'])
        ->expectsOutputToContain('0 nya annonser att bedöma.')
        ->assertSuccessful();
    expect($this->model->requests)->toHaveCount($requests);
});

it('refuses unknown accounts, missing files and accounts without a CV', function (): void {
    $this->artisan('radar:match', ['email' => 'nobody@example.se'])->assertExitCode(2);
    $this->artisan('radar:match', ['email' => 'anna@example.se', 'cv' => '/nope.pdf'])->assertExitCode(2);
    $this->artisan('radar:match', ['email' => 'anna@example.se'])->expectsOutputToContain('har inget analyserat CV')->assertExitCode(2);
});

it('needs an API key', function (): void {
    unset($this->app[LanguageModel::class]);

    $this->artisan('radar:match', ['email' => 'anna@example.se'])->expectsOutputToContain('ANTHROPIC_API_KEY')->assertFailed();
});
