<?php

declare(strict_types=1);

namespace App\Discovery\JobStream;

use App\Enums\BoardStatus;
use App\Enums\CandidateSourceType;
use App\Models\Board;
use App\Models\Domain;
use App\Models\PlatsbankenAd;
use App\Net\DomainName;
use App\Platforms\Platsbanken;
use App\Scraping\JobPostingData;
use App\Scraping\PostingWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Throwable;

/**
 * Keeps platsbanken_ads in step with JobTech JobStream (source B), records
 * employer web sites in domains, and keeps the Platsbanken board's
 * postings in step with the ads.
 *
 * The first run downloads /snapshot (all published ads, roughly 300 MB)
 * to disk and parses it as a stream. Later runs fetch /stream?date=...
 * with the changes since the previous run, including removals. Endpoints
 * and field names were checked against the JobStream source code
 * (gitlab.com/arbetsformedlingen/job-ads/jobsearch-apis, stream/rest.py).
 */
final readonly class JobStreamSync
{
    public const string CURSOR = 'jobstream';

    /** Removals disappear from /stream after 90 days; older cursors need a new snapshot. */
    public const int MAX_STREAM_DAYS = 80;

    /** The stream endpoint expects local Swedish time. */
    private const string TIMEZONE = 'Europe/Stockholm';

    private const int CHUNK = 1000;

    /** Employer "web sites" that are platforms, not the employer's own domain. */
    private const array PLATFORM_DOMAINS = [
        'facebook.com', 'linkedin.com', 'instagram.com', 'google.com', 'gmail.com', 'hotmail.com',
        'arbetsformedlingen.se', 'teamtailor.com', 'varbi.com', 'reachmee.com', 'jobylon.com',
        'recruitee.com', 'workbuster.se', 'webbplatsen.se', 'wixsite.com', 'blogspot.com',
    ];

    public function __construct(
        private DomainName $domains,
        private PostingWriter $writer,
        private string $baseUrl,
        private ?string $apiKey,
        private string $userAgent,
    ) {
    }

    public function sync(): JobStreamResult
    {
        $startedAt = CarbonImmutable::now();
        $cursor = $this->cursor();
        $snapshot = $cursor === null || $cursor->lessThan($startedAt->subDays(self::MAX_STREAM_DAYS));

        $path = storage_path('app/jobstream/'.($snapshot ? 'snapshot' : 'stream').'.json');
        File::ensureDirectoryExists(dirname($path));

        $query = $snapshot ? [] : ['date' => $cursor->subMinute()->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i:s')];

        try {
            $request = Http::withUserAgent($this->userAgent)
                ->acceptJson()
                ->timeout(1800)
                ->retry([5000, 60_000], throw: false)
                ->sink($path);

            if ($this->apiKey !== null && $this->apiKey !== '') {
                $request = $request->withHeaders(['api-key' => $this->apiKey]);
            }

            $request->get(rtrim($this->baseUrl, '/').($snapshot ? '/snapshot' : '/stream'), $query)->throw();

            $result = $this->import($path, $snapshot);
        } finally {
            File::delete($path);
        }

        DB::table('sync_cursors')->updateOrInsert(['name' => self::CURSOR], ['position' => $startedAt, 'updated_at' => Carbon::now()]);

        return $result;
    }

    public function cursor(): ?CarbonImmutable
    {
        $position = DB::table('sync_cursors')->where('name', self::CURSOR)->value('position');

        return is_string($position) ? CarbonImmutable::parse($position) : null;
    }

    private function import(string $path, bool $snapshot): JobStreamResult
    {
        $ads = $removed = $domains = 0;
        $batch = [];
        $board = Platsbanken::board();
        $now = Carbon::now();
        /** @var array<string, true> $seen */
        $seen = [];

        foreach (Items::fromFile($path, ['decoder' => new ExtJsonDecoder(true)]) as $ad) {
            if (! is_array($ad) || ! isset($ad['id'])) {
                continue;
            }

            $batch[] = $ad;
            if (count($batch) >= self::CHUNK) {
                [$a, $r, $d] = $this->store($batch, $board, $now, $seen);
                $ads += $a;
                $removed += $r;
                $domains += $d;
                $batch = [];
            }
        }

        if ($batch !== []) {
            [$a, $r, $d] = $this->store($batch, $board, $now, $seen);
            $ads += $a;
            $removed += $r;
            $domains += $d;
        }

        // A snapshot holds every published ad, so anything else has closed.
        if ($snapshot) {
            $this->writer->deactivateMissing($board, array_map(strval(...), array_keys($seen)), $now);

            $gone = PlatsbankenAd::query()->whereNull('removed_at')->pluck('id')
                ->map(strval(...))
                ->reject(static fn (string $id): bool => isset($seen[$id]));
            foreach ($gone->chunk(self::CHUNK) as $chunk) {
                PlatsbankenAd::query()->whereKey($chunk->values()->all())->update(['removed_at' => $now]);
            }
        }

        $this->refreshBoard($board, $now);

        return new JobStreamResult($snapshot, $ads, $removed, $domains);
    }

    private function refreshBoard(Board $board, Carbon $now): void
    {
        $jobCount = $board->jobPostings()->where('active', true)->count();

        $board->forceFill([
            'status' => $jobCount > 0 ? BoardStatus::Active : BoardStatus::Empty,
            'job_count' => $jobCount,
            'checked_at' => $now,
            'error' => null,
        ])->save();
    }

    /**
     * @param  list<array<mixed>>  $ads
     * @param  array<string, true>  $seen  Ids of published ads so far; added to.
     * @return array{int, int, int} ads stored, ads removed, new domains
     */
    private function store(array $ads, Board $board, Carbon $now, array &$seen): array
    {
        $rows = $removals = $domainRows = [];
        /** @var array<string, JobPostingData> $postings */
        $postings = [];

        foreach ($ads as $ad) {
            $id = (string) (is_scalar($ad['id']) ? $ad['id'] : '');
            if ($id === '') {
                continue;
            }

            if (($ad['removed'] ?? false) === true) {
                $removals[$id] = self::date($ad['removed_date'] ?? null) ?? Carbon::now();

                continue;
            }

            $seen[$id] = true;
            if (($posting = Platsbanken::posting($ad)) !== null) {
                $postings[$id] = $posting;
            }

            $employer = is_array($ad['employer'] ?? null) ? $ad['employer'] : [];
            $application = is_array($ad['application_details'] ?? null) ? $ad['application_details'] : [];
            $applyUrl = self::string($application['url'] ?? null);
            $orgNr = self::orgNr($employer['organization_number'] ?? null);
            $employerName = self::string($employer['name'] ?? null);

            $rows[$id] = [
                'id' => $id,
                'apply_host' => $applyUrl === null ? null : $this->domains->normalize($applyUrl),
                'apply_url' => $applyUrl === null ? null : mb_substr($applyUrl, 0, 2048),
                'org_nr' => $orgNr,
                'employer_name' => $employerName === null ? null : mb_substr($employerName, 0, 255),
                'title_normalized' => self::normalizeTitle(self::string($ad['headline'] ?? null)),
                'published_at' => self::date($ad['publication_date'] ?? null),
                'removed_at' => null,
            ];

            $employerUrl = self::string($employer['url'] ?? null);
            $base = $employerUrl === null ? null : $this->domains->registrable($employerUrl);
            if ($base !== null && ! in_array($base, self::PLATFORM_DOMAINS, true)) {
                $domainRows[$base] = [
                    'base_domain' => $base,
                    'source' => CandidateSourceType::JobStream->value,
                    'org_nr' => $orgNr,
                    'company_name' => $employerName === null ? null : mb_substr($employerName, 0, 255),
                ];
            }
        }

        DB::transaction(function () use ($rows, $removals, $postings, $board, $now): void {
            if ($rows !== []) {
                PlatsbankenAd::query()->upsert(array_values($rows), ['id'], [
                    'apply_host', 'apply_url', 'org_nr', 'employer_name', 'title_normalized', 'published_at', 'removed_at',
                ]);
            }

            foreach ($removals as $id => $removedAt) {
                PlatsbankenAd::query()->whereKey((string) $id)->update(['removed_at' => $removedAt]);
            }

            $this->writer->write($board, $postings, $now, ['in_platsbanken' => true]);
            $this->writer->deactivate($board, array_map(strval(...), array_keys($removals)), $now);
        });

        $newDomains = $domainRows === [] ? 0 : Domain::query()->insertOrIgnore(array_values($domainRows));

        return [count($rows), count($removals), $newDomains];
    }

    /**
     * Lower case with single spaces, for matching against job postings (Phase 9).
     */
    public static function normalizeTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($title)));

        return $title === '' ? null : mb_substr($title, 0, 500);
    }

    /**
     * Swedish organisation numbers as ten digits without hyphen.
     */
    private static function orgNr(mixed $value): ?string
    {
        $digits = is_scalar($value) ? (string) preg_replace('/\D/', '', (string) $value) : '';

        return $digits === '' ? null : substr($digits, -12);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // JobTech timestamps have no offset and are Swedish local time.
            return Carbon::parse($value, self::TIMEZONE)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
