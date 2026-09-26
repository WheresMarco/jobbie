<?php

declare(strict_types=1);

use App\Net\DnsRecordType;
use App\Net\DnsStatus;
use App\Net\DohDnsResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function dohResolver(): DohDnsResolver
{
    return new DohDnsResolver('https://doh.test/dns-query', 5, 2, 'RadarTest/1.0');
}

it('returns the whole CNAME chain and the final records', function (): void {
    Http::fake(['doh.test/*' => Http::response([
        'Status' => 0,
        'Answer' => [
            ['name' => 'career.kry.se', 'type' => 5, 'TTL' => 60, 'data' => 'ext.teamtailor.com.'],
            ['name' => 'ext.teamtailor.com', 'type' => 5, 'TTL' => 60, 'data' => 'teamtailor.map.fastly.net.'],
            ['name' => 'teamtailor.map.fastly.net', 'type' => 1, 'TTL' => 60, 'data' => '151.101.1.242'],
            ['name' => 'teamtailor.map.fastly.net', 'type' => 1, 'TTL' => 60, 'data' => '151.101.65.242'],
        ],
    ])]);

    $answer = dohResolver()->resolve('career.kry.se', DnsRecordType::A);

    expect($answer->status)->toBe(DnsStatus::Ok)
        ->and($answer->cnameChain)->toBe(['ext.teamtailor.com', 'teamtailor.map.fastly.net'])
        ->and($answer->values)->toBe(['151.101.1.242', '151.101.65.242'])
        ->and($answer->finalName())->toBe('teamtailor.map.fastly.net');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept', 'application/dns-json')
        && $request->hasHeader('User-Agent', 'RadarTest/1.0')
        && $request['name'] === 'career.kry.se'
        && $request['type'] === 'A');
});

it('handles a flattened CNAME as plain records', function (): void {
    Http::fake(['doh.test/*' => Http::response([
        'Status' => 0,
        'Answer' => [['name' => 'jobs.example.se.', 'type' => 1, 'TTL' => 60, 'data' => '151.101.129.242']],
    ])]);

    $answer = dohResolver()->resolve('jobs.example.se', DnsRecordType::A);

    expect($answer->cnameChain)->toBe([])
        ->and($answer->values)->toBe(['151.101.129.242']);
});

it('reports NXDOMAIN, empty answers and server failures', function (int $rcode, DnsStatus $expected): void {
    Http::fake(['doh.test/*' => Http::response(['Status' => $rcode])]);

    expect(dohResolver()->resolve('nope.example.se', DnsRecordType::A)->status)->toBe($expected);
})->with([
    'NXDOMAIN' => [3, DnsStatus::NxDomain],
    'NOERROR without answer' => [0, DnsStatus::NoData],
    'SERVFAIL' => [2, DnsStatus::Error],
]);

it('joins TXT character strings and unescapes them', function (): void {
    Http::fake(['doh.test/*' => Http::response([
        'Status' => 0,
        'Answer' => [
            ['name' => 'airmee.com', 'type' => 16, 'TTL' => 60, 'data' => '"teamtailor=b6ed34c038c2d280d171164a301c49cd"'],
            ['name' => 'airmee.com', 'type' => 16, 'TTL' => 60, 'data' => '"v=spf1 include:_spf.google.com" " ~all"'],
            ['name' => 'airmee.com', 'type' => 16, 'TTL' => 60, 'data' => '"say \"hi\""'],
        ],
    ])]);

    expect(dohResolver()->resolve('airmee.com', DnsRecordType::TXT)->values)->toBe([
        'teamtailor=b6ed34c038c2d280d171164a301c49cd',
        'v=spf1 include:_spf.google.com ~all',
        'say "hi"',
    ]);
});

it('turns transport problems into error answers', function (): void {
    Http::fake(['doh.test/*' => Http::failedConnection()]);

    $answer = dohResolver()->resolve('career.kry.se', DnsRecordType::A);

    expect($answer->status)->toBe(DnsStatus::Error)
        ->and($answer->error)->not->toBeNull();
});

it('treats HTTP errors and malformed bodies as errors', function (): void {
    Http::fakeSequence('doh.test/*')->push('oops', 500)->push('not json', 200);

    expect(dohResolver()->resolve('a.example.se', DnsRecordType::A)->error)->toBe('HTTP 500')
        ->and(dohResolver()->resolve('b.example.se', DnsRecordType::A)->error)->toBe('Malformed DoH response');
});

it('resolves many hosts in pooled chunks', function (): void {
    Http::fake(function (Request $request) {
        return $request['name'] === 'missing.example.se'
            ? Http::response(['Status' => 3])
            : Http::response(['Status' => 0, 'Answer' => [
                ['name' => $request['name'], 'type' => 5, 'TTL' => 60, 'data' => 'ext.teamtailor.com.'],
                ['name' => 'ext.teamtailor.com', 'type' => 1, 'TTL' => 60, 'data' => '151.101.1.242'],
            ]]);
    });

    $answers = dohResolver()->resolveMany(['a.example.se', 'b.example.se', 'missing.example.se'], DnsRecordType::A);

    expect(array_keys($answers))->toBe(['a.example.se', 'b.example.se', 'missing.example.se'])
        ->and($answers['a.example.se']->cnameChain)->toBe(['ext.teamtailor.com'])
        ->and($answers['b.example.se']->status)->toBe(DnsStatus::Ok)
        ->and($answers['missing.example.se']->status)->toBe(DnsStatus::NxDomain);

    Http::assertSentCount(3);
});

it('returns the chain itself for CNAME queries', function (): void {
    Http::fake(['doh.test/*' => Http::response(['Status' => 0, 'Answer' => [
        ['name' => 'careers.airmee.com', 'type' => 5, 'TTL' => 60, 'data' => '3h8gokothc8iq.ext.teamtailor.com.'],
    ]])]);

    $answer = dohResolver()->resolve('careers.airmee.com', DnsRecordType::CNAME);

    expect($answer->status)->toBe(DnsStatus::Ok)
        ->and($answer->values)->toBe(['3h8gokothc8iq.ext.teamtailor.com']);
});
