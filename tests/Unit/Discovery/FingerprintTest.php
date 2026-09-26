<?php

declare(strict_types=1);

use App\Discovery\Fingerprint;
use App\Net\DnsAnswer;

/**
 * @return array<string, array{0: string, 1: string|null}>
 */
function calibrationCnameExpectations(): array
{
    return [
        'shared entry' => ['career.kry.se', 'ext'],
        'shared entry, .ai' => ['jobs.timefold.ai', 'ext'],
        'shared entry, TXT without match' => ['jobb.nordicwellness.se', 'ext'],
        'unique entry' => ['career.clasohlson.com', 'ext_unique'],
        'unique entry with TXT' => ['careers.airmee.com', 'ext_unique'],
        'slug host behind wildcard' => ['kry.teamtailor.com', null],
        'WP Engine' => ['careers.einride.tech', null],
        'SuccessFactors' => ['jobs.tele2.com', null],
    ];
}

function calibrationAnswer(string $host): DnsAnswer
{
    $data = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/calibration/{$host}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return DnsAnswer::fromArray($data['dns']['a']);
}

it('matches CNAME chains from the calibration fixtures', function (string $host, ?string $expected): void {
    expect(Fingerprint::matchCname(calibrationAnswer($host)->cnameChain))->toBe($expected);
})->with(calibrationCnameExpectations());

it('has an expectation for every calibration fixture', function (): void {
    $hosts = array_map(
        static fn (string $file): string => basename($file, '.json'),
        glob(dirname(__DIR__, 2).'/Fixtures/calibration/*.json') ?: [],
    );

    expect(array_column(calibrationCnameExpectations(), 0))->toEqualCanonicalizing($hosts);
});

it('matches addresses of every Teamtailor fixture and none of the others', function (string $host, ?string $expected): void {
    $answer = calibrationAnswer($host);

    expect(Fingerprint::matchAddress($answer->values) !== null)->toBe($expected !== null);
})->with(calibrationCnameExpectations());

it('matches names in the chain case insensitively and with a trailing dot', function (): void {
    expect(Fingerprint::matchCname(['EXT.Teamtailor.com.']))->toBe('ext')
        ->and(Fingerprint::matchCname(['cdn.example.net', 'teamtailor.map.fastly.net']))->toBe('fastly_map')
        ->and(Fingerprint::matchCname(['ext.teamtailor.com.evil.example']))->toBeNull()
        ->and(Fingerprint::matchCname([]))->toBeNull();
});

it('recognises the verification TXT record', function (): void {
    expect(Fingerprint::matchTxt(['v=spf1 -all', 'teamtailor=b6ed34c038c2d280d171164a301c49cd']))->toBe('verification')
        ->and(Fingerprint::matchTxt(['aa1746a1074b903df3de93fe2edf3240']))->toBeNull()
        ->and(Fingerprint::matchTxt(['teamtailor=not-hex']))->toBeNull();
});

it('checks addresses against IPv4 and IPv6 ranges', function (): void {
    expect(Fingerprint::matchAddress(['151.101.65.242']))->toBe('151.101.65.242/32')
        ->and(Fingerprint::matchAddress(['2a04:4e42:0400:0000:0000:0000:0000:0498']))->toBe('2a04:4e42:400::498/128')
        ->and(Fingerprint::matchAddress(['151.101.65.243', '2a04:4e42::499']))->toBeNull()
        ->and(Fingerprint::matchAddress(['not an address']))->toBeNull();
});

it('finds HTML markers in the body or in headers', function (): void {
    expect(Fingerprint::matchHtml('<script src="https://scripts.teamtailor-cdn.com/a.js"></script>'))->toBe('cdn')
        ->and(Fingerprint::matchHtml('', ['Link' => ['<https://assets.teamtailor-cdn.com/x.css>; rel=preload']]))->toBe('cdn')
        ->and(Fingerprint::matchHtml('<html>WordPress</html>'))->toBeNull();
});

it('tells slug hosts from custom domains', function (): void {
    expect(Fingerprint::isSlugHost('kry.teamtailor.com'))->toBeTrue()
        ->and(Fingerprint::isSlugHost('3h8gokothc8iq.ext.teamtailor.com'))->toBeFalse()
        ->and(Fingerprint::isSlugHost('career.kry.se'))->toBeFalse()
        ->and(Fingerprint::isSlugHost('teamtailor.com'))->toBeFalse();
});
