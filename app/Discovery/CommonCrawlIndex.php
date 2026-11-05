<?php

declare(strict_types=1);

namespace App\Discovery;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The Common Crawl CDX API: every captured URL under a pattern in the most
 * recent indexes, page by page with a pause in between (showNumPages
 * first). Failed pages are logged and skipped.
 */
final readonly class CommonCrawlIndex
{
    public const string COLLINFO_URL = 'https://index.commoncrawl.org/collinfo.json';

    public function __construct(
        private int $indexCount,
        private string $userAgent,
        private int $timeout,
    ) {
    }

    /**
     * @return LazyCollection<int, array{index: string, url: string}>
     */
    public function urls(string $pattern): LazyCollection
    {
        /** @var LazyCollection<int, array{index: string, url: string}> $urls */
        $urls = LazyCollection::make(function () use ($pattern) {
            foreach ($this->indexes() as $index => $cdxApi) {
                $pages = $this->pageCount($cdxApi, $pattern);

                for ($page = 0; $page < $pages; $page++) {
                    if ($page > 0) {
                        Sleep::for(1)->second();
                    }

                    foreach ($this->urlsOnPage($cdxApi, $pattern, $page) as $url) {
                        yield ['index' => $index, 'url' => $url];
                    }
                }
            }
        });

        return $urls;
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

    private function pageCount(string $cdxApi, string $pattern): int
    {
        try {
            $response = $this->http()->get($cdxApi, $this->query($pattern) + ['showNumPages' => 'true']);
        } catch (Throwable $e) {
            Log::warning('Common Crawl page count failed', ['api' => $cdxApi, 'pattern' => $pattern, 'error' => $e->getMessage()]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('Common Crawl page count failed', ['api' => $cdxApi, 'pattern' => $pattern, 'status' => $response->status()]);

            return 0;
        }

        $json = $response->json();

        return is_array($json) && is_numeric($json['pages'] ?? null) ? (int) $json['pages'] : 0;
    }

    /**
     * @return list<string>
     */
    private function urlsOnPage(string $cdxApi, string $pattern, int $page): array
    {
        try {
            $response = $this->http()->get($cdxApi, $this->query($pattern) + ['page' => $page]);
        } catch (Throwable $e) {
            Log::warning('Common Crawl page failed', ['api' => $cdxApi, 'pattern' => $pattern, 'page' => $page, 'error' => $e->getMessage()]);

            return [];
        }

        // 404 means no captures on this page.
        if (! $response->successful()) {
            return [];
        }

        $urls = [];
        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && is_string($record['url'] ?? null)) {
                $urls[] = $record['url'];
            }
        }

        return $urls;
    }

    /**
     * @return array<string, string>
     */
    private function query(string $pattern): array
    {
        return ['url' => $pattern, 'output' => 'json', 'fl' => 'url'];
    }

    /**
     * The index often answers 503 or 504 under load, and discovery can
     * wait: up to five tries per request over two minutes.
     */
    private function http(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->timeout(max($this->timeout, 120))
            ->retry([5000, 15000, 30000, 60000], throw: false);
    }
}
