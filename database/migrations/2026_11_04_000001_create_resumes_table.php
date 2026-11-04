<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One CV per user. The file is deleted once Claude has read it;
        // only the profile it extracted is kept.
        Schema::create('resumes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('status', 20);
            $table->json('profile')->nullable();
            $table->text('preferences')->nullable();
            $table->text('error')->nullable();
            // Bumped on every upload, change of preferences and new search,
            // so queued jobs from an earlier run can tell they are stale.
            $table->unsignedInteger('run')->default(0);
            $table->unsignedInteger('to_score')->default(0);
            $table->unsignedInteger('scored')->default(0);
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('resume_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resume_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('reason', 500);
            $table->timestamps();

            $table->unique(['resume_id', 'job_posting_id']);
            $table->index(['resume_id', 'score']);
        });

        // Candidate search weighs role words in the title higher than the
        // same words in the description.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('job_postings', function (Blueprint $table): void {
                $table->fullText(['title'], 'job_postings_title_fulltext');
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('job_postings', function (Blueprint $table): void {
                $table->dropFullText('job_postings_title_fulltext');
            });
        }

        Schema::dropIfExists('resume_matches');
        Schema::dropIfExists('resumes');
    }
};
