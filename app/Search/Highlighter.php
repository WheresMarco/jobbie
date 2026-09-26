<?php

declare(strict_types=1);

namespace App\Search;

use Illuminate\Support\HtmlString;

/**
 * Marks search terms in plain text. Output is escaped HTML.
 */
final class Highlighter
{
    /**
     * @param  list<string>  $terms
     */
    public static function highlight(string $text, array $terms): HtmlString
    {
        $escaped = e($text);
        $pattern = self::pattern($terms);

        if ($pattern === null) {
            return new HtmlString($escaped);
        }

        // Never mark inside HTML entities such as &amp;.
        $parts = preg_split('/(&[a-zA-Z0-9#]+;)/', $escaped, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$escaped];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = (string) preg_replace($pattern, '<mark>$1</mark>', $part);
            }
        }

        return new HtmlString(implode('', $parts));
    }

    /**
     * A window of the text around the first term, or its start.
     *
     * @param  list<string>  $terms
     */
    public static function snippet(?string $text, array $terms, int $length = 240): HtmlString
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return new HtmlString('');
        }

        $position = null;
        foreach ($terms as $term) {
            $found = mb_stripos($text, $term);
            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        $start = $position === null ? 0 : max(0, $position - intdiv($length, 3));
        $excerpt = mb_substr($text, $start, $length);
        $prefix = $start > 0 ? '… ' : '';
        $suffix = $start + $length < mb_strlen($text) ? ' …' : '';

        return self::highlight($prefix.$excerpt.$suffix, $terms);
    }

    /**
     * @param  list<string>  $terms
     */
    private static function pattern(array $terms): ?string
    {
        $parts = array_map(static fn (string $t): string => preg_quote(e($t), '/'), array_filter($terms, static fn (string $t): bool => $t !== ''));
        if ($parts === []) {
            return null;
        }

        usort($parts, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        // Terms match at word starts, like the prefix search.
        return '/(?<![\p{L}\p{N}])('.implode('|', $parts).')/iu';
    }
}
