<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Discovery\CandidateCollector;
use App\Discovery\JobStream\JobStreamSync;
use App\Discovery\SourceRegistry;
use App\Enums\CandidateSourceType;
use App\Jobs\CollectCandidates;
use App\Jobs\QueueVerification;
use App\Jobs\SyncJobStream;
use App\Models\Domain;
use App\Net\DomainName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use SplFileObject;

final class RadarDiscover extends Command
{
    use Translates;

    protected $signature = 'radar:discover
        {--source=* : Sources to run (jobstream, prefixes, commoncrawl); default from config}
        {--domains= : CSV with base domains for prefix probing (domain, org number, company name)}
        {--sync : Run in this process instead of queueing}
        {--verify : Queue verification after the sources have run}';

    protected $description = 'Collect candidate hosts from the discovery sources';

    public function handle(DomainName $domains, SourceRegistry $sources, CandidateCollector $collector, JobStreamSync $jobStream): int
    {
        $types = $this->types();
        if ($types === null) {
            return self::INVALID;
        }

        $domainsOption = $this->option('domains');
        if (is_string($domainsOption) && $domainsOption !== '') {
            $imported = $this->importDomains($domainsOption, $domains);
            if ($imported === null) {
                return self::FAILURE;
            }
            $this->components->info($this->t('radar.discover.domains_imported', ['count' => $imported]));
        }

        $chain = [];

        foreach ($types as $type) {
            if (! SourceRegistry::isCollectable($type)) {
                $this->components->info($this->t('radar.discover.implicit', ['source' => $type->value]));

                continue;
            }

            if ($this->option('sync') === true) {
                if ($type === CandidateSourceType::JobStream) {
                    $result = $jobStream->sync();
                    $this->components->info($this->t('radar.discover.jobstream_synced', [
                        'ads' => $result->ads, 'removed' => $result->removed, 'domains' => $result->newDomains,
                    ]));
                }

                $counts = $collector->collect($sources->source($type));
                $this->components->info($this->t('radar.discover.collected', ['source' => $type->value, ...$counts]));

                continue;
            }

            // Queued sources run one after another in a single chain.
            if ($type === CandidateSourceType::JobStream) {
                $chain[] = new SyncJobStream();
            }
            $chain[] = new CollectCandidates($type);

            $this->components->info($this->t('radar.discover.queued', ['source' => $type->value]));
        }

        if ($this->option('verify') === true) {
            if ($this->option('sync') === true) {
                $this->call('radar:verify', ['--no-wait' => true]);
            } else {
                $chain[] = new QueueVerification();
            }
        }

        if ($chain !== []) {
            Bus::chain($chain)->dispatch();
        }

        return self::SUCCESS;
    }

    /**
     * @return list<CandidateSourceType>|null
     */
    private function types(): ?array
    {
        /** @var list<string> $names */
        $names = (array) $this->option('source');
        if ($names === []) {
            /** @var list<string> $names */
            $names = (array) config('radar.sources.enabled', []);
        }

        $types = [];
        foreach ($names as $name) {
            foreach (explode(',', $name) as $part) {
                $type = SourceRegistry::typeFromName($part);
                if ($type === null) {
                    $this->components->error($this->t('radar.discover.unknown_source', ['source' => $part]));

                    return null;
                }
                $types[$type->value] = $type;
            }
        }

        return array_values($types);
    }

    private function importDomains(string $path, DomainName $domains): ?int
    {
        if (! is_file($path)) {
            $this->components->error($this->t('radar.import_legacy.missing', ['path' => $path]));

            return null;
        }

        $rows = [];
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        foreach ($file as $line) {
            if (! is_array($line) || ! is_string($line[0] ?? null)) {
                continue;
            }

            // A header row or anything else that is not a domain is skipped.
            $base = $domains->registrable($line[0]);
            if ($base === null) {
                continue;
            }

            $orgNr = is_string($line[1] ?? null) ? preg_replace('/\D/', '', $line[1]) : '';
            $rows[$base] = [
                'base_domain' => $base,
                'source' => CandidateSourceType::Seed->value,
                'org_nr' => $orgNr === '' ? null : $orgNr,
                'company_name' => is_string($line[2] ?? null) && trim($line[2]) !== '' ? mb_substr(trim($line[2]), 0, 255) : null,
            ];
        }

        $created = 0;
        foreach (array_chunk(array_values($rows), 1000) as $chunk) {
            $created += Domain::query()->insertOrIgnore($chunk);
        }

        return $created;
    }
}
