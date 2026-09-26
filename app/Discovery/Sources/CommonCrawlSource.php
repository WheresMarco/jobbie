<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Source D. Every *.teamtailor.com host in the most recent Common Crawl
 * indexes, through the CDX API: showNumPages first, then page by page with
 * a pause in between. Slug hosts often redirect to a custom domain, which
 * source A picks up when the board is scraped.
 */
final readonly class CommonCrawlSource implements CandidateSource
{
    public const string COLLINFO_URL = 'https://index.commoncrawl.org/collinfo.json';

    /** Teamtailor's own hosts, never career sites. */
    private const array IGNORED_LABELS = ['www', 'app', 'api', 'assets', 'support', 'help', 'status', 'blog', 'career', 'careers', 'partners', 'developer', 'docs'];

    public function __construct(
        private int $indexCount,
        private string $userAgent,
        private int $timeout,
    ) {
    }

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::CommonCrawl;
    }

    public function collect(): LazyCollection
    {
        /** @var LazyCollection<int, CandidateData> $candidates */
        $candidates = LazyCollection::make(function () {
            $seen = [];

            foreach ($this->indexes() as $index => $cdxApi) {
                $pages = $this->pageCount($cdxApi);

                for ($page = 0; $page < $pages; $page++) {
                    if ($page > 0) {
                        Sleep::for(1)->second();
                    }

                    foreach ($this->hostsOnPage($cdxApi, $page) as $host) {
                        if (! isset($seen[$host])) {
                            $seen[$host] = true;

                            yield new CandidateData($host, CandidateSourceType::CommonCrawl, Strength::Strong, $index);
                        }
                    }
                }
            }
        });

        return $candidates;
    }

    /**
     * @return array<string, string> index id => CDX API URL, newest first
     */
    private function indexes(): array
    {
        $collections = $this->http()->get(self::COLLINFO_URL)->throw()->json();

        $indexes = [];
        foreach (is_array($collections) ? $collections : [] as $collection) {
            if (is_array($collection) && is_string($collection['id'] ?? null) && is_string($collection['cdx-api'] ?? null)) {
                $indexes[$collection['id']] = $collection['cdx-api'];
            }
        }

        return array_slice($indexes, 0, max(1, $this->indexCount), true);
    }

    private function pageCount(string $cdxApi): int
    {
        $response = $this->http()->get($cdxApi, $this->query() + ['showNumPages' => 'true']);
        if (! $response->successful()) {
            Log::warning('Common Crawl page count failed', ['api' => $cdxApi, 'status' => $response->status()]);

            return 0;
        }

        $json = $response->json();

        return is_array($json) && is_numeric($json['pages'] ?? null) ? (int) $json['pages'] : 0;
    }

    /**
     * @return list<string>
     */
    private function hostsOnPage(string $cdxApi, int $page): array
    {
        try {
            $response = $this->http()->get($cdxApi, $this->query() + ['page' => $page]);
        } catch (Throwable $e) {
            Log::warning('Common Crawl page failed', ['api' => $cdxApi, 'page' => $page, 'error' => $e->getMessage()]);

            return [];
        }

        // 404 means no captures on this page.
        if (! $response->successful()) {
            return [];
        }

        $hosts = [];
        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            $record = json_decode($line, true);
            $url = is_array($record) && is_string($record['url'] ?? null) ? $record['url'] : null;
            $host = $url === null ? null : self::slugHost($url);
            if ($host !== null) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    public static function slugHost(string $url): ?string
    {
        $host = parse_url(str_contains($url, '://') ? $url : "https://{$url}", PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        if (preg_match('/^([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)\.teamtailor\.com$/', $host, $m) !== 1) {
            return null;
        }

        return in_array($m[1], self::IGNORED_LABELS, true) ? null : $host;
    }

    /**
     * @return array<string, string>
     */
    private function query(): array
    {
        return ['url' => '*.teamtailor.com', 'output' => 'json', 'fl' => 'url'];
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->timeout($this->timeout)
            ->retry([2000, 5000, 10000], throw: false);
    }
}
