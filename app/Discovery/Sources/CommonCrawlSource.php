<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\CommonCrawlIndex;
use App\Discovery\Contracts\CandidateSource;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use Illuminate\Support\LazyCollection;

/**
 * Source D. Every *.teamtailor.com host in the most recent Common Crawl
 * indexes. Slug hosts often redirect to a custom domain, which source A
 * picks up when the board is scraped.
 */
final readonly class CommonCrawlSource implements CandidateSource
{
    /** Teamtailor's own hosts, never career sites. */
    private const array IGNORED_LABELS = ['www', 'app', 'api', 'assets', 'support', 'help', 'status', 'blog', 'career', 'careers', 'partners', 'developer', 'docs'];

    public function __construct(private CommonCrawlIndex $index)
    {
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

            foreach ($this->index->urls('*.teamtailor.com') as ['index' => $index, 'url' => $url]) {
                $host = self::slugHost($url);
                if ($host !== null && ! isset($seen[$host])) {
                    $seen[$host] = true;

                    yield new CandidateData($host, CandidateSourceType::CommonCrawl, Strength::Strong, $index);
                }
            }
        });

        return $candidates;
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
}
