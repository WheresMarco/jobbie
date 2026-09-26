<?php

declare(strict_types=1);

namespace App\Net;

use App\Net\Contracts\CertificateFetcher;
use RuntimeException;

final readonly class StreamCertificateFetcher implements CertificateFetcher
{
    public function __construct(private int $timeout)
    {
    }

    public function fetch(string $host): CertificateInfo
    {
        // Peer verification is off on purpose: the goal is to record whatever
        // certificate the host presents, including expired or mismatched ones.
        // No application data is sent over the connection.
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException("TLS connection to {$host} failed: {$errorMessage} ({$errorCode})");
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate instanceof \OpenSSLCertificate ? openssl_x509_parse($certificate) : false;

        if (! is_array($parsed)) {
            throw new RuntimeException("No certificate captured from {$host}");
        }

        return CertificateInfo::fromOpenSsl($parsed);
    }
}
