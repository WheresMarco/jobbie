<?php

declare(strict_types=1);

namespace App\Scraping;

use RuntimeException;

final class FeedException extends RuntimeException
{
    /**
     * @param  bool  $dead  True when the board is gone or does not serve job postings (404, 410, HTML).
     */
    public function __construct(string $message, public readonly bool $dead)
    {
        parent::__construct($message);
    }

    public static function dead(string $message): self
    {
        return new self($message, true);
    }

    public static function failed(string $message): self
    {
        return new self($message, false);
    }
}
