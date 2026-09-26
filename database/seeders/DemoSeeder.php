<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BoardKind;
use App\Enums\BoardStatus;
use App\Enums\RemoteStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fake boards and postings for trying out the job browser and for the
 * performance test: php artisan db:seed --class=DemoSeeder
 * Deterministic, and fast enough for 50 000 postings.
 */
class DemoSeeder extends Seeder
{
    private const array CITIES = [
        ['Stockholm', 'SE'], ['Göteborg', 'SE'], ['Malmö', 'SE'], ['Uppsala', 'SE'], ['Linköping', 'SE'],
        ['Örebro', 'SE'], ['Västerås', 'SE'], ['Helsingborg', 'SE'], ['Umeå', 'SE'], ['Luleå', 'SE'],
        ['Jönköping', 'SE'], ['Lund', 'SE'], ['Sundsvall', 'SE'], ['Gävle', 'SE'], ['Karlstad', 'SE'],
        ['Oslo', 'NO'], ['Bergen', 'NO'], ['København', 'DK'], ['Aarhus', 'DK'], ['Helsinki', 'FI'],
        ['London', 'GB'], ['Berlin', 'DE'],
    ];

    private const array TITLES = [
        'Backendutvecklare', 'Frontendutvecklare', 'Systemutvecklare', 'Sjuksköterska', 'Undersköterska', 'Läkare',
        'Psykolog', 'Lagerarbetare', 'Truckförare', 'Säljare', 'Kundtjänstmedarbetare', 'Ekonomiassistent',
        'Redovisningskonsult', 'Projektledare', 'Produktägare', 'UX-designer', 'Dataanalytiker', 'Butikssäljare',
        'Restaurangbiträde', 'Kock', 'Elektriker', 'Snickare', 'Lärare', 'Förskollärare', 'HR-specialist',
    ];

    /** Common words first: descriptions draw words with a skewed (Zipf like) distribution. */
    private const array WORDS = [
        'erfarenhet', 'team', 'kunder', 'ansvar', 'utveckling', 'kollegor', 'arbetstider', 'förmåner', 'kvalitet',
        'service', 'friskvård', 'tillväxt', 'flexibla', 'hållbarhet', 'digitala', 'lösningar', 'ledarskap',
        'trygghet', 'innovation', 'ekonomi', 'försäljning', 'logistik', 'lager', 'vård', 'patienter', 'Laravel',
        'React', 'körkort', 'truckkort', 'legitimation', 'semester', 'pension', 'kollektivavtal', 'provanställning',
        'heltid', 'deltid', 'skiftarbete', 'helger', 'omsorg', 'skola', 'elever', 'barn', 'bygg', 'installation',
        'underhåll', 'produktion', 'maskiner', 'kvalitetssäkring', 'redovisning', 'budget', 'analys', 'rapporter',
        'Excel', 'SAP', 'Python', 'Java', 'Kotlin', 'Swift', 'Azure', 'AWS', 'Kubernetes', 'säkerhet', 'molnet',
        'API', 'databaser', 'design', 'Figma', 'användare', 'kommunikation', 'marknadsföring', 'kampanjer',
        'rekrytering', 'onboarding', 'arbetsmiljö', 'restaurang', 'kök', 'gäster', 'butik', 'kassa', 'inköp',
    ];

    private const array DEPARTMENTS = ['Teknik', 'Vård', 'Sälj', 'Ekonomi', 'Lager', 'Kundservice', 'HR', 'Marknad', 'Produkt'];

    private const array REMOTE = [RemoteStatus::Fully, RemoteStatus::Hybrid, RemoteStatus::Onsite, RemoteStatus::None, null, null];

    public int $postings = 5000;

    public int $boards = 500;

