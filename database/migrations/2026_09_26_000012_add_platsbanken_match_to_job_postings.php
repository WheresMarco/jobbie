<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Phase 9: which Platsbanken ad a posting also appears as. The flag is
     * part of the job browser's covering index so its filter stays cheap.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->boolean('in_platsbanken')->default(false);
            $table->string('platsbanken_ad_id', 32)->nullable()->index();
            $table->string('platsbanken_match', 20)->nullable();
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex('job_postings_search_index');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->index(
                ['active', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company', 'in_platsbanken'],
                'job_postings_search_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex('job_postings_search_index');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropColumn(['in_platsbanken', 'platsbanken_ad_id', 'platsbanken_match']);
            $table->index(
                ['active', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company'],
                'job_postings_search_index',
            );
        });
    }
};
