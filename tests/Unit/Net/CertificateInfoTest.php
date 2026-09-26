<?php

declare(strict_types=1);

use App\Net\CertificateInfo;

it('reads subject, issuer, SAN list and validity from openssl_x509_parse output', function (): void {
    $info = CertificateInfo::fromOpenSsl([
        'subject' => ['CN' => 'career.example.se'],
        'issuer' => ['C' => 'US', 'O' => "Let's Encrypt", 'CN' => 'R11'],
        'validFrom_time_t' => 1_780_000_000,
        'validTo_time_t' => 1_787_776_000,
        'extensions' => ['subjectAltName' => 'DNS:career.example.se, DNS:Jobs.Example.se, IP Address:127.0.0.1'],
    ]);

    expect($info->subject)->toBe('career.example.se')
        ->and($info->issuerOrganization)->toBe("Let's Encrypt")
        ->and($info->issuerCommonName)->toBe('R11')
        ->and($info->subjectAltNames)->toBe(['career.example.se', 'jobs.example.se'])
        ->and($info->jsonSerialize()['valid_to'])->toStartWith('2026-');
});

it('tolerates missing fields and multi valued names', function (): void {
    $info = CertificateInfo::fromOpenSsl(['subject' => ['CN' => ['a.example', 'b.example']]]);

    expect($info->subject)->toBe('b.example')
        ->and($info->issuerOrganization)->toBeNull()
        ->and($info->subjectAltNames)->toBe([]);
});
