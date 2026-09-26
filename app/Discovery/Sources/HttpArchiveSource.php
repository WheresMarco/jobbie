<?php

declare(strict_types=1);

namespace App\Discovery\Sources;

use App\Discovery\CandidateData;
use App\Discovery\Contracts\CandidateSource;
use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use App\Models\Domain;
use App\Net\BigQuery;
use App\Net\DomainName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Source F, off by default. HTTP Archive's technology detection (its fork
 * of Wappalyzer) knows Teamtailor, but only through the JavaScript global
 * Teamtailor.Ziggeo, and the crawl mostly covers home pages of popular
 * origins. So this finds sites that embed Teamtailor, typically an
 * employer's main site rather than its career site. Each host becomes a
 * weak candidate and its registrable domain goes into domains, where
 * sources C and E probe it.
 *
 * Runs on BigQuery in your own Google Cloud project, capped by
 * RADAR_HTTP_ARCHIVE_MAX_BYTES.
 */
final readonly class HttpArchiveSource implements CandidateSource
{
    public const string SQL = <<<'SQL'
        SELECT DISTINCT NET.HOST(page) AS host
        FROM `httparchive.crawl.pages`, UNNEST(technologies) AS technology
        WHERE date = @crawl_date
          AND client = 'desktop'
          AND technology.technology = 'Teamtailor'
        SQL;

    public function __construct(
        private BigQuery $bigQuery,
        private DomainName $domains,
        private ?string $crawlDate,
        private int $maxBytesBilled,
    ) {
    }

    public function type(): CandidateSourceType
    {
        return CandidateSourceType::HttpArchive;
    }

    /**
     * The crawl to query: configured, or the first of last month (crawls are
     * dated to the first of the month and published during it).
     */
    public function crawlDate(): string
    {
        return $this->crawlDate ?: Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString();
    }

    public function collect(): LazyCollection
    {
        /** @var LazyCollection<int, CandidateData> $candidates */
        $candidates = LazyCollection::make(function () {
            if (! $this->bigQuery->configured()) {
                Log::warning('HTTP Archive source skipped: set RADAR_HTTP_ARCHIVE_PROJECT and RADAR_HTTP_ARCHIVE_CREDENTIALS');

                return;
            }

            $hosts = $this->bigQuery->column(self::SQL, ['crawl_date' => ['DATE', $this->crawlDate()]], $this->maxBytesBilled);

            $domainRows = [];
            foreach ($hosts as $host) {
                $base = $this->domains->registrable($host);
                if ($base !== null) {
                    $domainRows[$base] = ['base_domain' => $base, 'source' => CandidateSourceType::HttpArchive->value];
                }

                yield new CandidateData($host, CandidateSourceType::HttpArchive, Strength::Weak, $this->crawlDate());
            }

            foreach (array_chunk(array_values($domainRows), 1000) as $chunk) {
                Domain::query()->insertOrIgnore($chunk);
            }
        });

        return $candidates;
    }
}
