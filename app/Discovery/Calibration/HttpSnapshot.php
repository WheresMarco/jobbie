<?php

declare(strict_types=1);

namespace App\Discovery\Calibration;

use JsonSerializable;

final readonly class HttpSnapshot implements JsonSerializable
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<string>  $redirects  URLs visited before the final response.
     * @param  array<string, mixed>  $details  Path specific findings, see Calibrator.
     */
    public function __construct(
        public string $url,
        public ?int $status,
        public ?string $finalUrl,
        public array $headers,
        public array $redirects,
        public array $details = [],
        public ?string $error = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'status' => $this->status,
            'final_url' => $this->finalUrl,
            'redirects' => $this->redirects,
            'headers' => $this->headers,
            'details' => $this->details,
            'error' => $this->error,
        ];
    }
}
