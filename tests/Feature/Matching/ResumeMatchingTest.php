<?php

declare(strict_types=1);

use App\Enums\MarkStatus;
use App\Enums\ResumeStatus;
use App\Jobs\ScoreResumeMatches;
use App\Livewire\ResumeMatches;
use App\Matching\Contracts\LanguageModel;
use App\Matching\MatchingException;
use App\Matching\ResumeMatcher;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Tracking\PostingTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fakes\FakeLanguageModel;

uses(RefreshDatabase::class);

const FAKE_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 12:00:00');
    Storage::fake('local');

    $this->model = new FakeLanguageModel();
    $this->app->instance(LanguageModel::class, $this->model);

    $board = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    $this->backend = matchPosting($board, 'Backendutvecklare', 'Kry', 'Vi söker en utvecklare med PHP och Laravel.');
    $this->web = matchPosting($board, 'Webbutvecklare', 'Lavendla', 'Bygg vår webb i Laravel.');
    $this->support = matchPosting($board, 'Supporttekniker', 'Kry', 'Hjälp kunder med PHP-frågor.');
    $this->nurse = matchPosting($board, 'Sjuksköterska', 'Kry', 'Vård på distans.');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function matchPosting(Board $board, string $title, string $company, string $description, string $country = 'SE'): JobPosting
{
    $posting = JobPosting::factory()->for($board)->create([
        'title' => $title,
        'company' => $company,
        'description' => $description,
        'countries' => [$country],
    ]);
    $posting->locations()->create(['city' => 'Stockholm', 'country' => $country]);

    return $posting;
}

function uploadCv(?string $preferences = null): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ResumeMatches::class)
        ->set('preferences', (string) $preferences)
        ->set('cv', UploadedFile::fake()->createWithContent('cv.pdf', FAKE_PDF))
        ->call('upload');
}

