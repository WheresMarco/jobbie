<?php

declare(strict_types=1);

/*
| Sets up (or brings up to date) the Laravel Cloud environment for this
| repository through the official Cloud CLI (laravel/cloud-cli), then
| deploys. Safe to run again: existing resources are reused and settings
| are applied anew. Run by .github/workflows/laravel-cloud.yml.
|
| Environment:
|   LARAVEL_CLOUD_TOKEN         API token (read by the CLI itself)
|   CLOUD_BIN                   path to the cloud CLI
|   CLOUD_APP_NAME              application name (default teamtailor-radar)
|   CLOUD_REPOSITORY            owner/repo
|   CLOUD_BRANCH                branch to deploy
|   CLOUD_REGION                region for new resources
|   CLOUD_WORKER_SIZE           instance size for Horizon (default: smallest with 1 GB)
|   CLOUD_DEPLOY                "true" to deploy at the end
|   CLOUD_FIRST_RUN             "true" to start discovery and scraping after deploying
|   RADAR_BASIC_AUTH_USER, RADAR_BASIC_AUTH_PASSWORD, RADAR_USER_AGENT
|   RADAR_SEARCH_API_KEY        optional
*/

const BUILD_COMMAND = "composer install --no-dev --optimize-autoloader --no-interaction\nnpm ci\nnpm run build";
const DEPLOY_COMMAND = "php artisan migrate --force\nphp artisan horizon:terminate";
const WORKER_NAME = 'horizon';
const HORIZON_COMMAND = 'php artisan horizon';

$bin = getenv('CLOUD_BIN') ?: 'cloud';
$appName = getenv('CLOUD_APP_NAME') ?: 'teamtailor-radar';
$repository = required('CLOUD_REPOSITORY');
$branch = getenv('CLOUD_BRANCH') ?: 'main';
$region = getenv('CLOUD_REGION') ?: 'eu-central-1';

required('LARAVEL_CLOUD_TOKEN');
$basicUser = required('RADAR_BASIC_AUTH_USER');
$basicPassword = required('RADAR_BASIC_AUTH_PASSWORD');

// 1. Application, default environment, scheduler and MySQL database.
$application = findApplication($appName, $repository);
if ($application === null) {
    step("Creating application {$appName} in {$region} with a Laravel MySQL database (cloud ship)");
    $shipped = cloud(['ship', '--source-provider=github', '--database=mysql', '--database-preset=dev', "--name={$appName}", "--region={$region}"]);
    $application = cloud(['application:get', (string) ($shipped['application_id'] ?? '')]);
} else {
    step("Using existing application {$application['name']} ({$application['id']})");
}

$applicationId = (string) $application['id'];
$environmentId = (string) ($application['defaultEnvironmentId'] ?? '');
if ($environmentId === '') {
    fail('The application has no default environment.');
}

$environment = cloud(['environment:get', $environmentId]);
$region = (string) ($application['region'] ?? $region);

// 2. Branch, build and deploy commands.
step("Environment {$environment['name']}: branch {$branch}, build and deploy commands");
cloud(['environment:update', $environmentId, '--force', "--branch={$branch}", '--build-command='.BUILD_COMMAND, '--deploy-command='.DEPLOY_COMMAND]);

// 3. Valkey cache (Redis compatible) for queues, cache, sessions and Horizon.
if (empty($environment['cacheId'])) {
    $cache = findByName(cloud(['cache:list']), "{$appName}-cache");
    if ($cache === null) {
        [$type, $size] = pickCache(cloud(['cache:types']), $region);
        step("Creating cache {$appName}-cache ({$type}, {$size})");
        $cache = cloud(['cache:create', "--name={$appName}-cache", "--type={$type}", "--region={$region}", "--size={$size}"]);
    }

    step('Attaching the cache');
    retry(fn () => cloud(['environment:update', $environmentId, '--force', '--cache-id='.$cache['id']]));
}

