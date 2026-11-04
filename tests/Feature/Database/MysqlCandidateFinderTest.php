<?php

declare(strict_types=1);

use App\Matching\CandidateFinder;
use App\Matching\Profile;
use App\Models\Board;
use App\Models\JobPosting;
use App\Models\User;
use App\Tracking\PostingTracker;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
| Runs only against MySQL (composer test:mysql): candidate search for CV
| matching ranks with FULLTEXT. DatabaseMigrations, since InnoDB updates
| FULLTEXT indexes on commit only.
*/

uses(DatabaseMigrations::class);

it('ranks title matches first and finds any of the terms', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $board = Board::factory()->active()->create();
    $posting = static function (string $title, string $description, string $country = 'SE') use ($board): JobPosting {
        $p = JobPosting::factory()->for($board)->create(['title' => $title, 'description' => $description, 'company' => $title.' AB']);
        $p->locations()->create(['city' => 'Stockholm', 'country' => $country]);

        return $p;
    };

    $mentions = $posting('Ekonomiassistent', 'Du arbetar nära våra utvecklare och vår e-handel.');
    $developer = $posting('Backendutvecklare', 'Vi bygger i Go.');
    $laravel = $posting('Kundtjänst', 'Vårt system är byggt i Laravel och PHP.');
    $posting('Lagerarbetare', 'Truck och plock.');
    $posting('PHP-utvecklare', 'Laravel', 'DK');

    $profile = Profile::fromArray([
        'countries' => ['SE'],
        'title_terms' => ['utvecklare', 'backendutvecklare', 'tech lead'],
        'keywords' => ['laravel', 'php', 'e-handel', '365'],
    ]);

    $ids = (new CandidateFinder())->find($profile, app(PostingTracker::class)->marks(User::factory()->create()), 10);

    // The title match first; the two with words in the text tie. The
    // warehouse job matches nothing and the Danish one is out of scope.
    expect($ids)->toHaveCount(3)
        ->and($ids[0])->toBe($developer->id)
        ->and(array_slice($ids, 1))->toEqualCanonicalizing([$laravel->id, $mentions->id]);
})->group('mysql');
