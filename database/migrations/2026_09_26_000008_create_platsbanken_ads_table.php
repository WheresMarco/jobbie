<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('platsbanken_ads', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('apply_host', 253)->nullable()->index();
            $table->string('apply_url', 2048)->nullable();
            $table->string('org_nr', 20)->nullable()->index();
            $table->string('employer_name')->nullable();
            $table->string('title_normalized', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('removed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platsbanken_ads');
    }
};
