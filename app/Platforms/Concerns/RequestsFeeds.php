<?php

declare(strict_types=1);

namespace App\Platforms\Concerns;

use App\Scraping\FeedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP for adapters, with the same rules as FeedClient: 404 and 410 mean
 * the board is gone, other failures are temporary. The using class has
 * $userAgent, $timeout and $poolSize properties.
 */
trait RequestsFeeds
{
    protected function request(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->timeout($this->timeout)
            ->retry(
                [1000, 3000],
                when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
    }

    /**
     * @throws FeedException
     */
    protected function checked(callable $send): Response
    {
        try {
            $response = $send($this->request());
        } catch (Throwable $e) {
            throw FeedException::failed($e->getMessage());
        }

        if (! $response instanceof Response) {
            throw FeedException::failed('No response');
        }

        if (in_array($response->status(), [404, 410], true)) {
            throw FeedException::dead("HTTP {$response->status()}");
        }

        if (! $response->successful()) {
            throw FeedException::failed("HTTP {$response->status()}");
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws FeedException
     */
    protected function getJson(string $url, array $query = []): array
    {
        return self::json($this->checked(static fn (PendingRequest $r): Response => $r->acceptJson()->get($url, $query)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     *
     * @throws FeedException
     */
    protected function postJson(string $url, array $body): array
    {
        return self::json($this->checked(static fn (PendingRequest $r): Response => $r->acceptJson()->asJson()->post($url, $body)));
    }

    /**
     * GET several URLs a few at a time. Failed requests are left out.
     *
     * @param  array<string, string>  $urls  key => URL
     * @return array<string, array<mixed>> key => decoded JSON
     */
    protected function getJsonMany(array $urls): array
    {
        $results = [];

        foreach (array_chunk($urls, max(1, $this->poolSize), true) as $chunk) {
            /** @var array<string, Response|Throwable> $responses */
            $responses = Http::pool(function (Pool $pool) use ($chunk): array {
                $requests = [];
                foreach ($chunk as $key => $url) {
                    $requests[] = $pool->as((string) $key)
                        ->withUserAgent($this->userAgent)
                        ->timeout($this->timeout)
                        ->acceptJson()
                        ->get($url);
                }

                return $requests;
            });

            foreach ($responses as $key => $response) {
                if ($response instanceof Response && $response->successful() && is_array($json = $response->json())) {
                    $results[(string) $key] = $json;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<mixed>
     *
     * @throws FeedException
     */
    private static function json(Response $response): array
    {
        $json = json_decode($response->body(), true);
        if (! is_array($json)) {
            throw FeedException::dead('Not JSON');
        }

        return $json;
    }

    /**
     * Lower case host of a link, which may lack a scheme.
     */
    protected static function host(string $url): ?string
    {
        $host = parse_url(self::withScheme($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    protected static function withScheme(string $url): string
    {
        return str_contains($url, '://') ? $url : "https://{$url}";
    }

    /**
     * Non-empty path segments of a link, URL-decoded.
     *
     * @return list<string>
     */
    protected static function pathSegments(string $url): array
    {
        $path = (string) parse_url(self::withScheme($url), PHP_URL_PATH);

        return array_values(array_map(rawurldecode(...), array_filter(explode('/', $path), static fn (string $s): bool => $s !== '')));
    }

    /**
     * One query parameter of a link, or null.
     */
    protected static function query(string $url, string $name): ?string
    {
        parse_str((string) parse_url(self::withScheme($url), PHP_URL_QUERY), $query);
        $value = $query[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected static function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<mixed>
     */
    protected static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
