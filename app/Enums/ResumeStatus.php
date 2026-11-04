<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a CV is in matching.
 */
enum ResumeStatus: string
{
    /** Claude is reading the CV. */
    case Analyzing = 'analyzing';

    /** Claude is scoring candidate postings. */
    case Matching = 'matching';

    case Ready = 'ready';

    case Failed = 'failed';

    public function busy(): bool
    {
        return $this === self::Analyzing || $this === self::Matching;
    }
}
