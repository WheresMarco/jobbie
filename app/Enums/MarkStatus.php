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

    /** Worked on and turned down; hidden from the job list. */
    case Rejected = 'rejected';

    /** Not of interest at all; hidden from the job list. */
    case NotRelevant = 'not_relevant';

    /**
     * Statuses that hide a posting from the job list.
     *
     * @return list<self>
     */
    public static function hiding(): array
    {
        return [self::Rejected, self::NotRelevant];
    }

    public function hides(): bool
    {
        return in_array($this, self::hiding(), true);
    }
}
