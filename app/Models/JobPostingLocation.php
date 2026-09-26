<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One city and country pair of a job posting, kept in its own indexed
 * table so location filters never have to search JSON.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property string|null $city
 * @property string|null $country
 */
#[Table(timestamps: false)]
#[Fillable(['job_posting_id', 'city', 'country'])]
class JobPostingLocation extends Model
{
    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
