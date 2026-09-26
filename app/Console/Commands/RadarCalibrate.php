<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Discovery\Calibration\CalibrationResult;
use App\Discovery\Calibration\CalibrationSection;
use App\Discovery\Calibration\Calibrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class RadarCalibrate extends Command
{
    protected $signature = 'radar:calibrate
        {hosts* : Hosts to examine, for example career.kry.se}
        {--without=* : Sections to skip (dns, txt, tls, http)}
        {--output= : Directory for the JSON files (default storage/app/calibration)}';

    protected $description = 'Record DNS, TLS and HTTP fingerprints of known Teamtailor career sites';

    public function handle(Calibrator $calibrator): int
    {
        $sections = $this->sections();
        if ($sections === null) {
            return self::INVALID;
        }

        $outputOption = $this->option('output');
        $directory = is_string($outputOption) && $outputOption !== ''
            ? $outputOption
            : storage_path('app/calibration');
        File::ensureDirectoryExists($directory);

        $rows = [];
        /** @var list<string> $hosts */
        $hosts = (array) $this->argument('hosts');

        foreach ($hosts as $host) {
            $this->components->task($this->t('radar.calibrate.examining', ['host' => $host]), function () use ($calibrator, $host, $sections, $directory, &$rows): bool {
                $result = $calibrator->calibrate($host, $sections);
                File::put(
                    $directory.'/'.$result->host.'.json',
                    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
                );
                $rows[] = $this->row($result);

                return true;
            });
        }

        $this->table([
            $this->t('radar.calibrate.columns.host'),
            $this->t('radar.calibrate.columns.cname'),
            $this->t('radar.calibrate.columns.cname_match'),
            $this->t('radar.calibrate.columns.address_match'),
            $this->t('radar.calibrate.columns.txt_match'),
            $this->t('radar.calibrate.columns.feed'),
            $this->t('radar.calibrate.columns.final_host'),
        ], $rows);

        $this->components->info($this->t('radar.calibrate.written', ['directory' => $directory]));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @return list<CalibrationSection>|null
     */
    private function sections(): ?array
    {
        $without = [];
        foreach ((array) $this->option('without') as $value) {
            foreach (explode(',', (string) $value) as $name) {
                $section = CalibrationSection::tryFrom(strtolower(trim($name)));
                if ($section === null) {
                    $this->components->error($this->t('radar.calibrate.unknown_section', ['section' => $name]));

                    return null;
                }
                $without[] = $section;
            }
        }

        return array_values(array_filter(
            CalibrationSection::cases(),
            static fn (CalibrationSection $section): bool => ! in_array($section, $without, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function row(CalibrationResult $result): array
    {
        $feed = $result->snapshot('/jobs.json');
        $feedCell = match (true) {
            $feed === null => '',
            $feed->status === null => $this->t('radar.calibrate.feed_error'),
            ($feed->details['has_jobposting'] ?? false) === true => $feed->status.' jsonfeed',
            default => (string) $feed->status,
        };

        $finalHost = '';
        if ($feed?->finalUrl !== null) {
            $finalHost = (string) parse_url($feed->finalUrl, PHP_URL_HOST);
        }

        return [
            $result->host,
            implode(' → ', $result->cnameChain()),
            $result->cnameMatch() ?? '',
            $result->addressMatch() ?? '',
            $result->rootTxt === null ? '' : ($result->txtMatch() ?? '–'),
            $feedCell,
            $finalHost,
        ];
    }
}
