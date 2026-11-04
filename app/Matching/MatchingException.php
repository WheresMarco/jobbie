<?php

declare(strict_types=1);

namespace App\Matching;

use RuntimeException;

final class MatchingException extends RuntimeException
{
    public static function notACv(): self
    {
        return new self('The document is not a CV.', 1);
    }

    public function isNotACv(): bool
    {
        return $this->getCode() === 1;
    }
}
