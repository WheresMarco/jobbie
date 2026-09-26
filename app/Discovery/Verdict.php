<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\CandidateStage;

/**
 * Outcome of verifying one candidate host.
 */
final readonly class Verdict
{
    /**
     * @param  list<string>  $cnameChain
     * @param  string|null  $reason  Machine readable, for example "no_fingerprint" or "http_404".
     */
    public function __construct(
        public string $host,
        public CandidateStage $stage,
        public ?string $reason = null,
        public array $cnameChain = [],
        public ?string $finalHost = null,
        public ?string $homePageUrl = null,
        public ?string $feedTitle = null,
    ) {
    }

    public function verified(): bool
    {
        return $this->stage === CandidateStage::Verified;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
