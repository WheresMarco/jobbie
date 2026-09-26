<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Enums\CandidateSourceType;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Models\Candidate;
use App\Net\DomainName;
use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * Imports hosts from the Python prototype's SQLite file as strong seed
 * candidates. The prototype schema is not pinned down, so every table is
 * scanned for the first column that looks like a host or URL.
 */
final class RadarImportLegacy extends Command
{
    use Translates;

    /** Column names tried in order of preference. */
    private const array HOST_COLUMNS = [
        'host', 'hostname', 'canonical_host', 'board_host', 'domain',
        'url', 'career_url', 'home_url', 'feed_url', 'base_url',
    ];

    private const int CHUNK = 1000;

    protected $signature = 'radar:import-legacy {path : SQLite file from the Python prototype}';

    protected $description = 'Import hosts from the Python prototype as seed candidates';

    public function handle(DomainName $domains): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->components->error($this->t('radar.import_legacy.missing', ['path' => $path]));

            return self::FAILURE;
        }

        try {
            $pdo = new PDO('sqlite:'.$path, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            $tableNames = $tables === false ? [] : $tables->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $this->components->error($this->t('radar.import_legacy.unreadable', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        $rows = [];
        $totalCreated = 0;

        foreach ($tableNames as $table) {
            $table = (string) $table;
            $column = $this->hostColumn($pdo, $table);
            if ($column === null) {
                continue;
            }

            [$seen, $invalid, $created] = $this->importColumn($pdo, $domains, $table, $column);
            $rows[] = ["{$table}.{$column}", $seen, $invalid, $created];
            $totalCreated += $created;
        }

        if ($rows === []) {
            $this->components->warn($this->t('radar.import_legacy.no_columns'));

            return self::FAILURE;
        }

        $this->table([
            $this->t('radar.import_legacy.columns.column'),
            $this->t('radar.import_legacy.columns.seen'),
            $this->t('radar.import_legacy.columns.invalid'),
            $this->t('radar.import_legacy.columns.created'),
        ], $rows);

        $this->components->info($this->t('radar.import_legacy.done', ['count' => $totalCreated]));

        return self::SUCCESS;
    }

    private function hostColumn(PDO $pdo, string $table): ?string
    {
        $statement = $pdo->query('PRAGMA table_info('.$this->quote($table).')');
        $columns = array_map(
            static fn (mixed $row): string => is_array($row) ? strtolower((string) $row['name']) : '',
            $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC),
        );

        foreach (self::HOST_COLUMNS as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{int, int, int} Values seen, invalid values, candidates created.
     */
    private function importColumn(PDO $pdo, DomainName $domains, string $table, string $column): array
    {
        $statement = $pdo->query(sprintf(
            'SELECT DISTINCT %1$s FROM %2$s WHERE %1$s IS NOT NULL',
            $this->quote($column),
            $this->quote($table),
        ));

        $seen = $invalid = $created = 0;
        $batch = [];
        $now = now();

        while ($statement !== false && ($value = $statement->fetchColumn()) !== false) {
            $seen++;
            $host = $domains->normalize((string) $value);
            if ($host === null) {
                $invalid++;

                continue;
            }

            $batch[$host] = [
                'host' => $host,
                'source' => CandidateSourceType::Seed->value,
                'source_ref' => "legacy:{$table}.{$column}",
                'strength' => Strength::Strong->value,
                'stage' => CandidateStage::New->value,
                'discovered_at' => $now,
            ];

            if (count($batch) >= self::CHUNK) {
                $created += Candidate::query()->insertOrIgnore(array_values($batch));
                $batch = [];
            }
        }

        if ($batch !== []) {
            $created += Candidate::query()->insertOrIgnore(array_values($batch));
        }

        return [$seen, $invalid, $created];
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
