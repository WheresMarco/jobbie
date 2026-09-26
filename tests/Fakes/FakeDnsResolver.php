<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Net\Contracts\DnsResolver;
use App\Net\DnsAnswer;
use App\Net\DnsRecordType;
use App\Net\DnsStatus;

/**
 * In memory resolver. Unknown names answer NXDOMAIN.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, DnsAnswer> keyed by "TYPE host" */
    private array $answers = [];

    /** @var list<string> */
    public array $queries = [];

    public function add(DnsAnswer $answer): self
    {
        $this->answers[$answer->type->name.' '.$answer->host] = $answer;

        return $this;
    }

    /**
     * Load the dns section of every calibration fixture.
     */
    public static function fromCalibrationFixtures(): self
    {
        $resolver = new self();

        foreach (glob(dirname(__DIR__).'/Fixtures/calibration/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ((array) $data['dns'] as $answer) {
                if (is_array($answer)) {
                    $resolver->add(DnsAnswer::fromArray($answer));
                }
            }
        }

        return $resolver;
    }

    public function resolve(string $host, DnsRecordType $type): DnsAnswer
    {
        $this->queries[] = $type->name.' '.$host;

        return $this->answers[$type->name.' '.$host] ?? new DnsAnswer($host, $type, DnsStatus::NxDomain);
    }

    public function resolveMany(array $hosts, DnsRecordType $type): array
    {
        $answers = [];
        foreach ($hosts as $host) {
            $answers[$host] = $this->resolve($host, $type);
        }

        return $answers;
    }
}
