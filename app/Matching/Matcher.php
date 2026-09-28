<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * Scores a posting against a profile, from 0 to 100.
 *
 * Three parts, each counted only when the profile fills it in, and the sum
 * scaled to 100:
 *
 * - Title (45): the share of a wanted title's words found in the posting
 *   title, best title counts. The whole phrase counts as all words.
 * - Skills (40): skills found in the title, department or description.
 *   SKILLS_FOR_FULL_SCORE of them (or all, if fewer are listed) give full
 *   points, so listing many skills is not punished.
 * - Location (15): the posting is in a wanted city, or fully remote, and
 *   its way of working is one the profile accepts. Postings that do not
 *   say how they work get the benefit of the doubt.
 *
 * An excluded word in the posting title gives 0 whatever else matches.
 */
final class Matcher
{
    public const int TITLE_WEIGHT = 45;

    public const int SKILLS_WEIGHT = 40;

    public const int LOCATION_WEIGHT = 15;

    public const int SKILLS_FOR_FULL_SCORE = 4;

    /** RemoteStatus value => JobFilters::REMOTE_OPTIONS value. */
    private const array REMOTE_OPTIONS = ['fully' => 'fully', 'hybrid' => 'hybrid', 'onsite' => 'onsite', 'none' => 'onsite'];

    /**
     * @param  list<string>  $cities
     * @param  string|null  $remote  RemoteStatus value.
     */
    public function match(Criteria $criteria, string $title, ?string $department, ?string $description, array $cities, ?string $remote): MatchResult
    {
        foreach ($criteria->excludedPatterns as $term => $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return new MatchResult(score: 0, excluded: $term);
            }
        }

        $earned = 0.0;
        $possible = 0;

        $bestTitle = null;
        if ($criteria->hasTitles()) {
            $possible += self::TITLE_WEIGHT;
            $best = 0.0;
            foreach ($criteria->titlePatterns as $wanted) {
                $share = self::titleShare($wanted, $title);
                if ($share > $best) {
                    $best = $share;
                    $bestTitle = $wanted['title'];
                }
            }
            $earned += $best * self::TITLE_WEIGHT;
        }

        $skills = [];
        if ($criteria->hasSkills()) {
            $possible += self::SKILLS_WEIGHT;
            $text = $title."\n".$department."\n".$description;
            foreach ($criteria->skillPatterns as $term => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $skills[] = (string) $term;
                }
            }
            $needed = min(count($criteria->skillPatterns), self::SKILLS_FOR_FULL_SCORE);
            $earned += min(1.0, count($skills) / $needed) * self::SKILLS_WEIGHT;
        }

        $location = false;
        if ($criteria->hasLocation()) {
            $possible += self::LOCATION_WEIGHT;
            $location = self::locationFits($criteria, $cities, $remote);
            $earned += $location ? self::LOCATION_WEIGHT : 0;
        }

        return new MatchResult(
            score: $possible === 0 ? 0 : (int) round(100 * $earned / $possible),
            title: $bestTitle,
            skills: $skills,
            location: $location,
        );
    }

    /**
     * @param  array{title: string, phrase: string, words: list<string>}  $wanted
     */
    private static function titleShare(array $wanted, string $title): float
    {
        if (preg_match($wanted['phrase'], $title) === 1) {
            return 1.0;
        }
        if ($wanted['words'] === []) {
            return 0.0;
        }

        $found = 0;
        foreach ($wanted['words'] as $pattern) {
            if (preg_match($pattern, $title) === 1) {
                $found++;
            }
        }

        return $found / count($wanted['words']);
    }

    /**
     * @param  list<string>  $cities
     */
    private static function locationFits(Criteria $criteria, array $cities, ?string $remote): bool
    {
        $mode = self::REMOTE_OPTIONS[$remote ?? ''] ?? null;

        if ($criteria->remoteSet !== [] && $mode !== null && ! isset($criteria->remoteSet[$mode])) {
            return false;
        }
        if ($criteria->citySet === [] || $mode === 'fully') {
            return true;
        }
        foreach ($cities as $city) {
            if (isset($criteria->citySet[mb_strtolower($city)])) {
                return true;
            }
        }

        return false;
    }
}
