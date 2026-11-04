<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How well a posting fits a CV, as Claude scored it.
 *
 * @property int $id
 * @property int $resume_id
 * @property int $job_posting_id
 * @property int $score 0 to 100.
 * @property string $reason One sentence in Swedish.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['resume_id', 'job_posting_id', 'score', 'reason'])]
class ResumeMatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Resume, $this>
     */
    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
