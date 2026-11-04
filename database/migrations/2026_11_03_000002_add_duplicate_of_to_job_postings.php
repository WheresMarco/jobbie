<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // A Platsbanken posting for the same ad as a posting read from the
        // employer's own system points at that posting and is left out of
        // the job list. Set by PlatsbankenMatcher.
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->foreignId('duplicate_of_id')->nullable()->constrained('job_postings')->nullOnDelete();
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex('job_postings_search_index');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->index(
                ['active', 'duplicate_of_id', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company', 'in_platsbanken'],
                'job_postings_search_index',
            );
        });

        // Platsbanken ads become postings, which needs the full ads. The
        // next JobStream sync starts over from a snapshot.
        DB::table('sync_cursors')->where('name', 'jobstream')->delete();
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex('job_postings_search_index');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->index(
                ['active', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company', 'in_platsbanken'],
                'job_postings_search_index',
            );
        });
    }
};
