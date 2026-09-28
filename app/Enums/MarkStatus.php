<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a user is with a posting.
 */
enum MarkStatus: string
{
    /** Picked out to work on. */
    case Saved = 'saved';

    case Applied = 'applied';

    /** Not interested; hidden from the job list. */
    case Rejected = 'rejected';
}
