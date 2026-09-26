<?php

declare(strict_types=1);

use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_host', 253)->unique();
            $table->string('tt_slug', 100)->nullable()->index();
            $table->enum('kind', array_column(BoardKind::cases(), 'value'));
            $table->enum('status', array_column(BoardStatus::cases(), 'value'))->default(BoardStatus::New->value)->index();
            $table->string('name')->nullable();
            $table->string('home_url', 2048)->nullable();
            $table->unsignedInteger('job_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
