<?php

declare(strict_types=1);

namespace App\Discovery\Calibration;

enum CalibrationSection: string
{
    /** CNAME chain plus A and AAAA records of the host. */
    case Dns = 'dns';

    /** TXT records on the root domain. */
    case Txt = 'txt';

    /** Certificate issuer and SAN list. */
    case Tls = 'tls';

    /** Response headers for / and /jobs.json, and asset hosts in the HTML. */
    case Http = 'http';
}
