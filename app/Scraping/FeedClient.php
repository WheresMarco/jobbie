<?php

declare(strict_types=1);

namespace App\Scraping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a board's jobs.json (JSON Feed 1.1), page by page.
 */
final readonly class FeedClient
{
    public const int PAGE_SIZE = 100;

    public const int MAX_PAGES = 100;

    public function __construct(
        private string $userAgent,
        private int $timeout,
    ) {
    }

    /**
     * @throws FeedException When the first page cannot be read. Failures on
     *                       later pages only mark the feed as truncated.
     */
    public function fetch(string $host): Feed
    {
        $requested = "https://{$host}/jobs.json";
        $response = $this->get($requested, 1);
        $finalUrl = self::finalUrl($response, $requested);
        $first = $json = self::decode($response);
        $base = strtok($finalUrl, '?') ?: $finalUrl;

        /** @var array<string, array<string, mixed>> $items */
        $items = [];
        $truncated = false;
        $page = 1;

        while (true) {
            $pageItems = self::items($json);
            $new = 0;
            foreach ($pageItems as $item) {
                $id = is_array($item) ? self::itemId($item) : null;
                if ($id !== null && ! isset($items[$id])) {
                    /** @var array<string, mixed> $item */
                    $items[$id] = $item;
                    $new++;
                }
            }

            if (count($pageItems) < self::PAGE_SIZE) {
                break;
            }

            // A full page without new ids means paging is not advancing, so
            // there is no way to know whether everything was seen.
            if ($new === 0 || $page >= self::MAX_PAGES) {
                $truncated = true;

                break;
            }

            $page++;
            try {
                $json = self::decode($this->get($base, $page));
            } catch (FeedException) {
                $truncated = true;

                break;
            }
        }

        return new Feed(
            title: self::stringOrNull($first['title'] ?? null),
            homePageUrl: self::stringOrNull($first['home_page_url'] ?? null),
            finalUrl: $finalUrl,
            items: array_values($items),
            truncated: $truncated,
            pages: $page,
        );
    }

    /**
     * @throws FeedException
     */
    private function get(string $url, int $page): Response
    {
        try {
            $response = $this->request()->get($url, ['page' => $page]);
        } catch (Throwable $e) {
            throw FeedException::failed($e->getMessage());
        }

        if (in_array($response->status(), [404, 410], true)) {
            throw FeedException::dead("HTTP {$response->status()}");
        }

        if (! $response->successful()) {
            throw FeedException::failed("HTTP {$response->status()}");
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->acceptJson()
            ->timeout($this->timeout)
            ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
            ->retry(
                [1000, 3000],
                when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
    }

    /**
     * @return array<mixed>
     *
     * @throws FeedException
     */
    private static function decode(Response $response): array
    {
        return self::decodeBody($response->body());
    }

    /**
     * Decode a jobs.json body. Accepts a JSON Feed whose "items" is a list
     * that is empty or has at least one item with a _jobposting block.
     *
     * @return array<mixed>
     *
     * @throws FeedException When the body is not a Teamtailor job feed.
     */
    public static function decodeBody(string $body): array
    {
        $json = json_decode($body, true);

        if (! is_array($json)
            || ! is_string($json['version'] ?? null)
            || ! str_contains($json['version'], 'jsonfeed')
            || ! is_array($json['items'] ?? null)
            || ! array_is_list($json['items'])) {
            throw FeedException::dead('Not a JSON feed');
        }

        foreach ($json['items'] as $item) {
            if (is_array($item) && isset($item['_jobposting'])) {
                return $json;
            }
        }

        if ($json['items'] === []) {
            return $json;
        }

        throw FeedException::dead('JSON feed without job postings');
    }

    /**
     * @param  array<mixed>  $json  A feed that passed decode().
     * @return list<mixed>
     */
    private static function items(array $json): array
    {
        return is_array($json['items'] ?? null) ? array_values($json['items']) : [];
    }

    /**
     * @param  array<mixed>  $item
     */
    private static function itemId(array $item): ?string
    {
        $id = $item['id'] ?? null;

        return is_string($id) || is_int($id) ? (string) $id : null;
    }

    private static function finalUrl(Response $response, string $requested): string
    {
        $redirects = $response->headers()['X-Guzzle-Redirect-History'] ?? [];

        return $redirects === [] ? $requested : (string) end($redirects);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
