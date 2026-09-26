<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Indexes for the job browser's filters and facets.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->index(['active', 'first_seen_at']);
            $table->index(['active', 'remote']);
            $table->index(['active', 'department']);
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex(['active', 'first_seen_at']);
            $table->dropIndex(['active', 'remote']);
            $table->dropIndex(['active', 'department']);
        });
    }
};
