<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Where incremental syncs (JobStream) left off.
     */
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table): void {
            $table->string('name', 50)->primary();
            $table->timestamp('position');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
