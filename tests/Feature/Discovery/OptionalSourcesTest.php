<?php

declare(strict_types=1);

use App\Enums\CandidateSourceType;
use App\Enums\Strength;
use App\Models\Candidate;
use App\Models\Domain;
use App\Net\BigQuery;
use App\Net\DnsAnswer;
use App\Net\DnsRecordType;
use App\Net\DnsStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function txt(string $domain, DnsStatus $status, array $values = []): DnsAnswer
{
    return new DnsAnswer($domain, DnsRecordType::TXT, $status, [], $values);
}

it('probes TXT customers with the extended prefix list (source E)', function (): void {
    config(['radar.txt_prefixes' => ['career', 'jobb']]);
    Domain::factory()->create(['base_domain' => 'airmee.com']);
    Domain::factory()->create(['base_domain' => 'other.se']);
    Domain::factory()->create(['base_domain' => 'flaky.se']);
    Domain::factory()->create(['base_domain' => 'fresh.se', 'txt_match' => false, 'txt_checked_at' => now()->subDays(3)]);
    $this->dns->add(txt('airmee.com', DnsStatus::Ok, ['v=spf1 -all', 'teamtailor=b6ed34c038c2d280d171164a301c49cd']))
        ->add(txt('other.se', DnsStatus::NoData))
        ->add(DnsAnswer::failed('flaky.se', DnsRecordType::TXT, 'timeout'));

    $this->artisan('radar:discover', ['--source' => ['txt'], '--sync' => true])->assertSuccessful();

    expect(Candidate::query()->orderBy('host')->pluck('host')->all())->toBe(['career.airmee.com', 'jobb.airmee.com'])
        ->and(Candidate::query()->findOrFail('jobb.airmee.com'))
        ->source->toBe(CandidateSourceType::Txt)
        ->strength->toBe(Strength::Strong)
        ->source_ref->toBe('jobb')
        ->and(Domain::query()->findOrFail('airmee.com')->txt_match)->toBeTrue()
        ->and(Domain::query()->findOrFail('other.se')->txt_match)->toBeFalse()
        // DNS errors are retried; recently checked domains are skipped.
        ->and(Domain::query()->findOrFail('flaky.se')->txt_checked_at)->toBeNull()
        ->and($this->dns->queries)->not->toContain('TXT fresh.se');
});

it('turns search results into weak candidates (source G)', function (): void {
    config(['radar.search_api.key' => 'secret', 'radar.search_api.queries' => ['"teamtailor" karriär'], 'radar.search_api.pages' => 3]);
    $page = fn (array $urls) => Http::response(['web' => ['results' => array_map(fn ($u) => ['url' => $u], $urls)]]);
    Http::fakeSequence('api.search.brave.com/*')
        ->pushResponse($page(array_merge(['https://career.kry.se/jobs', 'https://Career.Kry.se/'], array_map(fn ($i) => "https://site{$i}.se/", range(1, 18)))))
        ->pushResponse($page(['https://jobs.timefold.ai/jobs/1']));

    $this->artisan('radar:discover', ['--source' => ['search'], '--sync' => true])->assertSuccessful();

    expect(Candidate::query()->count())->toBe(20)
        ->and(Candidate::query()->findOrFail('career.kry.se'))
        ->source->toBe(CandidateSourceType::SearchApi)
        ->strength->toBe(Strength::Weak)
        ->source_ref->toBe('"teamtailor" karriär');

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Subscription-Token', 'secret') && $r['offset'] === 1);
    Http::assertSentCount(2);
});

it('skips the search source without a key', function (): void {
    Log::spy();

    $this->artisan('radar:discover', ['--source' => ['search'], '--sync' => true])->assertSuccessful();

    expect(Candidate::query()->count())->toBe(0);
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('queries HTTP Archive through BigQuery with a service account (source F)', function (): void {
    Carbon::setTestNow('2026-09-26');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $credentials = tempnam(sys_get_temp_dir(), 'sa');
    file_put_contents($credentials, json_encode(['client_email' => 'radar@example.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
    config(['radar.http_archive.google_project' => 'my-project', 'radar.http_archive.google_credentials' => $credentials]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'bigquery.googleapis.com/bigquery/v2/projects/my-project/queries' => Http::response([
            'jobComplete' => true,
            'jobReference' => ['jobId' => 'job1', 'location' => 'US'],
            'rows' => [['f' => [['v' => 'www.kry.se']]]],
            'pageToken' => 'next',
        ]),
        'bigquery.googleapis.com/bigquery/v2/projects/my-project/queries/job1*' => Http::response([
            'jobComplete' => true,
            'jobReference' => ['jobId' => 'job1', 'location' => 'US'],
            'rows' => [['f' => [['v' => 'careers.example.co.uk']]]],
        ]),
    ]);

    $this->artisan('radar:discover', ['--source' => ['httparchive'], '--sync' => true])->assertSuccessful();

    expect(Candidate::query()->orderBy('host')->pluck('host')->all())->toBe(['careers.example.co.uk', 'www.kry.se'])
        ->and(Candidate::query()->findOrFail('www.kry.se')->source_ref)->toBe('2026-08-01')
        ->and(Domain::query()->orderBy('base_domain')->pluck('base_domain')->all())->toBe(['example.co.uk', 'kry.se']);

    Http::assertSent(function (Request $r): bool {
        if ($r->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        [$header, $claims, $signature] = explode('.', (string) $r['assertion']);
        $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

        return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $claims['iss'] === 'radar@example.iam.gserviceaccount.com'
            && $signature !== '';
    });
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/queries')
        && $r->hasHeader('Authorization', 'Bearer tok')
        && $r['queryParameters'][0]['parameterValue']['value'] === '2026-08-01'
        && $r['maximumBytesBilled'] === (string) config('radar.http_archive.max_bytes_billed')
        && str_contains($r['query'], "technology.technology = 'Teamtailor'"));
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'queries/job1') && $r['pageToken'] === 'next');

    @unlink($credentials);
});

it('skips HTTP Archive without credentials', function (): void {
    Log::spy();

    expect((new BigQuery(null, null, 5))->configured())->toBeFalse();
    $this->artisan('radar:discover', ['--source' => ['httparchive'], '--sync' => true])->assertSuccessful();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});
