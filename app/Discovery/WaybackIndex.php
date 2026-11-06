<?php

declare(strict_types=1);

namespace App\Discovery;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The Internet Archive's Wayback CDX API: the captured URL keys under a
 * domain and its subdomains, in SURT order ("com,teamtailor,acme)/jobs"),
 * read from any key onwards.
 *
 * Under load the API sheds domain-wide queries with a "Temporarily
 * Offline" page, sometimes with status 200. Such answers are retried here,
 * and throw WaybackUnavailable when they persist, so a walk is never cut
 * short without notice.
 */
final readonly class WaybackIndex
{
    public const string CDX_URL = 'https://web.archive.org/web/timemap/cdx';

    /** Pauses before each new try of an unusable answer, in seconds. */
    private const array PAUSES = [30, 60, 120];

    public function __construct(
        private string $userAgent,
        private int $timeout,
    ) {
    }

    /**
     * Up to $limit distinct URL keys from $from onwards (from the start
     * when null).
     *
     * @return list<string>
     */
    public function urlKeys(string $domain, ?string $from, int $limit): array
    {
        $query = ['url' => $domain, 'matchType' => 'domain', 'fl' => 'urlkey', 'collapse' => 'urlkey', 'limit' => $limit];
        if ($from !== null) {
            // A resume key is a compressed "urlkey timestamp".
            $query += ['showResumeKey' => 'true', 'resumeKey' => rtrim(strtr(base64_encode((string) gzcompress("{$from} 0")), '+/', '-_'), '=')];
        }

        $keys = [];
        // A blank line separates the keys from the next resume key.
        foreach (preg_split('/\R/', $this->body($query)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                break;
            }
            $keys[] = $line;
        }

        return $keys;
    }

    /**
     * The host part of a URL key ("com,teamtailor,acme:443)/jobs" gives
     * "com,teamtailor,acme:443").
     */
    public static function surtHost(string $urlKey): ?string
    {
        $surt = strstr($urlKey, ')', true);

        return $surt === false || $surt === '' ? null : $surt;
    }

    /**
     * The host of a URL key ("com,teamtailor,acme:443)/" gives
     * acme.teamtailor.com).
     */
    public static function host(string $urlKey): ?string
    {
        $surt = self::surtHost($urlKey);

        return $surt === null ? null : implode('.', array_reverse(explode(',', (string) preg_replace('/:\d+$/', '', $surt))));
    }

    /**
     * The first key after every key of $urlKey's host: ")~" sorts after
     * ")/..." but before longer hosts that start the same way.
     */
    public static function after(string $urlKey): ?string
    {
        $surt = self::surtHost($urlKey);

        return $surt === null ? null : "{$surt})~";
    }

    /**
     * @param  array<string, int|string>  $query
     */
    private function body(array $query): string
    {
        foreach ([0, ...self::PAUSES] as $pause) {
            if ($pause > 0) {
                Sleep::for($pause)->seconds();
            }

            try {
                $response = $this->http()->get(self::CDX_URL, $query);
            } catch (Throwable $e) {
                Log::warning('Wayback request failed', ['error' => $e->getMessage()]);

                continue;
            }

            $body = trim($response->body());
            if ($response->successful() && ! str_starts_with($body, '<')) {
                return $body;
            }

            Log::warning('Wayback request failed', ['status' => $response->status(), 'body' => self::excerpt($response)]);
        }

        throw new WaybackUnavailable('The Wayback CDX API did not answer');
    }

    private static function excerpt(Response $response): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', strip_tags($response->body())), 0, 80);
    }

    /**
     * Retries 5xx answers and timeouts: up to five tries per request over
     * two minutes.
     */
    private function http(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->timeout(max($this->timeout, 180))
            ->retry([5000, 15000, 30000, 60000], throw: false);
    }
}
