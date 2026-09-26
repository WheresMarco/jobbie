<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Runs only against MySQL (composer test:mysql). Guards the FULLTEXT setup
| that the job browser depends on.
*/

beforeEach(function (): void {
    Schema::create('fulltext_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->fullText('title');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('fulltext_probe');
});

it('runs on MySQL 8 with boolean mode FULLTEXT search', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    DB::table('fulltext_probe')->insert([
        ['title' => 'Backendutvecklare till Stockholm'],
        ['title' => 'Sjuksköterska i Göteborg'],
    ]);

    $titles = DB::table('fulltext_probe')
        ->whereFullText('title', '+backend*', ['mode' => 'boolean'])
        ->pluck('title');

    expect($titles->all())->toBe(['Backendutvecklare till Stockholm']);
})->group('mysql');
