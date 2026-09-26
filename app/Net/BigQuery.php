<?php

declare(strict_types=1);

namespace App\Net;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal BigQuery REST client: runs one query with a service account from
 * a JSON key file and pages through the rows. Written by hand rather than
 * with google/cloud-bigquery, which would pull in a large dependency tree
 * for a single optional source.
 */
final readonly class BigQuery
{
    private const string SCOPE = 'https://www.googleapis.com/auth/bigquery';

    private const string API = 'https://bigquery.googleapis.com/bigquery/v2';

    public function __construct(
        private ?string $project,
        private ?string $credentialsPath,
        private int $timeout,
    ) {
    }

    public function configured(): bool
    {
        return $this->project !== null && $this->project !== ''
            && $this->credentialsPath !== null && is_file($this->credentialsPath);
    }

    /**
     * Run a standard SQL query with named parameters and return the first
     * column of every row.
     *
     * @param  array<string, array{string, string}>  $parameters  name => [type, value]
     * @return list<string>
     *
     * @throws RuntimeException
     */
    public function column(string $sql, array $parameters, int $maxBytesBilled): array
    {
        $token = $this->accessToken();
        $request = Http::withToken($token)->timeout($this->timeout)->acceptJson();

        $response = $request->post(self::API."/projects/{$this->project}/queries", [
            'query' => $sql,
            'useLegacySql' => false,
            'parameterMode' => 'NAMED',
            'queryParameters' => array_map(
                static fn (string $name, array $p): array => ['name' => $name, 'parameterType' => ['type' => $p[0]], 'parameterValue' => ['value' => $p[1]]],
                array_keys($parameters),
                $parameters,
            ),
            'maximumBytesBilled' => (string) $maxBytesBilled,
            'timeoutMs' => $this->timeout * 1000,
            'maxResults' => 10_000,
        ])->throw()->json();

        $values = [];
        while (true) {
            if (! is_array($response)) {
                throw new RuntimeException('Unexpected BigQuery response');
            }

            if (($response['jobComplete'] ?? false) === true) {
                foreach (is_array($response['rows'] ?? null) ? $response['rows'] : [] as $row) {
                    $value = is_array($row) ? ($row['f'][0]['v'] ?? null) : null;
                    if (is_string($value)) {
                        $values[] = $value;
                    }
                }
            }

            $job = is_array($response['jobReference'] ?? null) ? $response['jobReference'] : [];
            $pageToken = $response['pageToken'] ?? null;
            if (($response['jobComplete'] ?? false) === true && ! is_string($pageToken)) {
                return $values;
            }

            $response = $request->get(self::API."/projects/{$this->project}/queries/".($job['jobId'] ?? ''), array_filter([
                'location' => $job['location'] ?? null,
                'pageToken' => is_string($pageToken) ? $pageToken : null,
                'maxResults' => 10_000,
                'timeoutMs' => $this->timeout * 1000,
            ]))->throw()->json();
        }
    }

    /**
     * OAuth access token through the JWT bearer flow for service accounts.
     */
    private function accessToken(): string
    {
        $credentials = json_decode((string) file_get_contents((string) $this->credentialsPath), true);
        if (! is_array($credentials) || ! is_string($credentials['client_email'] ?? null) || ! is_string($credentials['private_key'] ?? null)) {
            throw new RuntimeException('The credentials file is not a service account key');
        }

        $tokenUri = is_string($credentials['token_uri'] ?? null) ? $credentials['token_uri'] : 'https://oauth2.googleapis.com/token';
        $now = time();
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the token request');
        }
        $segments[] = self::base64Url($signature);

        $token = Http::asForm()->timeout($this->timeout)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments),
        ])->throw()->json('access_token');

        if (! is_string($token)) {
            throw new RuntimeException('No access token in the response');
        }

        return $token;
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
