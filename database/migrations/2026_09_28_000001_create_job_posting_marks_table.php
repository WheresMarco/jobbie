<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One row per user and posting the user has seen or marked.
        Schema::create('job_posting_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'job_posting_id']);
            $table->index(['user_id', 'status', 'status_changed_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            // Postings first seen up to this moment count as seen.
            $table->timestamp('postings_seen_until')->nullable();
            // Bumped whenever the user's marks change, so cached searches expire.
            $table->unsignedInteger('marks_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['postings_seen_until', 'marks_version']);
        });

        Schema::dropIfExists('job_posting_marks');
    }
};
