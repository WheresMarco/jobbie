<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Net\Contracts\DnsResolver;
use App\Net\DnsAnswer;
use App\Net\DnsRecordType;
use App\Net\DnsStatus;
use App\Scraping\FeedClient;
use App\Scraping\FeedException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Decides whether a host is a Teamtailor career site, from cheap to
 * expensive: DNS, fingerprint, jobs.json, and for strong candidates whose
 * feed is missing, the HTML of the start page.
 *
 * A host only counts as verified when its job feed can be read.
 */
final readonly class Verifier
{
    public function __construct(
        private DnsResolver $dns,
        private string $userAgent,
        private int $timeout,
        private int $poolSize,
    ) {
    }

    public function verify(string $host, Strength $strength): Verdict
    {
        return $this->verifyMany([$host => $strength])[$host];
    }

    /**
     * @param  array<string, Strength>  $candidates  host => strength
     * @return array<string, Verdict> keyed by host, in input order
     */
    public function verifyMany(array $candidates): array
    {
        $hosts = array_map(strval(...), array_keys($candidates));
        $verdicts = [];
        $toHttp = [];

        // Slug hosts sit behind a DNS wildcard, so DNS says nothing about them.
        $dnsHosts = array_values(array_filter($hosts, static fn (string $h): bool => ! Fingerprint::isSlugHost($h)));
        $answers = $dnsHosts === [] ? [] : $this->dns->resolveMany($dnsHosts, DnsRecordType::A);

        foreach ($hosts as $host) {
            if (Fingerprint::isSlugHost($host)) {
                $toHttp[$host] = new Verdict($host, CandidateStage::DnsOk, 'slug_host');

                continue;
            }

            $verdict = $this->afterDns($host, $candidates[$host], $answers[$host] ?? DnsAnswer::failed($host, DnsRecordType::A, 'No answer'));
            if ($verdict->stage === CandidateStage::FingerprintMatch || ($verdict->stage === CandidateStage::DnsOk && $candidates[$host] === Strength::Strong)) {
                $toHttp[$host] = $verdict;
            } else {
                $verdicts[$host] = $verdict;
            }
        }

        foreach (array_chunk($toHttp, max(1, $this->poolSize), true) as $chunk) {
            $verdicts = [...$verdicts, ...$this->checkFeeds($chunk, $candidates)];
        }

        $ordered = [];
        foreach ($hosts as $host) {
            $ordered[$host] = $verdicts[$host];
        }

        return $ordered;
    }

    private function afterDns(string $host, Strength $strength, DnsAnswer $answer): Verdict
    {
        return match ($answer->status) {
            DnsStatus::NxDomain, DnsStatus::NoData => new Verdict($host, CandidateStage::NoDns, $answer->status->value),
            // Unknown, not negative: leave the candidate for the next run.
            DnsStatus::Error => new Verdict($host, CandidateStage::New, 'dns_error'),
            DnsStatus::Ok => $this->fingerprint($host, $strength, $answer),
        };
    }

    private function fingerprint(string $host, Strength $strength, DnsAnswer $answer): Verdict
    {
        $match = Fingerprint::matchCname($answer->cnameChain);
        $reason = $match === null ? null : "cname:{$match}";

        if ($match === null && ($range = Fingerprint::matchAddress($answer->values)) !== null) {
            $reason = "ip:{$range}";
        }

        if ($reason !== null) {
            return new Verdict($host, CandidateStage::FingerprintMatch, $reason, $answer->cnameChain);
        }

        return $strength === Strength::Strong
            ? new Verdict($host, CandidateStage::DnsOk, null, $answer->cnameChain)
            : new Verdict($host, CandidateStage::Rejected, 'no_fingerprint', $answer->cnameChain);
    }

    /**
     * @param  array<string, Verdict>  $pending
     * @param  array<string, Strength>  $candidates
     * @return array<string, Verdict>
     */
    private function checkFeeds(array $pending, array $candidates): array
    {
        $responses = $this->pool(array_keys($pending), '/jobs.json');
        $verdicts = [];
        $fallback = [];

        foreach ($pending as $host => $verdict) {
            $host = (string) $host;
            $response = $responses[$host] ?? null;

            if (! $response instanceof Response) {
                $verdicts[$host] = $this->failed($verdict, 'http_error');

                continue;
            }

            if ($response->successful()) {
                try {
                    $feed = FeedClient::decodeBody($response->body());
                } catch (FeedException) {
                    $verdicts[$host] = $this->failed($verdict, 'not_a_feed');

                    continue;
                }

                $redirects = $response->headers()['X-Guzzle-Redirect-History'] ?? [];
                $finalUrl = $redirects === [] ? "https://{$host}/jobs.json" : (string) end($redirects);

                $verdicts[$host] = $verdict->with([
                    'stage' => CandidateStage::Verified,
                    'reason' => $verdict->reason,
                    'finalHost' => strtolower((string) parse_url($finalUrl, PHP_URL_HOST)),
                    'homePageUrl' => is_string($feed['home_page_url'] ?? null) ? $feed['home_page_url'] : null,
                    'feedTitle' => is_string($feed['title'] ?? null) ? $feed['title'] : null,
                ]);

                continue;
            }

            if ($response->status() === 404 && $candidates[$host] === Strength::Strong) {
                $fallback[$host] = $verdict;

                continue;
            }

            $verdicts[$host] = $this->failed($verdict, 'http_'.$response->status());
        }

        if ($fallback !== []) {
            $pages = $this->pool(array_keys($fallback), '/');
            foreach ($fallback as $host => $verdict) {
                $host = (string) $host;
                $page = $pages[$host] ?? null;
                $marker = $page instanceof Response && $page->successful()
                    ? Fingerprint::matchHtml($page->body(), $page->headers())
                    : null;

                // A marker is logged, but no board is created without a readable feed.
                $verdicts[$host] = $marker === null
                    ? $this->failed($verdict, 'http_404')
                    : $verdict->with(['stage' => CandidateStage::FingerprintMatch, 'reason' => "html:{$marker}"]);
            }
        }

        return $verdicts;
    }

    /**
     * A DNS fingerprint is kept even when HTTP fails, so the host can be
     * rechecked; everything else is rejected.
     */
    private function failed(Verdict $verdict, string $reason): Verdict
    {
        $stage = $verdict->stage === CandidateStage::FingerprintMatch ? CandidateStage::FingerprintMatch : CandidateStage::Rejected;

        return $verdict->with(['stage' => $stage, 'reason' => $verdict->stage === CandidateStage::FingerprintMatch ? "{$verdict->reason};{$reason}" : $reason]);
    }

    /**
     * @param  list<string|int>  $hosts
     * @return array<string, Response|Throwable>
     */
    private function pool(array $hosts, string $path): array
    {
        $hosts = array_map(strval(...), $hosts);

        /** @var array<string, Response|Throwable> $responses */
        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $host) => $pool->as($host)
                ->withUserAgent($this->userAgent)
                ->timeout($this->timeout)
                ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->get("https://{$host}{$path}"),
            $hosts,
        ));

        return $responses;
    }
}
