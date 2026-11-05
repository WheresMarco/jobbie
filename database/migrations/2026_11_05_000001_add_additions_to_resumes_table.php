<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table): void {
            // Roles and skills the user added to the profile, and those of
            // Claude's they removed. Kept apart from it, so a new CV or new
            // preferences leave them be.
            $table->json('additions')->nullable()->after('profile');
            $table->json('removals')->nullable()->after('additions');
            // The profile changed since the matches were scored.
            $table->boolean('edited')->default(false)->after('removals');
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table): void {
            $table->dropColumn(['additions', 'removals', 'edited']);
        });
    }
};
