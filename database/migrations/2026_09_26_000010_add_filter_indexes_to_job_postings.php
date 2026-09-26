<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Covering index for the job browser's single scan (JobSearch): every
     * column it reads, newest first, without touching the wide rows that
     * hold the descriptions.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->index(
                ['active', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company'],
                'job_postings_search_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex('job_postings_search_index');
        });
    }
};
