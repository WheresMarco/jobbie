<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Discovery\Verifier;
use App\Enums\CandidateStage;
use App\Enums\Strength;
use App\Scraping\FeedException;
use App\Scraping\Teamtailor\FeedClient;
use App\Scraping\Teamtailor\RssEnricher;
use Illuminate\Console\Command;

/**
 * Manual smoke test against real career sites; not part of CI. Verifies
 * and reads each host without writing anything to the database.
 */
final class RadarSmoke extends Command
{
    use Translates;

    public const array DEFAULT_HOSTS = ['career.kry.se', 'jobs.timefold.ai', 'jobb.nordicwellness.se'];

    protected $signature = 'radar:smoke {hosts?* : Hosts to check (default three known career sites)}';

    protected $description = 'Check verification, feed and RSS against real career sites (not run in CI)';

    public function handle(Verifier $verifier, FeedClient $feeds, RssEnricher $rss): int
    {
        /** @var list<string> $hosts */
        $hosts = (array) $this->argument('hosts') ?: self::DEFAULT_HOSTS;
        $rows = [];
        $ok = true;

        foreach ($hosts as $host) {
            $verdict = $verifier->verify($host, Strength::Strong);
            $postings = $enriched = '';

            if ($verdict->stage === CandidateStage::Verified) {
                try {
                    $feed = $feeds->fetch($verdict->finalHost ?? $host);
                    $postings = (string) count($feed->items);
                    $enriched = (string) count($rss->fetch($feed->finalHost() ?: $host));
                } catch (FeedException $e) {
                    $postings = $e->getMessage();
                    $ok = false;
                }
            } else {
                $ok = false;
            }

            $rows[] = [$host, $verdict->stage->value, (string) $verdict->reason, (string) $verdict->finalHost, $postings, $enriched];
        }

        $this->table([
            $this->t('radar.smoke.columns.host'),
            $this->t('radar.smoke.columns.stage'),
            $this->t('radar.smoke.columns.reason'),
            $this->t('radar.smoke.columns.final_host'),
            $this->t('radar.smoke.columns.postings'),
            $this->t('radar.smoke.columns.rss'),
        ], $rows);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
