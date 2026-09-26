<?php

declare(strict_types=1);

use App\Net\CertificateInfo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->output = sys_get_temp_dir().'/radar-calibration-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->output);
});

/**
 * @return array<string, mixed>
 */
function calibrationOutput(string $directory, string $host): array
{
    return json_decode((string) file_get_contents("{$directory}/{$host}.json"), true, flags: JSON_THROW_ON_ERROR);
}

it('records DNS, TXT, TLS and HTTP data for a host', function (): void {
    Http::fake([
        'careers.airmee.com/jobs.json' => Http::response($this->fixture('http/jobs.json'), 200, ['Content-Type' => 'application/json']),
        'careers.airmee.com/' => Http::response($this->fixture('http/home.html'), 200, ['Content-Type' => 'text/html', 'Server' => 'nginx']),
    ]);
    $this->certificates->add('careers.airmee.com', new CertificateInfo(
        'careers.airmee.com',
        "Let's Encrypt",
        'R11',
        ['careers.airmee.com'],
        1_780_000_000,
        1_787_776_000,
    ));

    $this->artisan('radar:calibrate', ['hosts' => ['careers.airmee.com'], '--output' => $this->output])
        ->assertSuccessful();

    $result = calibrationOutput($this->output, 'careers.airmee.com');

    expect($result['root_domain'])->toBe('airmee.com')
        ->and($result['dns']['a']['cname_chain'])->toBe(['3h8gokothc8iq.ext.teamtailor.com', 'teamtailor.map.fastly.net'])
        ->and($result['matches'])->toBe(['cname' => 'ext_unique', 'address' => '151.101.1.242/32', 'txt' => 'verification'])
        ->and($result['tls']['issuer_organization'])->toBe("Let's Encrypt")
        ->and($result['http'][0]['status'])->toBe(200)
        ->and($result['http'][0]['headers']['Server'])->toBe(['nginx'])
        ->and($result['http'][0]['details']['html_marker'])->toBe('cdn')
        ->and($result['http'][0]['details']['asset_hosts'])->toContain('assets.teamtailor-cdn.com')
        ->and($result['http'][1]['details'])->toMatchArray([
            'is_json' => true,
            'version' => 'https://jsonfeed.org/version/1.1',
            'home_page_url' => 'https://career.example.se/',
            'item_count' => 1,
            'has_jobposting' => true,
        ])
        ->and($result['errors'])->toBe([]);
});

it('keeps going when TLS and HTTP fail', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    $this->artisan('radar:calibrate', ['hosts' => ['career.kry.se'], '--output' => $this->output])
        ->assertSuccessful();

    $result = calibrationOutput($this->output, 'career.kry.se');

    expect($result['matches']['cname'])->toBe('ext')
        ->and($result['errors'])->toHaveKey('tls')
        ->and($result['http'][0]['status'])->toBeNull()
        ->and($result['http'][0]['error'])->not->toBeNull()
        ->and($result['http'][1]['error'])->not->toBeNull();
});

it('skips sections with --without', function (): void {
    $this->artisan('radar:calibrate', [
        'hosts' => ['career.kry.se', 'careers.einride.tech'],
        '--without' => ['txt,tls', 'http'],
        '--output' => $this->output,
    ])->assertSuccessful();

    $result = calibrationOutput($this->output, 'careers.einride.tech');

    expect($result['dns']['root_txt'])->toBeNull()
        ->and($result['tls'])->toBeNull()
        ->and($result['http'])->toBe([])
        ->and($result['matches']['cname'])->toBeNull()
        ->and($this->dns->queries)->not->toContain('TXT kry.se');

    Http::assertNothingSent();
});

it('rejects unknown sections', function (): void {
    $this->artisan('radar:calibrate', ['hosts' => ['career.kry.se'], '--without' => ['smtp'], '--output' => $this->output])
        ->assertExitCode(2);

    expect(File::isDirectory($this->output))->toBeFalse();
});