    public function run(): void
    {
        mt_srand(4711);
        $now = Carbon::now();

        $boardRows = [];
        for ($b = 1; $b <= $this->boards; $b++) {
            $custom = $b % 3 !== 0;
            $boardRows[] = [
                'canonical_host' => $custom ? "karriar.foretag{$b}.se" : "foretag{$b}.teamtailor.com",
                'tt_slug' => $custom ? null : "foretag{$b}",
                'kind' => ($custom ? BoardKind::Custom : BoardKind::TtSubdomain)->value,
                'status' => BoardStatus::Active->value,
                'name' => "Företag {$b} AB",
                'home_url' => null,
                'job_count' => 0,
                'first_seen_at' => $now,
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($boardRows, 500) as $chunk) {
            DB::table('boards')->insert($chunk);
        }
        $boardIds = DB::table('boards')->orderBy('id')->pluck('id')->all();

        $firstId = (int) DB::table('job_postings')->max('id') + 1;
        $postings = $locations = [];

        for ($i = 0; $i < $this->postings; $i++) {
            // Skewed: a few boards (staffing agencies) have many postings.
            $boardIndex = (int) floor(count($boardIds) * (mt_rand() / mt_getrandmax()) ** 3);
            $boardId = $boardIds[min($boardIndex, count($boardIds) - 1)];
            $title = self::TITLES[mt_rand(0, count(self::TITLES) - 1)];
            $remote = self::REMOTE[mt_rand(0, count(self::REMOTE) - 1)];
            // Mostly Stockholm, then the rest evenly.
            $places = [mt_rand(0, 2) === 0 ? self::CITIES[0] : self::CITIES[mt_rand(0, count(self::CITIES) - 1)]];
            if (mt_rand(0, 9) === 0) {
                $places[] = self::CITIES[mt_rand(0, count(self::CITIES) - 1)];
            }
            $places = array_values(array_unique($places, SORT_REGULAR));

            $words = [];
            for ($w = 0; $w < 60; $w++) {
                // Skewed: the first words are common, the rest rare, plus some unique noise.
                $words[] = mt_rand(0, 3) === 0
                    ? 'ord'.mt_rand(1, 20_000)
                    : self::WORDS[(int) floor(count(self::WORDS) * (mt_rand() / mt_getrandmax()) ** 3)];
            }

            $postings[] = [
                'board_id' => $boardId,
                'feed_id' => (string) (1_000_000 + $i),
                'title' => $title,
                'url' => "https://example.se/jobs/{$i}",
                'company' => mt_rand(0, 4) === 0 ? 'Kund '.mt_rand(1, 400).' AB' : "Företag {$boardId} AB",
                'cities' => json_encode(array_column($places, 0), JSON_UNESCAPED_UNICODE),
                'countries' => json_encode(array_values(array_unique(array_column($places, 1)))),
                'remote' => $remote?->value,
                'department' => mt_rand(0, 3) === 0 ? null : self::DEPARTMENTS[mt_rand(0, count(self::DEPARTMENTS) - 1)],
                'employment_type' => 'FULL_TIME',
                'salary_text' => null,
                'posted_at' => $now->copy()->subMinutes(mt_rand(0, 60 * 24 * 60)),
                'description' => "Vi söker en {$title}. ".implode(' ', $words),
                'first_seen_at' => $now->copy()->subMinutes(mt_rand(0, 60 * 24 * 30)),
                'last_seen_at' => $now,
                'active' => mt_rand(0, 19) !== 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($places as [$city, $country]) {
                $locations[] = ['job_posting_id' => $firstId + $i, 'city' => $city, 'country' => $country];
            }

            if (count($postings) === 1000) {
                DB::table('job_postings')->insert($postings);
                $postings = [];
            }
        }

        if ($postings !== []) {
            DB::table('job_postings')->insert($postings);
        }
        foreach (array_chunk($locations, 1000) as $chunk) {
            DB::table('job_posting_locations')->insert($chunk);
        }

        DB::table('boards')->update([
            'job_count' => DB::table('job_postings')->selectRaw('count(*)')
                ->whereColumn('job_postings.board_id', 'boards.id')
                ->where('job_postings.active', true),
        ]);
    }
}
