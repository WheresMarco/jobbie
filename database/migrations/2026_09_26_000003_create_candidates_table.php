<?php

declare(strict_types=1);

use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table): void {
            $table->string('host', 253)->primary();
            $table->enum('source', array_column(CandidateSourceType::cases(), 'value'));
            $table->string('source_ref', 2048)->nullable();
            $table->enum('strength', array_column(Strength::cases(), 'value'));
            $table->enum('stage', array_column(CandidateStage::cases(), 'value'))->default(CandidateStage::New->value);
            $table->string('reason')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('checked_at')->nullable();

            $table->index(['stage', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
