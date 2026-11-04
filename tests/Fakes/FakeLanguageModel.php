<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Matching\Contracts\LanguageModel;
use App\Matching\MatchingException;
use Closure;

/**
 * Stands in for Claude. Profile requests get $profile back. Scoring
 * requests get a score per posting from $scoreUsing, given the title.
 */
final class FakeLanguageModel implements LanguageModel
{
    /** @var list<array{system: string, content: list<array<string, mixed>>, schema: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, mixed> */
    public array $profile = [
        'is_cv' => true,
        'headline' => 'Senior webbutvecklare',
        'summary' => 'Erfaren backendutvecklare med PHP och Laravel.',
        'seniority' => 'senior',
        'years' => 13,
        'roles' => ['Webbutvecklare', 'Backendutvecklare'],
        'skills' => ['PHP', 'Laravel'],
        'languages' => ['Svenska', 'Engelska'],
        'locations' => ['Linköping'],
        'countries' => ['SE'],
        'remote' => 'any',
        'title_terms' => ['utvecklare', 'developer'],
        'keywords' => ['php', 'laravel'],
    ];

    /** @var Closure(string): int */
    public Closure $scoreUsing;

    /** Thrown by the next request, then cleared. */
    public ?MatchingException $failNext = null;

    public function __construct()
    {
        $this->scoreUsing = static fn (string $title): int => str_contains(mb_strtolower($title), 'utvecklare') ? 90 : 30;
    }

    public function json(string $system, array $content, array $schema, int $maxTokens): array
    {
        $this->requests[] = ['system' => $system, 'content' => $content, 'schema' => $schema];

        if ($this->failNext !== null) {
            $e = $this->failNext;
            $this->failNext = null;

            throw $e;
        }

        if (! isset($schema['properties']['matches'])) {
            return $this->profile;
        }

        $matches = [];
        foreach ($this->postingsIn(count($this->requests) - 1) as $id => $title) {
            $matches[] = ['id' => $id, 'score' => ($this->scoreUsing)($title), 'reason' => "Passar som {$title}."];
        }

        return ['matches' => $matches];
    }

    /**
     * Posting id => title in a scoring request.
     *
     * @return array<int, string>
     */
    public function postingsIn(int $request): array
    {
        $text = (string) ($this->requests[$request]['content'][0]['text'] ?? '');
        preg_match_all('~<posting id="(\d+)">\nTitel: (.*)~', $text, $found, PREG_SET_ORDER);

        $postings = [];
        foreach ($found as [, $id, $title]) {
            $postings[(int) $id] = $title;
        }

        return $postings;
    }

    /**
     * Every posting sent for scoring, by title.
     *
     * @return list<string>
     */
    public function scoredTitles(): array
    {
        $titles = [];
        foreach (array_keys($this->requests) as $i) {
            array_push($titles, ...array_values($this->postingsIn($i)));
        }
        sort($titles);

        return $titles;
    }
}
