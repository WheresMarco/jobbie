<?php

declare(strict_types=1);

use App\Enums\BoardKind;
use App\Enums\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // A board is identified by its platform and the account on that
        // platform. Several platforms serve many employers from one host, so
        // the host is no longer unique. Teamtailor boards keep their host as
        // the key (Board keeps the two in step).
        Schema::table('boards', function (Blueprint $table): void {
            $table->enum('platform', array_column(Platform::cases(), 'value'))->default(Platform::Teamtailor->value)->after('id');
            $table->string('external_key')->nullable()->after('platform');
        });

        DB::table('boards')->update(['external_key' => DB::raw('canonical_host')]);

        Schema::table('boards', function (Blueprint $table): void {
            $table->string('external_key')->nullable(false)->change();
            $table->unique(['platform', 'external_key']);
            $table->dropUnique(['canonical_host']);
            $table->index('canonical_host');
            // Custom domain or slug only means something on Teamtailor.
            $table->enum('kind', array_column(BoardKind::cases(), 'value'))->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('boards')->where('platform', '!=', Platform::Teamtailor->value)->delete();

        Schema::table('boards', function (Blueprint $table): void {
            $table->dropUnique(['platform', 'external_key']);
            $table->dropIndex(['canonical_host']);
            $table->unique('canonical_host');
            $table->dropColumn(['platform', 'external_key']);
        });
    }
};
