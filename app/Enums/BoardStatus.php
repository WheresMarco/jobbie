<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a verified career site.
 */
enum BoardStatus: string
{
    case New = 'new';

    case Active = 'active';

    case Empty = 'empty';

    case Dead = 'dead';

    case Error = 'error';
}
