<?php

declare(strict_types=1);

use App\Enums\RemoteStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('feed_id', 100);
            $table->string('title', 500);
            $table->string('url', 2048);
            $table->string('company')->nullable();
            $table->json('cities');
            $table->json('countries');
            $table->enum('remote', array_column(RemoteStatus::cases(), 'value'))->nullable();
            $table->string('department')->nullable();
            $table->string('employment_type', 100)->nullable();
            $table->string('salary_text')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['board_id', 'feed_id']);
            $table->index(['active', 'posted_at']);

            // SQLite (used by most tests) has no FULLTEXT indexes.
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['title', 'company', 'description']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
