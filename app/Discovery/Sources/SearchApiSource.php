<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Source G, off by default. Runs the configured queries against the Brave
 * Search API (key in RADAR_SEARCH_API_KEY) and turns the hosts of the
 * results into weak candidates: only hosts with a DNS fingerprint reach
 * HTTP. One request per second, the limit of the free plan.
 */
final readonly class SearchApiSource implements CandidateSource
{
    private const int PAGE_SIZE = 20;

    /** Brave allows offsets 0 to 9. */
    private const int MAX_PAGES = 10;

    /**
     * @param  list<string>  $queries
     */
    public function __construct(
        private ?string $key,
        private string $endpoint,
        private array $queries,
        private int $pages,
        private string $userAgent,
        private int $timeout,
    ) {
    }

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::SearchApi;
    }

    public function collect(): LazyCollection
    {
        /** @var LazyCollection<int, CandidateData> $candidates */
        $candidates = LazyCollection::make(function () {
            if ($this->key === null || $this->key === '') {
                Log::warning('Search API source skipped: RADAR_SEARCH_API_KEY is not set');

                return;
            }

            $seen = [];
            $first = true;

            foreach ($this->queries as $query) {
                for ($page = 0; $page < min($this->pages, self::MAX_PAGES); $page++) {
                    if (! $first) {
                        Sleep::for(1)->second();
                    }
                    $first = false;

                    $urls = $this->search($query, $page);
                    foreach ($urls as $url) {
                        $host = parse_url($url, PHP_URL_HOST);
                        if (is_string($host) && ! isset($seen[$host = strtolower($host)])) {
                            $seen[$host] = true;

                            yield new CandidateData($host, CandidateSourceType::SearchApi, Strength::Weak, $query);
                        }
                    }

                    if (count($urls) < self::PAGE_SIZE) {
                        break;
                    }
                }
            }
        });

        return $candidates;
    }

    /**
     * @return list<string> Result URLs.
     */
    private function search(string $query, int $page): array
    {
        try {
            $response = Http::withUserAgent($this->userAgent)
                ->timeout($this->timeout)
                ->acceptJson()
                ->withHeaders(['X-Subscription-Token' => (string) $this->key])
                ->retry([2000, 5000], throw: false)
                ->get($this->endpoint, ['q' => $query, 'count' => self::PAGE_SIZE, 'offset' => $page, 'country' => 'se']);
        } catch (Throwable $e) {
            Log::warning('Search API request failed', ['query' => $query, 'error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Search API request failed', ['query' => $query, 'status' => $response->status()]);

            return [];
        }

        $results = $response->json('web.results');

        return array_values(array_filter(
            array_map(static fn (mixed $r): mixed => is_array($r) ? ($r['url'] ?? null) : null, is_array($results) ? $results : []),
            is_string(...),
        ));
    }
}
