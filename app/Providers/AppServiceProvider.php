<?php

declare(strict_types=1);

namespace App\Providers;

use App\Discovery\Calibration\Calibrator;
use App\Discovery\JobStream\JobStreamSync;
use App\Discovery\JobStream\PlatsbankenAdParser;
use App\Discovery\Sources\CommonCrawlSource;
use App\Discovery\Sources\HttpArchiveSource;
use App\Discovery\Sources\PrefixSource;
use App\Discovery\Sources\SearchApiSource;
use App\Discovery\Sources\TxtRecordSource;
use App\Discovery\Verifier;
use App\Models\User;
use App\Net\BigQuery;
use App\Net\Contracts\CertificateFetcher;
use App\Net\Contracts\DnsResolver;
use App\Net\DohDnsResolver;
use App\Net\DomainName;
use App\Net\PublicSuffixList;
use App\Net\StreamCertificateFetcher;
use App\Net\SystemDnsResolver;
use App\Scraping\PostingStore;
use App\Scraping\Teamtailor\FeedClient;
use App\Scraping\Teamtailor\RssEnricher;
use App\Search\JobSearch;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DnsResolver::class, static fn (): DnsResolver => match (config('radar.dns_driver')) {
            'system' => new SystemDnsResolver(),
            default => new DohDnsResolver(
                endpoint: config()->string('radar.doh_endpoint'),
                timeout: config()->integer('radar.dns_timeout'),
                poolSize: config()->integer('radar.dns_pool_size'),
                userAgent: config()->string('radar.user_agent'),
            ),
        });

        $this->app->singleton(DomainName::class, static fn (): DomainName => new DomainName(PublicSuffixList::rules()));

        $this->app->singleton(CertificateFetcher::class, static fn (): CertificateFetcher => new StreamCertificateFetcher(
            timeout: config()->integer('radar.tls_timeout'),
        ));

        $this->app->bind(FeedClient::class, static fn (): FeedClient => new FeedClient(
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(RssEnricher::class, static fn (): RssEnricher => new RssEnricher(
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(Verifier::class, static fn (Application $app): Verifier => new Verifier(
            dns: $app->make(DnsResolver::class),
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
            poolSize: config()->integer('radar.http_pool_size'),
        ));

        $this->app->bind(PrefixSource::class, static fn (Application $app): PrefixSource => new PrefixSource(
            prefixes: array_values(array_map(strval(...), config()->array('radar.prefixes'))),
            domains: $app->make(DomainName::class),
        ));

        $this->app->bind(TxtRecordSource::class, static fn (Application $app): TxtRecordSource => new TxtRecordSource(
            dns: $app->make(DnsResolver::class),
            prefixes: array_values(array_map(strval(...), config()->array('radar.txt_prefixes'))),
            recheckDays: config()->integer('radar.txt_recheck_days'),
        ));

        $this->app->bind(SearchApiSource::class, static fn (): SearchApiSource => new SearchApiSource(
            key: is_string(config('radar.search_api.key')) ? config()->string('radar.search_api.key') : null,
            endpoint: config()->string('radar.search_api.endpoint'),
            queries: array_values(array_map(strval(...), config()->array('radar.search_api.queries'))),
            pages: config()->integer('radar.search_api.pages'),
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(HttpArchiveSource::class, static fn (Application $app): HttpArchiveSource => new HttpArchiveSource(
            bigQuery: new BigQuery(
                project: is_string(config('radar.http_archive.google_project')) ? config()->string('radar.http_archive.google_project') : null,
                credentialsPath: is_string(config('radar.http_archive.google_credentials')) ? config()->string('radar.http_archive.google_credentials') : null,
                timeout: 120,
            ),
            domains: $app->make(DomainName::class),
            crawlDate: is_string(config('radar.http_archive.crawl_date')) ? config()->string('radar.http_archive.crawl_date') : null,
            maxBytesBilled: config()->integer('radar.http_archive.max_bytes_billed'),
        ));

        $this->app->bind(CommonCrawlSource::class, static fn (): CommonCrawlSource => new CommonCrawlSource(
            indexCount: config()->integer('radar.commoncrawl.index_count'),
            userAgent: config()->string('radar.user_agent'),
            timeout: config()->integer('radar.http_timeout'),
        ));

        $this->app->bind(JobStreamSync::class, static fn (Application $app): JobStreamSync => new JobStreamSync(
            domains: $app->make(DomainName::class),
            postings: $app->make(PostingStore::class),
            parser: $app->make(PlatsbankenAdParser::class),
            baseUrl: config()->string('radar.jobstream.base_url'),
            apiKey: is_string(config('radar.jobstream.api_key')) ? config('radar.jobstream.api_key') : null,
            userAgent: config()->string('radar.user_agent'),
        ));

        $this->app->bind(JobSearch::class, static fn (): JobSearch => new JobSearch(
            newJobDays: config()->integer('radar.new_job_days'),
        ));

        $this->app->bind(Calibrator::class, static fn (Application $app): Calibrator => new Calibrator(
            dns: $app->make(DnsResolver::class),
            certificates: $app->make(CertificateFetcher::class),
            domains: $app->make(DomainName::class),
            userAgent: config()->string('radar.user_agent'),
            httpTimeout: config()->integer('radar.http_timeout'),
        ));
    }

    public function boot(): void
    {
        // Statistics and Horizon. Not fillable: only radar:admin grants it.
        Gate::define('admin', static fn (User $user): bool => $user->is_admin);
    }
}
