<?php

declare(strict_types=1);

namespace App\Enums;

enum BoardKind: string
{
    case TtSubdomain = 'tt_subdomain';

    case Custom = 'custom';
}
