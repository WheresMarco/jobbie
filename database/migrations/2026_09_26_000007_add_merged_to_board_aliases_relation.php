<?php

declare(strict_types=1);

use App\Enums\BoardAliasRelation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('board_aliases', function (Blueprint $table): void {
            $table->enum('relation', array_column(BoardAliasRelation::cases(), 'value'))->change();
        });
    }

    public function down(): void
    {
        Schema::table('board_aliases', function (Blueprint $table): void {
            $table->enum('relation', ['redirect', 'feed_home', 'seed', 'cname'])->change();
        });
    }
};
