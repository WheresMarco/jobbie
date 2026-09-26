<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a candidate host is in the verification funnel.
 */
enum CandidateStage: string
{
    case New = 'new';

    case NoDns = 'no_dns';

    case DnsOk = 'dns_ok';

    case FingerprintMatch = 'fingerprint_match';

    case Verified = 'verified';

    case Rejected = 'rejected';
}
