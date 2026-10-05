<?php

declare(strict_types=1);

use App\Enums\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            // A string rather than an enum, so a new platform needs no migration.
            $table->string('platform', 32)->default(Platform::Teamtailor->value)->index()->after('canonical_host');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            $table->dropIndex(['platform']);
            $table->dropColumn('platform');
        });
    }
};
