<?php

declare(strict_types=1);

use App\Enums\CandidateSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->string('base_domain', 253)->primary();
            $table->enum('source', array_column(CandidateSourceType::cases(), 'value'));
            $table->string('org_nr', 20)->nullable()->index();
            $table->string('company_name')->nullable();
            $table->boolean('txt_match')->nullable();
            $table->timestamp('probed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
