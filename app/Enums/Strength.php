<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Strong candidates are checked over HTTP even without a DNS fingerprint match.
 */
enum Strength: string
{
    case Strong = 'strong';

    case Weak = 'weak';
}
