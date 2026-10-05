<?php

declare(strict_types=1);

use App\Scraping\JobPostingData;
use App\Scraping\PostingStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Platsbanken ads become postings under one board (platform platsbanken).
| That board is not a Teamtailor site, so kind becomes optional. Postings
| get the employer's organisation number, "duplicate" marks Platsbanken
| ads that an employer's own posting already covers, and "in_sweden" marks
| postings located in Sweden or without any location. Only postings with
| both flags right are listed. The search index swaps in_platsbanken (no
| longer filtered on) for the two flags.
*/
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable()->change();
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->string('org_nr', 20)->nullable();
            $table->boolean('duplicate')->default(false);
            $table->boolean('in_sweden')->default(true);
            $table->dropIndex('job_postings_search_index');
        });

        foreach (DB::table('job_postings')->select('id', 'countries')->lazyById(2000) as $posting) {
            if (! PostingStore::inSweden(JobPostingData::decodeList((string) $posting->countries))) {
                DB::table('job_postings')->where('id', $posting->id)->update(['in_sweden' => false]);
            }
        }

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->index(
                ['active', 'duplicate', 'in_sweden', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company'],
                'job_postings_search_index',
            );
        });
    }

    /**
     * boards.kind stays a nullable string: the Platsbanken board has none.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex('job_postings_search_index');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropColumn(['org_nr', 'duplicate', 'in_sweden']);
            $table->index(
                ['active', 'posted_at', 'first_seen_at', 'board_id', 'remote', 'department', 'company', 'in_platsbanken'],
                'job_postings_search_index',
            );
        });
    }
};
