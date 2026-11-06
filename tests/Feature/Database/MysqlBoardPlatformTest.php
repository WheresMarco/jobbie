<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Platform;
use App\Models\Board;
use App\Models\Candidate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Runs only against MySQL (composer test:mysql). Board discovery inserts
| with INSERT IGNORE, which made MySQL store platforms missing from an
| enum column as ''. The columns are strings now.
*/

uses(DatabaseMigrations::class);

it('stores every platform, as discovery inserts them', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    foreach (Platform::cases() as $platform) {
        Board::query()->insertOrIgnore([
            'platform' => $platform->value,
            'external_key' => "key-{$platform->value}",
            'canonical_host' => "{$platform->value}.example",
            'status' => BoardStatus::New->value,
            'job_count' => 0,
            'first_seen_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    expect(Board::query()->pluck('platform')->map->value->sort()->values()->all())
        ->toBe(collect(Platform::cases())->map->value->sort()->values()->all());
})->group('mysql');

it('stores every candidate source, as collection inserts them', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    foreach (CandidateSourceType::cases() as $source) {
        Candidate::query()->insertOrIgnore([
            'host' => "{$source->value}.example",
            'source' => $source->value,
            'strength' => 'strong',
            'stage' => CandidateStage::New->value,
            'discovered_at' => Carbon::now(),
        ]);
    }

    expect(Candidate::query()->pluck('source')->map->value->sort()->values()->all())
        ->toBe(collect(CandidateSourceType::cases())->map->value->sort()->values()->all());
})->group('mysql');
