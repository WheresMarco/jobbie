<?php

declare(strict_types=1);

use App\Enums\BoardAliasRelation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('board_aliases', function (Blueprint $table): void {
            $table->string('host', 253)->primary();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->enum('relation', array_column(BoardAliasRelation::cases(), 'value'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_aliases');
    }
};
