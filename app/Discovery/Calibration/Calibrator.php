<?php

declare(strict_types=1);

namespace App\Discovery\Calibration;

use App\Discovery\Fingerprint;
use App\Net\Contracts\CertificateFetcher;
use App\Net\Contracts\DnsResolver;
use App\Net\DnsRecordType;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Collects everything radar:calibrate records about one host. Each section
 * fails independently; an error in one is stored and the rest still run.
 */
final readonly class Calibrator
{
    private const int HEAD_EXCERPT_BYTES = 20_000;

    public function __construct(
        private DnsResolver $dns,
        private CertificateFetcher $certificates,
        private string $userAgent,
        private int $httpTimeout,
    ) {
    }

    /**
     * @param  list<CalibrationSection>  $sections
     */
    public function calibrate(string $host, array $sections): CalibrationResult
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $rootDomain = self::rootDomain($host);
        $errors = [];

        $a = $aaaa = $rootTxt = $certificate = null;
        $http = [];

        if (in_array(CalibrationSection::Dns, $sections, true)) {
            $a = $this->dns->resolve($host, DnsRecordType::A);
            $aaaa = $this->dns->resolve($host, DnsRecordType::AAAA);
        }

        if (in_array(CalibrationSection::Txt, $sections, true)) {
            $rootTxt = $this->dns->resolve($rootDomain, DnsRecordType::TXT);
        }

        if (in_array(CalibrationSection::Tls, $sections, true)) {
            try {
                $certificate = $this->certificates->fetch($host);
            } catch (Throwable $e) {
                $errors[CalibrationSection::Tls->value] = $e->getMessage();
            }
        }

        if (in_array(CalibrationSection::Http, $sections, true)) {
            $http[] = $this->snapshot("https://{$host}/", $this->homeDetails(...));
            $http[] = $this->snapshot("https://{$host}/jobs.json", $this->feedDetails(...));
        }

        return new CalibrationResult(
            host: $host,
            rootDomain: $rootDomain,
            capturedAt: Carbon::now()->toIso8601String(),
            a: $a,
            aaaa: $aaaa,
            rootTxt: $rootTxt,
            certificate: $certificate,
            http: $http,
            errors: $errors,
        );
    }

    /**
     * Registrable domain approximated by dropping the first label. Good
     * enough for calibration input, which is always a subdomain of a normal
     * suffix. DomainName (Phase 2) replaces this with the Public Suffix List.
     */
    public static function rootDomain(string $host): string
    {
        $labels = explode('.', $host);

        return count($labels) > 2 ? implode('.', array_slice($labels, 1)) : $host;
    }

    /**
     * @param  callable(Response): array<string, mixed>  $details
     */
    private function snapshot(string $url, callable $details): HttpSnapshot
    {
        try {
            $response = Http::withUserAgent($this->userAgent)
                ->timeout($this->httpTimeout)
                ->withOptions(['allow_redirects' => ['max' => 10, 'track_redirects' => true]])
                ->get($url);
        } catch (Throwable $e) {
            return new HttpSnapshot($url, null, null, [], [], error: $e->getMessage());
        }

        $redirects = array_values(array_map(strval(...), $response->headers()['X-Guzzle-Redirect-History'] ?? []));
        $headers = array_filter(
            $response->headers(),
            static fn (string $name): bool => ! str_starts_with($name, 'X-Guzzle-'),
            ARRAY_FILTER_USE_KEY,
        );

        return new HttpSnapshot(
            url: $url,
            status: $response->status(),
            finalUrl: $redirects === [] ? $url : $redirects[array_key_last($redirects)],
            headers: array_map(static fn (array $values): array => array_values(array_map(strval(...), $values)), $headers),
            redirects: $redirects,
            details: $details($response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function homeDetails(Response $response): array
    {
        $html = $response->body();

        return [
            'asset_hosts' => self::assetHosts($html),
            'generator' => self::metaGenerator($html),
            'html_marker' => Fingerprint::matchHtml($html, $response->headers()),
            'head_excerpt' => self::headExcerpt($html),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedDetails(Response $response): array
    {
        $json = json_decode($response->body(), true);
        if (! is_array($json)) {
            return ['is_json' => false];
        }

        $items = is_array($json['items'] ?? null) ? $json['items'] : null;

        return [
            'is_json' => true,
            'version' => is_string($json['version'] ?? null) ? $json['version'] : null,
            'title' => is_string($json['title'] ?? null) ? $json['title'] : null,
            'home_page_url' => is_string($json['home_page_url'] ?? null) ? $json['home_page_url'] : null,
            'feed_url' => is_string($json['feed_url'] ?? null) ? $json['feed_url'] : null,
            'item_count' => $items === null ? null : count($items),
            'has_jobposting' => $items !== null && array_any(
                $items,
                static fn (mixed $item): bool => is_array($item) && isset($item['_jobposting']),
            ),
        ];
    }

    /**
     * Distinct hosts referenced by script, link, img, iframe and source tags.
     *
     * @return list<string>
     */
    public static function assetHosts(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new DOMXPath($document))->query(
            '//script/@src | //link/@href | //img/@src | //iframe/@src | //source/@src',
        );

        $hosts = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            $host = parse_url((string) $node->nodeValue, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host)] = true;
            }
        }

        $hosts = array_keys($hosts);
        sort($hosts);

        return $hosts;
    }

    private static function metaGenerator(string $html): ?string
    {
        return preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m) === 1
            ? $m[1]
            : null;
    }

    private static function headExcerpt(string $html): string
    {
        $end = stripos($html, '</head>');

        return substr($html, 0, $end === false ? self::HEAD_EXCERPT_BYTES : min($end + 7, self::HEAD_EXCERPT_BYTES));
    }
}
