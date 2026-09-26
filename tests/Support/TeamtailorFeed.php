<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Builds Teamtailor style jobs.json and jobs.rss responses and fakes a
 * board that serves them with the real paging rules (?page=N with 100
 * items, ?offset=N with 100 items).
 */
final class TeamtailorFeed
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function item(int|string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => (string) $id,
            'url' => "https://career.example.se/jobs/{$id}-utvecklare",
            'title' => "Utvecklare {$id}",
            'content_html' => "<p>Annons {$id}</p>",
            'date_published' => '2026-09-01T08:00:00+02:00',
            '_jobposting' => [
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'title' => "Utvecklare {$id}",
                'datePosted' => '2026-09-01T08:00:00+02:00',
                'employmentType' => 'FULL_TIME',
                'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Exempel AB'],
                'jobLocation' => [[
                    '@type' => 'Place',
                    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Stockholm', 'addressCountry' => 'Sweden'],
                ]],
            ],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function page(array $items, string $host = 'career.example.se', string $title = 'Exempel AB'): array
    {
        return [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $title,
            'home_page_url' => "https://{$host}/",
            'feed_url' => "https://{$host}/jobs.json",
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, array{department?: string, remote?: string}>  $items  guid => fields
     */
    public static function rss(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:tt="https://teamtailor.com/locations"><channel><title>Exempel AB</title>';

        foreach ($items as $guid => $fields) {
            $xml .= "<item><title>Utvecklare {$guid}</title><guid>{$guid}</guid>";
            if (isset($fields['department'])) {
                $xml .= '<tt:department>'.htmlspecialchars($fields['department'], ENT_XML1).'</tt:department>';
            }
            if (isset($fields['remote'])) {
                $xml .= "<remoteStatus>{$fields['remote']}</remoteStatus>";
            }
            $xml .= '</item>';
        }

        return $xml.'</channel></rss>';
    }

    /**
     * Drop every earlier Http::fake() stub. Stubs accumulate and the first
     * match wins, so a test that changes what a host serves must reset.
     */
    public static function reset(): void
    {
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
    }

    /**
     * Fake a board serving the given items over paged JSON and RSS,
     * replacing all earlier fakes.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array{department?: string, remote?: string}>  $rss
     * @param  Closure(Request): mixed|null  $override  Return a response to replace the default one.
     */
    public static function fake(string $host, array $items, array $rss = [], ?Closure $override = null): void
    {
        self::reset();

        Http::fake(function (Request $request) use ($host, $items, $rss, $override) {
            if ($override !== null && ($response = $override($request)) !== null) {
                return $response;
            }

            $parts = parse_url($request->url());
            if (($parts['host'] ?? null) !== $host) {
                return null;
            }

            parse_str($parts['query'] ?? '', $query);

            return match ($parts['path'] ?? '') {
                '/jobs.json' => Http::response(self::page(
                    array_slice($items, (max(1, (int) ($query['page'] ?? 1)) - 1) * 100, 100),
                    $host,
                )),
                '/jobs.rss' => Http::response(
                    self::rss(array_slice($rss, (int) ($query['offset'] ?? 0), 100, true)),
                    200,
                    ['Content-Type' => 'application/rss+xml'],
                ),
                default => Http::response('Not found', 404),
            };
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function items(int $count, int $firstId = 1000): array
    {
        return array_map(static fn (int $i): array => self::item($firstId + $i), range(0, $count - 1));
    }
}