it('reads the CV, scores the candidates and lists the strong matches', function (): void {
    uploadCv('Linköping eller distans')
        ->assertHasNoErrors()
        ->assertSee('Senior webbutvecklare')
        ->assertViewHas('matches', fn (array $m): bool => array_map(fn ($x) => $x->jobPosting->title, $m) === ['Webbutvecklare', 'Backendutvecklare'])
        ->assertSeeHtml('data-score="90"')
        ->assertSee('Passar som Backendutvecklare.')
        ->assertDontSee('Supporttekniker')
        // Weak matches are there on request.
        ->set('all', true)
        ->assertSee('Supporttekniker');

    // Claude got the PDF itself and the preferences.
    $first = $this->model->requests[0]['content'];
    expect($first[0]['type'])->toBe('document')
        ->and($first[0]['source'])->toBe(['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode(FAKE_PDF)])
        ->and($first[1]['text'])->toContain("<preferences>\nLinköping eller distans\n</preferences>");

    // Postings without any search term never reach Claude.
    expect($this->model->scoredTitles())->toBe(['Backendutvecklare', 'Supporttekniker', 'Webbutvecklare']);

    $resume = Resume::query()->sole();
    expect($resume->status)->toBe(ResumeStatus::Ready)
        ->and($resume->filename)->toBe('cv.pdf')
        ->and($resume->profile['headline'])->toBe('Senior webbutvecklare')
        ->and($resume->scored)->toBe(3)
        ->and($resume->matched_at)->not->toBeNull()
        // Only the profile is kept, not the CV.
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('scores in chunks', function (): void {
    config(['radar.matching.chunk_size' => 2]);

    uploadCv();

    // One profile request and two scoring requests.
    expect($this->model->requests)->toHaveCount(3)
        ->and(Resume::query()->sole()->matches()->count())->toBe(3);
});

it('leaves out hidden postings and employers, other countries and repeats', function (): void {
    $board = $this->backend->board;
    matchPosting($board, 'PHP-utvecklare', 'Kry', 'PHP', 'NO');
    matchPosting($board, 'Backendutvecklare', 'Kry', 'Samma annons igen, PHP.');
    // The same ad under two spellings of the employer's name.
    matchPosting($board, 'Fullstackutvecklare', 'Bolaget AB', 'Vi bygger med PHP.');
    matchPosting($board, 'Fullstackutvecklare', 'Bolaget i Sverige AB', 'Vi bygger med PHP.');
    matchPosting($board, 'Frontendutvecklare', 'Dolda AB', 'PHP och React.');
    $remote = JobPosting::factory()->for($board)->create(['title' => 'Laravelutvecklare', 'company' => 'Okänd ort AB', 'countries' => []]);

    $tracker = app(PostingTracker::class);
    $tracker->mark($this->user, $this->web->id, MarkStatus::NotRelevant);
    $tracker->hideEmployer($this->user, 'Dolda AB');

    uploadCv();

    expect($this->model->scoredTitles())->toBe(['Backendutvecklare', 'Fullstackutvecklare', 'Laravelutvecklare', 'Supporttekniker'])
        ->and($remote->id)->toBeIn(Resume::query()->sole()->matches()->pluck('job_posting_id')->all());
});

it('hides matches the user hides later and closed postings', function (): void {
    $component = uploadCv();

    app(PostingTracker::class)->mark($this->user, $this->web->id, MarkStatus::Rejected);
    $this->backend->update(['active' => false]);

    $component->call('$refresh')->assertViewHas('matches', [])->assertViewHas('totalCount', 1);
});

it('scores only new candidates when searching again', function (): void {
    uploadCv();
    $before = count($this->model->requests);

    $new = matchPosting($this->backend->board, 'Fullstackutvecklare', 'Ny AB', 'PHP och Laravel.');
    Livewire::test(ResumeMatches::class)->call('searchAgain')->assertHasNoErrors();

    expect(array_values($this->model->postingsIn($before)))->toBe(['Fullstackutvecklare'])
        ->and(Resume::query()->sole()->matches()->count())->toBe(4)
        ->and(Resume::query()->sole()->matches()->where('job_posting_id', $new->id)->value('score'))->toBe(90);
});

it('revises the profile without the CV when the preferences change and scores everything again', function (): void {
    uploadCv('Stockholm');
    $this->model->scoreUsing = static fn (string $title): int => 55;
    $before = count($this->model->requests);

    Livewire::test(ResumeMatches::class)
        ->assertSet('preferences', 'Stockholm')
        ->set('preferences', 'Bara distans')
        ->call('savePreferences')
        ->assertHasNoErrors();

    $revision = $this->model->requests[$before]['content'];
    expect($revision)->toHaveCount(1)
        ->and($revision[0]['type'])->toBe('text')
        ->and($revision[0]['text'])->toContain('<profile>')->toContain("<preferences>\nBara distans\n</preferences>");

    $resume = Resume::query()->sole();
    expect($resume->preferences)->toBe('Bara distans')
        ->and($resume->matches()->pluck('score')->unique()->all())->toBe([55])
        ->and($resume->matches()->count())->toBe(3);
});

it('adds roles and skills of the user\'s own and scores everything against them', function (): void {
    uploadCv();
    $platform = matchPosting($this->backend->board, 'Plattformsingenjör', 'Moln AB', 'Vi kör Kubernetes.');
    $before = count($this->model->requests);

    Livewire::test(ResumeMatches::class)
        ->set('adding.skills', '  Kubernetes ')
        ->call('add', 'skills')
        ->assertHasNoErrors()
        ->assertSet('adding.skills', '')
        ->set('adding.roles', 'Plattformsingenjör')
        ->call('add', 'roles')
        ->assertSeeHtml('data-own')
        ->assertSeeHtml('data-edited')
        ->assertSee('Matcha om')
        ->call('searchAgain')
        ->assertDontSeeHtml('data-edited');

    $resume = Resume::query()->sole();
    expect($resume->additions())->toBe(['roles' => ['Plattformsingenjör'], 'skills' => ['Kubernetes']])
        ->and($resume->edited)->toBeFalse()
        // Found by the new terms, and the old matches scored again too.
        ->and(array_values($this->model->postingsIn($before)))->toEqualCanonicalizing(['Backendutvecklare', 'Plattformsingenjör', 'Supporttekniker', 'Webbutvecklare'])
        ->and($this->model->requests[$before]['content'][0]['text'])->toContain('Kubernetes')->toContain('Plattformsingenjör')
        ->and($resume->matches()->where('job_posting_id', $platform->id)->exists())->toBeTrue();
});

it('removes additions and refuses repeats, long values and too many', function (): void {
    uploadCv();

    Livewire::test(ResumeMatches::class)->set('adding.skills', 'php')->call('add', 'skills')->assertHasErrors('adding.skills')->assertSee('”php” finns redan.');
    Livewire::test(ResumeMatches::class)->set('adding.skills', str_repeat('a', 61))->call('add', 'skills')->assertHasErrors('adding.skills');
    Livewire::test(ResumeMatches::class)->set('adding.roles', '   ')->call('add', 'roles')->assertHasNoErrors();
    Livewire::test(ResumeMatches::class)->call('add', 'languages')->assertStatus(404);
    expect(Resume::query()->sole()->edited)->toBeFalse();

    Livewire::test(ResumeMatches::class)
        ->set('adding.skills', 'Docker')
        ->call('add', 'skills')
        ->call('removeItem', 'skills', 'docker')
        ->assertDontSeeHtml('data-own');
    expect(Resume::query()->sole()->additions()['skills'])->toBe([]);

    Resume::query()->sole()->update(['additions' => ['roles' => [], 'skills' => array_map(fn (int $i): string => "Skill {$i}", range(1, ResumeMatches::MAX_ADDITIONS))]]);
    Livewire::test(ResumeMatches::class)->set('adding.skills', 'Docker')->call('add', 'skills')->assertHasErrors('adding.skills');
});

it('removes Claude\'s roles and skills, and brings them back when added again', function (): void {
    uploadCv();

    Livewire::test(ResumeMatches::class)
        ->call('removeItem', 'skills', 'Laravel')
        ->call('removeItem', 'roles', 'Webbutvecklare')
        ->call('removeItem', 'skills', 'Not in the profile')
        ->assertSeeHtml('data-edited')
        ->assertDontSeeHtml('Ta bort Laravel')
        ->assertSeeHtml('Ta bort PHP');

    $resume = Resume::query()->sole();
    expect($resume->removals())->toBe(['roles' => ['Webbutvecklare'], 'skills' => ['Laravel']])
        ->and($resume->profile()->skills)->toBe(['PHP'])
        ->and($resume->profile()->keywords)->toBe(['php'])
        // Claude's own profile is untouched.
        ->and($resume->profile['skills'])->toBe(['PHP', 'Laravel']);

    // Not an addition of the user's own: it is simply back.
    Livewire::test(ResumeMatches::class)->set('adding.skills', 'laravel')->call('add', 'skills')->assertHasNoErrors()->assertDontSeeHtml('data-own');
    expect(Resume::query()->sole()->removals()['skills'])->toBe([])
        ->and(Resume::query()->sole()->additions()['skills'])->toBe([]);
});

it('keeps additions apart from Claude\'s profile when the preferences change', function (): void {
    uploadCv();
    Livewire::test(ResumeMatches::class)->set('adding.skills', 'Docker')->call('add', 'skills');
    $before = count($this->model->requests);

    Livewire::test(ResumeMatches::class)->set('preferences', 'Distans')->call('savePreferences')->assertSee('Docker');

    $resume = Resume::query()->sole();
    expect($this->model->requests[$before]['content'][0]['text'])->not->toContain('Docker')
        ->and($this->model->requests[$before + 1]['content'][0]['text'])->toContain('Docker')
        ->and($resume->profile['skills'])->toBe(['PHP', 'Laravel'])
        ->and($resume->additions()['skills'])->toBe(['Docker'])
        ->and($resume->edited)->toBeFalse();
});

it('tells the user when the file is not a CV', function (): void {
    $this->model->profile['is_cv'] = false;

    uploadCv()->assertSee('Filen ser inte ut att vara ett CV.');

    $resume = Resume::query()->sole();
    expect($resume->status)->toBe(ResumeStatus::Failed)
        ->and($resume->profile)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('fails the run when Claude cannot score anything, keeping the profile', function (): void {
    $matcher = app(ResumeMatcher::class);
    $resume = $matcher->prepare($this->user, 'cv.pdf', null);
    $matcher->analyze($resume, FAKE_PDF);

    $this->model->failNext = new MatchingException('overloaded');
    expect(fn () => $matcher->score($resume, [$this->backend->id]))->toThrow(MatchingException::class);
    $matcher->finish($resume->id, $resume->run, failed: true);

    $resume->refresh();
    expect($resume->status)->toBe(ResumeStatus::Failed)
        ->and($resume->error)->toBe('Jobbie AI kunde inte bedöma annonserna. Försök igen om en stund.')
        ->and($resume->profile)->not->toBeNull();
});

it('ignores queued work from an earlier run', function (): void {
    uploadCv();
    $resume = Resume::query()->sole();
    $requests = count($this->model->requests);

    (new ScoreResumeMatches($resume->id, $resume->run - 1, [$this->nurse->id]))->handle(app(ResumeMatcher::class));

    expect($this->model->requests)->toHaveCount($requests)
        ->and($resume->matches()->where('job_posting_id', $this->nurse->id)->exists())->toBeFalse();
});

it('limits the runs per day', function (): void {
    config(['radar.matching.daily_runs' => 2]);

    uploadCv()->assertHasNoErrors();
    Livewire::test(ResumeMatches::class)->call('searchAgain')->assertHasNoErrors();
    Livewire::test(ResumeMatches::class)->call('searchAgain')->assertHasErrors('run');
    uploadCv()->assertHasErrors('cv');

    // Others are not affected.
    $this->actingAs(User::factory()->create());
    uploadCv()->assertHasNoErrors();

    RateLimiter::clear('resume-runs:'.$this->user->id);
});

it('accepts only PDF files', function (): void {
    Livewire::test(ResumeMatches::class)
        ->set('cv', UploadedFile::fake()->createWithContent('cv.txt', 'Mitt CV'))
        ->call('upload')
        ->assertHasErrors(['cv' => 'mimes']);

    expect(Resume::query()->count())->toBe(0);
});

it('removes the CV and its matches', function (): void {
    uploadCv();

    Livewire::test(ResumeMatches::class)->call('remove')->assertViewHas('resume', null);

    expect(Resume::query()->count())->toBe(0)
        ->and(\App\Models\ResumeMatch::query()->count())->toBe(0);
});

it('keeps each user to their own CV', function (): void {
    uploadCv();

    $this->actingAs(User::factory()->create());
    Livewire::test(ResumeMatches::class)->assertViewHas('resume', null)->assertDontSee('Senior webbutvecklare');
});

it('says so when matching is not configured', function (): void {
    $this->app->forgetInstance(LanguageModel::class);
    unset($this->app[LanguageModel::class]);

    $this->get(route('match'))->assertOk()->assertSee('Matchning är inte påslagen.');
});

it('links the page from the menu and requires an account', function (): void {
    $this->get(route('jobs'))->assertSee(route('match'));

    auth()->logout();
    $this->get(route('match'))->assertRedirect(route('login'));
});
