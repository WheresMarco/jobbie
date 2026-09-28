<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // What a user is looking for. Postings are scored against it.
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('titles');
            $table->json('skills');
            $table->json('excluded');
            $table->json('cities');
            $table->json('remote');
            $table->timestamp('scored_at')->nullable();
            // Bumped whenever the scores are computed, so cached searches expire.
            $table->unsignedInteger('scores_version')->default(0);
            $table->timestamps();
        });

        // One row per user and active posting with a score above zero.
        Schema::create('job_posting_scores', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');

            $table->primary(['user_id', 'job_posting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_scores');
        Schema::dropIfExists('profiles');
    }
};
