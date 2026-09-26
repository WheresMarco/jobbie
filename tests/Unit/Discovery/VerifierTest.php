<?php

declare(strict_types=1);

use App\Discovery\Verifier;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Net\DnsAnswer;
use App\Net\DnsRecordType;
use App\Net\DnsStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TeamtailorFeed;

function verifier(): Verifier
{
    return new Verifier(app(App\Net\Contracts\DnsResolver::class), 'RadarTest/1.0', 5, 10);
}

function feedResponse(string $host = 'career.kry.se'): Illuminate\Http\Client\Response|GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(TeamtailorFeed::page([TeamtailorFeed::item(1)], $host, 'Kry'));
}

it('verifies a custom domain with a multi step CNAME chain', function (): void {
    Http::fake(['career.kry.se/jobs.json' => feedResponse()]);

    $verdict = verifier()->verify('career.kry.se', Strength::Weak);

    expect($verdict->stage)->toBe(CandidateStage::Verified)
        ->and($verdict->reason)->toBe('cname:ext')
        ->and($verdict->cnameChain)->toBe(['ext.teamtailor.com', 'teamtailor.map.fastly.net'])
        ->and($verdict->finalHost)->toBe('career.kry.se')
        ->and($verdict->homePageUrl)->toBe('https://career.kry.se/')
        ->and($verdict->feedTitle)->toBe('Kry');
});

it('verifies a unique ext entry', function (): void {
    Http::fake(['careers.airmee.com/jobs.json' => feedResponse('careers.airmee.com')]);

    expect(verifier()->verify('careers.airmee.com', Strength::Weak)->reason)->toBe('cname:ext_unique');
});

it('falls back to Teamtailor addresses when the CNAME is flattened', function (): void {
    $this->dns->add(new DnsAnswer('jobs.flat.se', DnsRecordType::A, DnsStatus::Ok, [], ['151.101.65.242']));
    Http::fake(['jobs.flat.se/jobs.json' => feedResponse('jobs.flat.se')]);

    $verdict = verifier()->verify('jobs.flat.se', Strength::Weak);

    expect($verdict->stage)->toBe(CandidateStage::Verified)
        ->and($verdict->reason)->toBe('ip:151.101.65.242/32');
});

it('stops at DNS for names without records', function (DnsStatus $status): void {
    $this->dns->add(new DnsAnswer('jobb.gone.se', DnsRecordType::A, $status));

    expect(verifier()->verify('jobb.gone.se', Strength::Strong))
        ->stage->toBe(CandidateStage::NoDns)
        ->reason->toBe($status->value);

    Http::assertNothingSent();
})->with([DnsStatus::NxDomain, DnsStatus::NoData]);

it('keeps candidates for the next run when DNS fails', function (): void {
    $this->dns->add(DnsAnswer::failed('jobb.flaky.se', DnsRecordType::A, 'timeout'));

    expect(verifier()->verify('jobb.flaky.se', Strength::Weak))
        ->stage->toBe(CandidateStage::New)
        ->reason->toBe('dns_error');
});

it('rejects weak candidates without a fingerprint before any HTTP', function (): void {
    expect(verifier()->verify('careers.einride.tech', Strength::Weak))
        ->stage->toBe(CandidateStage::Rejected)
        ->reason->toBe('no_fingerprint');

    Http::assertNothingSent();
});

it('checks strong candidates over HTTP even without a fingerprint', function (): void {
    Http::fake(['careers.einride.tech/jobs.json' => feedResponse('careers.einride.tech')]);

    expect(verifier()->verify('careers.einride.tech', Strength::Strong)->stage)->toBe(CandidateStage::Verified);
});

it('does not accept an HTML page with status 200', function (): void {
    Http::fake(['*' => Http::response('<!DOCTYPE html><html><body>Jobb</body></html>')]);

    expect(verifier()->verify('career.kry.se', Strength::Weak))
        ->stage->toBe(CandidateStage::FingerprintMatch)
        ->reason->toBe('cname:ext;not_a_feed')
        ->and(verifier()->verify('careers.einride.tech', Strength::Strong))
        ->stage->toBe(CandidateStage::Rejected)
        ->reason->toBe('not_a_feed');
});

it('records timeouts', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    expect(verifier()->verify('career.kry.se', Strength::Weak))
        ->stage->toBe(CandidateStage::FingerprintMatch)
        ->reason->toBe('cname:ext;http_error');
});

it('looks for HTML markers when a strong candidate has no feed', function (): void {
    Http::fake([
        'careers.einride.tech/jobs.json' => Http::response('Not found', 404),
        'careers.einride.tech/' => Http::response('<script src="https://scripts.teamtailor-cdn.com/x.js"></script>'),
        'jobs.tele2.com/jobs.json' => Http::response('Not found', 404),
        'jobs.tele2.com/' => Http::response('<html>SuccessFactors</html>'),
    ]);

    $verdicts = verifier()->verifyMany(['careers.einride.tech' => Strength::Strong, 'jobs.tele2.com' => Strength::Strong]);

    expect($verdicts['careers.einride.tech'])
        ->stage->toBe(CandidateStage::FingerprintMatch)
        ->reason->toBe('html:cdn')
        ->and($verdicts['jobs.tele2.com'])
        ->stage->toBe(CandidateStage::Rejected)
        ->reason->toBe('http_404');
});

it('sends slug hosts straight to HTTP because of the DNS wildcard', function (): void {
    Http::fake(['kry.teamtailor.com/jobs.json' => Http::response('', 301, ['Location' => 'https://career.kry.se/jobs.json']), 'career.kry.se/jobs.json' => feedResponse()]);

    $verdict = verifier()->verify('kry.teamtailor.com', Strength::Strong);

    expect($verdict->stage)->toBe(CandidateStage::Verified)
        ->and($verdict->finalHost)->toBe('career.kry.se')
        ->and($this->dns->queries)->toBe([]);
});

it('verifies many hosts and keeps the input order', function (): void {
    Http::fake(fn (Request $r) => feedResponse((string) parse_url($r->url(), PHP_URL_HOST)));

    $verdicts = verifier()->verifyMany([
        'jobs.timefold.ai' => Strength::Weak,
        'careers.einride.tech' => Strength::Weak,
        'career.clasohlson.com' => Strength::Weak,
        'nope.example.se' => Strength::Weak,
    ]);

    expect(array_keys($verdicts))->toBe(['jobs.timefold.ai', 'careers.einride.tech', 'career.clasohlson.com', 'nope.example.se'])
        ->and(array_map(fn ($v) => $v->stage, $verdicts))->toBe([
            'jobs.timefold.ai' => CandidateStage::Verified,
            'careers.einride.tech' => CandidateStage::Rejected,
            'career.clasohlson.com' => CandidateStage::Verified,
            'nope.example.se' => CandidateStage::NoDns,
        ]);

    Http::assertSentCount(2);
});
