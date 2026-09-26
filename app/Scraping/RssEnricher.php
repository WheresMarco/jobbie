<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\RemoteStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Reads department and remote status from jobs.rss, which the JSON feed
 * lacks. Never throws: on any failure it logs and returns what it has, and
 * the scrape goes on without enrichment.
 */
final readonly class RssEnricher
{
    public const int PAGE_SIZE = 100;

    public const int MAX_PAGES = 100;

    public function __construct(
        private string $userAgent,
        private int $timeout,
    ) {
    }

    /**
     * @return array<string, RssEnrichment> Keyed by guid, which equals the JSON item id.
     */
    public function fetch(string $host): array
    {
        $enrichments = [];

        try {
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = Http::withUserAgent($this->userAgent)
                    ->timeout($this->timeout)
                    ->retry([1000], throw: false)
                    ->get("https://{$host}/jobs.rss", ['offset' => $page * self::PAGE_SIZE]);

                if (! $response->successful()) {
                    throw new \RuntimeException("HTTP {$response->status()}");
                }

                $items = self::parse($response->body());
                $new = 0;
                foreach ($items as $guid => $enrichment) {
                    if (! isset($enrichments[$guid])) {
                        $enrichments[$guid] = $enrichment;
                        $new++;
                    }
                }

                if ($new === 0 || count($items) < self::PAGE_SIZE) {
                    break;
                }
            }
        } catch (Throwable $e) {
            Log::warning('RSS enrichment failed', ['host' => $host, 'error' => $e->getMessage()]);
        }

        return $enrichments;
    }

    /**
     * @return array<string, RssEnrichment>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new \RuntimeException('Invalid RSS');
        }

        $items = [];
        foreach ($document->xpath('//item') ?: [] as $item) {
            $guid = trim((string) $item->guid);
            if ($guid === '') {
                continue;
            }

            $department = self::child($item, 'department');
            $remote = self::child($item, 'remoteStatus');

            $items[$guid] = new RssEnrichment(
                department: $department,
                remote: $remote === null ? null : RemoteStatus::tryFrom(strtolower($remote)),
            );
        }

        return $items;
    }

    /**
     * First child with the given local name in any namespace.
     */
    private static function child(SimpleXMLElement $item, string $localName): ?string
    {
        $nodes = $item->xpath("*[local-name()='{$localName}']") ?: [];
        $value = isset($nodes[0]) ? trim((string) $nodes[0]) : '';

        return $value === '' ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
