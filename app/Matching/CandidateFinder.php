<?php

declare(strict_types=1);

namespace App\Matching;

use App\Tracking\UserMarks;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Picks the active postings most like a profile, for Claude to score.
 * Scoring costs money per posting, so this is the cheap first cut.
 *
 * On MySQL, FULLTEXT in boolean mode without + (any term matches) ranks
 * the postings: title terms in the title count three times, and all terms
 * anywhere count once. Other databases (the tests) count matching terms
 * with LIKE instead.
 */
final readonly class CandidateFinder
{
    /** Role words in the title say more than any word in the text. */
    private const int TITLE_WEIGHT = 3;

    /** InnoDB's default innodb_ft_min_token_size. */
    private const int MIN_TERM_LENGTH = 3;

    private const int MAX_TERMS = 60;

    /**
     * Ids best first. Leaves out postings and employers the user has
     * hidden, postings outside the profile's countries (postings without
     * a known location stay), and repeats: the same title from the same
     * employer, or with the same opening text under another spelling of
     * the employer's name. Staffing companies and merged boards make them.
     *
     * @return list<int>
     */
    public function find(Profile $profile, UserMarks $marks, int $limit): array
    {
        $titleTerms = self::terms($profile->titleTerms);
        $allTerms = self::terms([...$profile->titleTerms, ...$profile->keywords]);
        if ($allTerms === [] || $limit < 1) {
            return [];
        }

        $query = DB::table('job_postings')
            ->leftJoin('boards', 'boards.id', '=', 'job_postings.board_id')
            ->where('job_postings.active', true)
            ->select(['job_postings.id', 'job_postings.title'])
            ->selectRaw('coalesce(job_postings.company, boards.name, boards.canonical_host) as employer')
            ->selectRaw('substr(job_postings.description, 1, 300) as opening')
            ->when($profile->countries !== [], fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereExists(fn (Builder $l) => $l->from('job_posting_locations')
                    ->whereColumn('job_posting_locations.job_posting_id', 'job_postings.id')
                    ->whereIn('job_posting_locations.country', $profile->countries))
                ->orWhereNotExists(fn (Builder $l) => $l->from('job_posting_locations')
                    ->whereColumn('job_posting_locations.job_posting_id', 'job_postings.id'))));

        // Room for what is dropped below.
        $fetch = ($limit + count($marks->hiddenIds)) * 3;

        $rows = DB::connection()->getDriverName() === 'mysql'
            ? $this->rankWithFullText($query, $titleTerms, $allTerms, $fetch)
            : $this->rankWithLike($query, $titleTerms, $allTerms, $fetch);

        $ids = $seen = [];
        foreach ($rows as $row) {
            if (isset($marks->hiddenIds[$row['id']]) || $marks->isEmployerHidden($row['employer'])) {
                continue;
            }

            $title = mb_strtolower(trim($row['title']));
            $keys = ["e|{$title}|".mb_strtolower($row['employer'])];
            if ($row['opening'] !== '') {
                $keys[] = "o|{$title}|{$row['opening']}";
            }
            if (array_intersect_key($seen, array_flip($keys)) !== []) {
                continue;
            }
            $seen += array_fill_keys($keys, true);

            $ids[] = $row['id'];
            if (count($ids) === $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $titleTerms
     * @param  list<string>  $allTerms
     * @return list<array{id: int, title: string, employer: string, opening: string}>
     */
    private function rankWithFullText(Builder $query, array $titleTerms, array $allTerms, int $fetch): array
    {
        $all = self::booleanQuery($allTerms);
        $rank = 'match(job_postings.title, job_postings.company, job_postings.description) against (? in boolean mode)';
        $bindings = [$all];
        if ($titleTerms !== []) {
            $rank = self::TITLE_WEIGHT.' * match(job_postings.title) against (? in boolean mode) + '.$rank;
            $bindings = [self::booleanQuery($titleTerms), $all];
        }

        $rows = $query
            ->whereFullText(['job_postings.title', 'job_postings.company', 'job_postings.description'], $all, ['mode' => 'boolean'])
            ->orderByRaw("{$rank} desc", $bindings)
            ->orderByDesc('job_postings.id')
            ->limit($fetch)
            ->get();

        return $rows->map(static fn (object $row): array => self::row($row))->all();
    }

    /**
     * @param  list<string>  $titleTerms
     * @param  list<string>  $allTerms
     * @return list<array{id: int, title: string, employer: string, opening: string}>
     */
    private function rankWithLike(Builder $query, array $titleTerms, array $allTerms, int $fetch): array
    {
        $rows = $query
            ->addSelect('job_postings.description')
            ->where(function (Builder $q) use ($allTerms): void {
                foreach ($allTerms as $term) {
                    $q->orWhere('job_postings.title', 'like', "%{$term}%")
                        ->orWhere('job_postings.description', 'like', "%{$term}%");
                }
            })
            ->get();

        $ranked = [];
        foreach ($rows as $row) {
            $title = mb_strtolower((string) $row->title);
            $text = $title.' '.mb_strtolower((string) $row->description);
            $rank = 0;
            foreach ($titleTerms as $term) {
                $rank += str_contains($title, $term) ? self::TITLE_WEIGHT : 0;
            }
            foreach ($allTerms as $term) {
                $rank += str_contains($text, $term) ? 1 : 0;
            }
            $ranked[] = ['rank' => $rank, ...self::row($row)];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['rank'], $b['id']] <=> [$a['rank'], $a['id']]);

        return array_map(static fn (array $r): array => ['id' => $r['id'], 'title' => $r['title'], 'employer' => $r['employer'], 'opening' => $r['opening']], array_slice($ranked, 0, $fetch));
    }

    /**
     * Distinct lower-case words, with everything but letters and digits
     * as separators. Phrases are split up: in boolean mode InnoDB drops
     * the terms after a quoted phrase that holds a stopword or a word
     * shorter than its minimum, and for ranking the words do as well.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    private static function terms(array $terms): array
    {
        $words = [];
        foreach ($terms as $term) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= self::MIN_TERM_LENGTH) {
                    $words[$word] = true;
                }
            }
        }

        // Keys like "365" turn into integers.
        return array_slice(array_map(strval(...), array_keys($words)), 0, self::MAX_TERMS);
    }

    /**
     * Any of the words, each as a prefix.
     *
     * @param  list<string>  $terms
     */
    private static function booleanQuery(array $terms): string
    {
        return implode(' ', array_map(static fn (string $t): string => "{$t}*", $terms));
    }

    /**
     * @return array{id: int, title: string, employer: string, opening: string}
     */
    private static function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'employer' => (string) $row->employer,
            'opening' => (string) $row->opening,
        ];
    }
}
