<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('job_posting_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('city', 191)->nullable()->index();
            $table->char('country', 2)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_locations');
    }
};
