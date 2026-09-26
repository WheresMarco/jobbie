<?php

declare(strict_types=1);

namespace App\Net;

enum DnsStatus: string
{
    /** The name resolved and at least one record of the requested type exists. */
    case Ok = 'ok';

    /** The name does not exist. */
    case NxDomain = 'nxdomain';

    /** The name exists (or the resolver cannot tell) but has no records of the requested type. */
    case NoData = 'nodata';

    /** Timeout, SERVFAIL or a transport error. The answer says nothing about the name. */
    case Error = 'error';

    public function resolved(): bool
    {
        return $this === self::Ok;
    }
}
