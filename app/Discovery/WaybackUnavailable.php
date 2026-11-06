<?php

declare(strict_types=1);

namespace App\Discovery;

use RuntimeException;

/**
 * The Wayback CDX API did not answer a request, even after retries.
 */
final class WaybackUnavailable extends RuntimeException
{
}
