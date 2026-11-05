<?php

declare(strict_types=1);

use App\Enums\BoardStatus;
use App\Enums\Platform;
use App\Models\Board;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Runs only against MySQL (composer test:mysql). Board discovery inserts
| with INSERT IGNORE, which made MySQL store platforms missing from an
| enum column as ''. The column is a string now.
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
