<?php

declare(strict_types=1);

use App\Matching\Contracts\LanguageModel;
use App\Matching\MatchingException;
use App\Matching\MatchScorer;
use App\Matching\Profile;
use App\Models\Board;
use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLanguageModel;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->model = new FakeLanguageModel();
    $this->profile = Profile::fromArray($this->model->profile);
    $board = Board::factory()->active()->create(['name' => 'Kry']);
    $this->postings = [
        JobPosting::factory()->for($board)->create(['id' => 11, 'title' => 'Utvecklare', 'description' => str_repeat('Lång text. ', 1000)]),
        JobPosting::factory()->for($board)->create(['id' => 12, 'title' => 'Säljare', 'company' => null]),
    ];
});

it('describes each posting, shortened, without the search terms', function (): void {
    (new MatchScorer($this->model))->score($this->profile, 'Distans', $this->postings);

    $text = $this->model->requests[0]['content'][0]['text'];
    expect($text)->toContain('<posting id="11">')
        ->toContain("Arbetsgivare: Kry\n")
        ->toContain('Ort: Stockholm (SE)')
        ->toContain("<preferences>\nDistans\n</preferences>")
        ->not->toContain('title_terms')
        ->and(mb_strlen($text))->toBeLessThan(MatchScorer::DESCRIPTION_CHARS + 3000);
});

it('keeps only known ids and clamps the scores', function (): void {
    $model = new class () implements LanguageModel {
        public function json(string $system, array $content, array $schema, int $maxTokens): array
        {
            return ['matches' => [
                ['id' => 11, 'score' => 140, 'reason' => '  Passar.  '],
                ['id' => 12, 'score' => -5, 'reason' => 'Nej.'],
                ['id' => 99, 'score' => 50, 'reason' => 'Okänd.'],
                ['id' => '11', 'score' => 10, 'reason' => 'Fel typ.'],
            ]];
        }
    };

    expect((new MatchScorer($model))->score($this->profile, null, $this->postings))->toBe([
        11 => ['score' => 100, 'reason' => 'Passar.'],
        12 => ['score' => 0, 'reason' => 'Nej.'],
    ]);
});

it('fails when nothing could be scored', function (): void {
    $model = new class () implements LanguageModel {
        public function json(string $system, array $content, array $schema, int $maxTokens): array
        {
            return ['matches' => []];
        }
    };

    expect(fn () => (new MatchScorer($model))->score($this->profile, null, $this->postings))->toThrow(MatchingException::class);
});

it('reads stored profiles leniently', function (): void {
    $profile = Profile::fromArray([
        'headline' => ' Sjuksköterska ',
        'seniority' => 'wizard',
        'years' => -2,
        'skills' => ['Vård', '', 'Vård', 7],
        'countries' => ['se', 'Sweden', 'NO'],
        'remote' => 'sometimes',
    ]);

    expect($profile->headline)->toBe('Sjuksköterska')
        ->and($profile->seniority)->toBe('mid')
        ->and($profile->years)->toBeNull()
        ->and($profile->skills)->toBe(['Vård'])
        ->and($profile->countries)->toBe(['SE', 'NO'])
        ->and($profile->remote)->toBe('any')
        ->and($profile->titleTerms)->toBe([]);
});
