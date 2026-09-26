<?php

declare(strict_types=1);

use App\Net\DomainName;

beforeEach(function (): void {
    $this->domains = app(DomainName::class);
});

it('normalises hosts', function (string $input, ?string $expected): void {
    expect($this->domains->normalize($input))->toBe($expected);
})->with([
    'lower case and trailing dot' => ['Career.Kry.SE.', 'career.kry.se'],
    'URL with path and query' => ['https://Jobs.Timefold.ai/jobs?page=2', 'jobs.timefold.ai'],
    'port and credentials' => ['user@jobb.example.se:8443/x', 'jobb.example.se'],
    'Swedish letters to Punycode' => ['karriär.företag.se', 'xn--karrir-fua.xn--fretag-wxa.se'],
    'upper case Swedish letters' => ['KARRIÄR.Åhléns.se', 'xn--karrir-fua.xn--hlns-poa0a.se'],
    'already Punycode' => ['xn--karrir-fua.example.se', 'xn--karrir-fua.example.se'],
    'surrounding whitespace' => ["  jobs.example.se \n", 'jobs.example.se'],
]);

it('rejects invalid hosts', function (string $input): void {
    expect($this->domains->normalize($input))->toBeNull();
})->with([
    'empty' => [''],
    'single label' => ['localhost'],
    'IPv4' => ['127.0.0.1'],
    'IPv6 URL' => ['https://[2a04:4e42::498]/'],
    'leading hyphen' => ['-jobs.example.se'],
    'trailing hyphen' => ['jobs-.example.se'],
    'empty label' => ['jobs..example.se'],
    'space' => ['jobs example.se'],
    'underscore' => ['_dmarc.example.se'],
    'label over 63 characters' => [str_repeat('a', 64).'.se'],
    'numeric TLD' => ['example.123'],
    'unencodable' => ["jobb.exa\u{0000}mple.se"],
]);

it('finds the registrable domain with the Public Suffix List', function (string $input, ?string $expected): void {
    expect($this->domains->registrable($input))->toBe($expected);
})->with([
    'Swedish subdomain' => ['career.kry.se', 'kry.se'],
    'deep subdomain' => ['a.b.jobs.example.se', 'example.se'],
    'co.uk' => ['careers.foretag.co.uk', 'foretag.co.uk'],
    'com.au' => ['jobs.example.com.au', 'example.com.au'],
    'Swedish letters' => ['karriär.företag.se', 'xn--fretag-wxa.se'],
    'registrable domain itself' => ['kry.se', 'kry.se'],
    'public suffix only' => ['co.uk', null],
    'private suffix' => ['jobs.example.github.io', 'example.github.io'],
    'unknown TLD' => ['jobs.example.invalidtld', null],
    'invalid host' => ['not a host', null],
]);

it('converts back to Unicode for display', function (): void {
    expect($this->domains->toUnicode('xn--karrir-fua.xn--fretag-wxa.se'))->toBe('karriär.företag.se');
});
