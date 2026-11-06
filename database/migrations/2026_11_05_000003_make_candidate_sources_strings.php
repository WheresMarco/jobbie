<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Like boards.platform: a MySQL enum only takes the sources there
        // were when it was created, and INSERT IGNORE stores others as ''.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['candidates', 'domains'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('source', 32)->change();
            });
        }
    }

    public function down(): void
    {
        // The enums are not restored: rows from newer sources would not fit.
    }
};
