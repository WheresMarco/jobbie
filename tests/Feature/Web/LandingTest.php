<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Livewire\JobBrowser;
use App\Matching\Contracts\LanguageModel;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLanguageModel;

uses(RefreshDatabase::class);

it('shows the landing page to guests on /', function (): void {
    $board = Board::factory()->custom('career.kry.se')->active()->create(['name' => 'Kry']);
    JobPosting::factory()->for($board)->count(3)->create(['company' => null]);

    $this->get('/')
        ->assertOk()
        ->assertDontSeeLivewire(JobBrowser::class)
        ->assertSee('Hitta jobben innan alla andra gör det.')
        ->assertSeeInOrder(['3', 'Aktiva annonser', '1', 'Arbetsgivare'])
        ->assertSee('Greenhouse')
        ->assertSee(route('login'))
        ->assertSee(route('register'));
});

it('shows the job list on / once logged in', function (): void {
    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()->assertSeeLivewire(JobBrowser::class)->assertDontSee('Hitta jobben innan alla andra gör det.');
});

it('only offers logging in when registration is off', function (): void {
    config(['radar.registration' => false]);

    $this->get('/')->assertOk()->assertSee(route('login'))->assertDontSee(route('register'));
});

it('only tells about CV matching when it is turned on', function (bool $enabled): void {
    unset($this->app[LanguageModel::class]);
    if ($enabled) {
        $this->app->instance(LanguageModel::class, new FakeLanguageModel());
    }

    $page = $this->get('/')->assertOk();
    $enabled ? $page->assertSee('Låt Jobbie AI läsa ditt CV') : $page->assertDontSee('Låt Jobbie AI läsa ditt CV');
})->with([true, false]);

it('shows a logo for each job source, or its name when there is none', function (): void {
    $page = $this->get('/')->assertOk();

    foreach (Platform::cases() as $platform) {
        $logo = collect(['svg', 'png'])->map(fn (string $ext): string => "images/sources/{$platform->value}.{$ext}")
            ->first(fn (string $path): bool => is_file(public_path($path)));

        $page->assertSeeHtml('data-source="'.$platform->value.'"');
        $logo !== null
            ? $page->assertSeeHtml('src="'.asset($logo).'" alt="'.__("radar.platforms.{$platform->value}").'"')
            : $page->assertSeeHtml('>'.__("radar.platforms.{$platform->value}").'</span>');
    }

    $page->assertSee(asset('images/sources/greenhouse.svg'), false);
});

it('credits the author in the footer', function (): void {
    $this->get('/')->assertOk()->assertSee('Ett projekt av')->assertSee('https://wheresmar.co', false);
    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('https://wheresmar.co', false);
});
