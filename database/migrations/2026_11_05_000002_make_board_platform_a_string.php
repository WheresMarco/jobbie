<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** Hosts of the platforms added after the enum was created. */
    private const array HOSTS = [
        'job-boards.greenhouse.io' => 'greenhouse',
        'jobs.lever.co' => 'lever',
        'jobs.eu.lever.co' => 'lever',
        'jobs.ashbyhq.com' => 'ashby',
    ];

    public function up(): void
    {
        // A MySQL enum only takes the platforms there were when it was
        // created, and INSERT IGNORE stores anything else as ''. A string
        // takes new platforms without a migration each time.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('boards', function (Blueprint $table): void {
                $table->string('platform', 32)->default('teamtailor')->change();
            });
        }

        foreach (self::HOSTS as $host => $platform) {
            DB::table('boards')->where('platform', '')->where('canonical_host', $host)->update(['platform' => $platform]);
        }
    }

    public function down(): void
    {
        // The enum is not restored: boards on newer platforms would not fit.
    }
};
