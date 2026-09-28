<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Employers a user has marked as not relevant. The name is the one
        // the job list groups by: the posting's company, else the career
        // site's name or host.
        Schema::create('hidden_employers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employer', 191);
            $table->timestamps();

            $table->unique(['user_id', 'employer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_employers');
    }
};