// 4. Environment variables. "set" updates existing keys instead of duplicating them.
$variables = array_filter([
    'APP_NAME' => 'Teamtailor-radar',
    'APP_DEBUG' => 'false',
    'APP_LOCALE' => 'sv',
    'QUEUE_CONNECTION' => 'redis',
    'CACHE_STORE' => 'redis',
    'SESSION_DRIVER' => 'redis',
    'RADAR_LONG_QUEUE_CONNECTION' => 'redis-long',
    'RADAR_BASIC_AUTH_USER' => $basicUser,
    'RADAR_BASIC_AUTH_PASSWORD' => $basicPassword,
    'RADAR_USER_AGENT' => getenv('RADAR_USER_AGENT') ?: null,
    'RADAR_SEARCH_API_KEY' => getenv('RADAR_SEARCH_API_KEY') ?: null,
], static fn (?string $value): bool => $value !== null && $value !== '');

if (! str_contains(json_encode($environment['environmentVariables'] ?? []) ?: '', 'APP_KEY')) {
    $variables['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
}

foreach ($variables as $key => $value) {
    step("Setting {$key}");
    cloud(['environment:variables', $environmentId, '--force', '--action=set', "--key={$key}", "--value={$value}"]);
}

// 5. Scheduler on the web instance, Horizon on its own worker instance.
$instances = listOf(cloud(['instance:list', $environmentId]));
foreach ($instances as $instance) {
    if (($instance['type'] ?? '') !== 'worker' && ($instance['usesScheduler'] ?? false) !== true) {
        step("Turning on the scheduler on {$instance['name']}");
        cloud(['instance:update', (string) $instance['id'], '--force', '--uses-scheduler=true']);
    }
}

$worker = null;
foreach ($instances as $instance) {
    if (($instance['name'] ?? '') === WORKER_NAME) {
        $worker = $instance;
    }
}

if ($worker === null) {
    $size = getenv('CLOUD_WORKER_SIZE') ?: pickWorkerSize(cloud(['instance:sizes']));
    step("Creating worker instance ".WORKER_NAME." ({$size})");
    $worker = cloud([
        'instance:create', $environmentId, '--name='.WORKER_NAME, '--type=worker', "--size={$size}",
        '--scaling-type=none', '--min-replicas=1', '--max-replicas=1', '--uses-scheduler=false',
    ]);
}

$processes = listOf(cloud(['background-process:list', (string) $worker['id']]));
$hasHorizon = array_filter($processes, static fn (array $p): bool => str_contains((string) ($p['command'] ?? ''), 'horizon'));
if ($hasHorizon === []) {
    step('Adding the Horizon background process');
    cloud(['background-process:create', (string) $worker['id'], '--type=custom', '--command='.HORIZON_COMMAND, '--processes=1']);
}

// 6. Deploy, and optionally start the first discovery and scrape.
if (getenv('CLOUD_DEPLOY') === 'true') {
    step("Deploying {$branch}");
    cloud(['deploy', $applicationId, (string) $environment['name']], json: false);
}

if (getenv('CLOUD_FIRST_RUN') === 'true') {
    step('Queueing discovery and verification, then a scrape');
    cloud(['command:run', $environmentId, '--cmd=php artisan radar:discover --verify'], json: false);
    cloud(['command:run', $environmentId, '--cmd=php artisan radar:scrape --no-wait'], json: false);
}

$url = (string) ($environment['url'] ?? '');
step('Done'.($url !== '' ? ": {$url}" : ''));
if (($summary = getenv('GITHUB_STEP_SUMMARY')) !== false && $summary !== '') {
    file_put_contents($summary, "### Laravel Cloud\n\n- Application: `{$applicationId}`\n- Environment: `{$environmentId}`\n- URL: {$url}\n", FILE_APPEND);
}

/**
 * Run the CLI non-interactively. With $json, returns the last JSON object
 * or list it printed.
 *
 * @param  list<string>  $arguments
 * @return array<mixed>
 */
function cloud(array $arguments, bool $json = true): array
{
    global $bin;

    $command = [$bin, ...$arguments, '--no-interaction'];
    if ($json) {
        $command[] = '--json';
    }

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        fail('Could not start the cloud CLI.');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    if (! $json) {
        echo $stdout;
    }

    if ($exit !== 0) {
        throw new RuntimeException(trim("cloud {$arguments[0]} failed ({$exit}):\n{$stderr}\n{$stdout}"));
    }

    if (! $json) {
        return [];
    }

    foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/**
 * @return array<mixed>|null
 */
function findApplication(string $name, string $repository): ?array
{
    foreach (listOf(cloud(['application:list'])) as $application) {
        if (strcasecmp((string) ($application['repositoryFullName'] ?? ''), $repository) === 0
            || ($application['name'] ?? null) === $name) {
            return cloud(['application:get', (string) $application['id']]);
        }
    }

    return null;
}

/**
 * @param  array<mixed>  $items
 * @return array<mixed>|null
 */
function findByName(array $items, string $name): ?array
{
    foreach (listOf($items) as $item) {
        if (($item['name'] ?? null) === $name) {
            return $item;
        }
    }

    return null;
}

/**
 * Lists come back either as a plain list or wrapped in "data".
 *
 * @param  array<mixed>  $value
 * @return list<array<mixed>>
 */
function listOf(array $value): array
{
    $items = array_is_list($value) ? $value : ($value['data'] ?? []);

    return array_values(array_filter(is_array($items) ? $items : [], is_array(...)));
}

/**
 * Valkey if offered, else the first type; its smallest size.
 *
 * @param  array<mixed>  $types
 * @return array{string, string}
 */
function pickCache(array $types, string $region): array
{
    $types = listOf($types);
    usort($types, static fn (array $a, array $b): int => (int) ! str_contains(strtolower((string) ($a['label'] ?? '').($a['type'] ?? '')), 'valkey')
        <=> (int) ! str_contains(strtolower((string) ($b['label'] ?? '').($b['type'] ?? '')), 'valkey'));
    $type = $types[0] ?? fail('No cache types available.');
    $sizes = listOf((array) ($type['sizes'] ?? []));
    $size = $sizes[0]['value'] ?? fail("No sizes for cache type {$type['type']}.");

    if (! empty($type['regions']) && ! in_array($region, (array) $type['regions'], true)) {
        fail("Cache type {$type['type']} is not available in {$region}.");
    }

    return [(string) $type['type'], (string) $size];
}

/**
 * The smallest instance size with at least 1 GB of memory.
 *
 * @param  array<mixed>  $sizes
 */
function pickWorkerSize(array $sizes): string
{
    $options = [];
    foreach ((array) ($sizes['categories'] ?? $sizes) as $category) {
        foreach (listOf((array) $category) as $option) {
            if (($option['memoryMib'] ?? 0) >= 1024) {
                $options[] = $option;
            }
        }
    }

    usort($options, static fn (array $a, array $b): int => [$a['memoryMib'], $a['cpuCount'] ?? 0] <=> [$b['memoryMib'], $b['cpuCount'] ?? 0]);

    return (string) ($options[0]['name'] ?? fail('No instance size with 1 GB; set CLOUD_WORKER_SIZE.'));
}

/**
 * Resources that are still being created reject attachments for a while.
 */
function retry(callable $callback, int $attempts = 60, int $seconds = 10): mixed
{
    for ($i = 1; ; $i++) {
        try {
            return $callback();
        } catch (RuntimeException $e) {
            if ($i >= $attempts) {
                throw $e;
            }
            step("Not ready yet, retrying in {$seconds} s ({$i}/{$attempts})");
            sleep($seconds);
        }
    }
}

function required(string $name): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : fail("{$name} is not set.");
}

function step(string $message): void
{
    fwrite(STDOUT, "==> {$message}\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}
