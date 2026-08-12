<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php report.php <run-directory> <profile>\n");
    exit(2);
}

$directory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$profile = $argv[2];

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException("Expected a JSON object in $path");
    }

    return $decoded;
}

/** @return array<string, int|float|null> */
function latency(array $summary, string $metric): array
{
    $values = $summary['metrics'][$metric]['values'] ?? [];

    return [
        'p50_ms' => $values['med'] ?? null,
        'p90_ms' => $values['p(90)'] ?? null,
        'p95_ms' => $values['p(95)'] ?? null,
        'maximum_ms' => $values['max'] ?? null,
        'count' => $values['count'] ?? null,
    ];
}

function metric(array $summary, string $name, string $value, int|float $default = 0): int|float
{
    return $summary['metrics'][$name]['values'][$value] ?? $default;
}

function bytes(string $usage): int
{
    if (!preg_match('/^([0-9.]+)([KMG]iB)/', $usage, $matches)) {
        return 0;
    }

    $factor = ['KiB' => 1024, 'MiB' => 1024 ** 2, 'GiB' => 1024 ** 3][$matches[2]];

    return (int) round((float) $matches[1] * $factor);
}

/** @return array<string, mixed> */
function resources(string $path): array
{
    $samples = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $sample = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($sample)) {
            $samples[] = $sample;
        }
    }

    $summary = ['samples' => count($samples)];
    foreach (['app', 'postgres'] as $container) {
        $cpu = array_map(static fn (array $sample): float => (float) rtrim((string) $sample[$container]['cpu'], '%'), $samples);
        $memory = array_map(static fn (array $sample): int => bytes((string) $sample[$container]['memory']), $samples);
        $processes = array_map(static fn (array $sample): int => (int) $sample[$container]['processes'], $samples);
        $summary[$container] = [
            'average_cpu_percent' => $cpu === [] ? null : array_sum($cpu) / count($cpu),
            'maximum_cpu_percent' => $cpu === [] ? null : max($cpu),
            'maximum_memory_bytes' => $memory === [] ? null : max($memory),
            'maximum_processes' => $processes === [] ? null : max($processes),
        ];
    }
    $connections = array_map(static fn (array $sample): int => (int) $sample['postgres_connections'], $samples);
    $summary['maximum_postgres_connections'] = $connections === [] ? null : max($connections);

    return $summary;
}

$metadata = readJson("$directory/metadata.json");
$seed = readJson("$directory/seed-k6.json");
$mixed = readJson("$directory/mixed-k6.json");
$seedDurability = readJson("$directory/seed-durability.json");
$mixedDurability = readJson("$directory/mixed-durability.json");
$plannedMixed = (int) $metadata['mixed']['planned_logs'];
$duration = (int) $metadata['mixed']['duration_seconds'];
$durableMixed = (int) $mixedDurability['rows'];
$attempted = (int) metric($mixed, 'ingestion_attempted', 'count');
$successful = (int) metric($mixed, 'ingestion_successful', 'count');
$failed = (int) metric($mixed, 'ingestion_failed', 'count');
$dropped = (int) metric($mixed, 'dropped_iterations', 'count');
$accepted = (int) metric($mixed, 'accepted_logs', 'count');
$seedAccepted = (int) metric($seed, 'accepted_logs', 'count');
$runDurationMs = (float) ($mixed['state']['testRunDurationMs'] ?? 0);
$observedSeconds = (float) ($mixedDurability['observed_commit_seconds'] ?? 0);
$seedLogs = (int) $metadata['seed']['logs'];
$exactSeedSequences = (int) $seedDurability['rows'] === $seedLogs
    && (int) $seedDurability['distinct_sequences'] === $seedLogs
    && (int) $seedDurability['duplicate_sequences'] === 0
    && (int) $seedDurability['minimum_sequence'] === 0
    && (int) $seedDurability['maximum_sequence'] === $seedLogs - 1
    && $seedAccepted === $seedLogs;
$exactSequences = $durableMixed === $plannedMixed
    && (int) $mixedDurability['distinct_sequences'] === $plannedMixed
    && (int) $mixedDurability['duplicate_sequences'] === 0
    && (int) $mixedDurability['minimum_sequence'] === (int) $metadata['mixed']['first_sequence']
    && (int) $mixedDurability['maximum_sequence'] === (int) $metadata['mixed']['last_sequence'];

$latencies = [
    'ingestion' => latency($mixed, 'ingestion_latency'),
    'filtered_query' => latency($mixed, 'query_latency'),
    'aggregation' => latency($mixed, 'aggregation_latency'),
    'visibility' => latency($mixed, 'visibility_lag'),
];
$latencies['ingestion']['success_rate'] = metric($mixed, 'ingestion_success_rate', 'rate');
$latencies['filtered_query']['success_rate'] = metric($mixed, 'query_success_rate', 'rate');
$latencies['aggregation']['success_rate'] = metric($mixed, 'aggregation_success_rate', 'rate');
$latencies['visibility']['success_rate'] = metric($mixed, 'visibility_success_rate', 'rate');
$applicationTimings = [
    'json_decode' => latency($mixed, 'app_json_decode'),
    'validation_transform' => latency($mixed, 'app_validation_transform'),
    'postgresql_array_encoding' => latency($mixed, 'app_pg_array_encoding'),
    'connection_wait' => latency($mixed, 'app_connection_wait'),
    'sql_execution' => latency($mixed, 'app_sql_execution'),
    'complete_request' => latency($mixed, 'app_complete_request'),
];
$scheduledIngestionRequests = intdiv($plannedMixed, (int) $metadata['mixed']['batch_size']);
$droppedIngestionRequests = max(0, $scheduledIngestionRequests - $attempted);
$successfulAttemptRate = $attempted > 0 ? ($successful / $attempted) * 100 : 0;

$scheduledThroughput = $duration > 0 ? $durableMixed / $duration : 0;
$performancePass = $scheduledThroughput >= 15000
    && $exactSequences
    && $attempted === $successful
    && $failed === 0
    && $dropped === 0
    && (float) ($latencies['filtered_query']['p95_ms'] ?? INF) < 1000
    && (float) ($latencies['aggregation']['p95_ms'] ?? INF) < 1000
    && (float) ($latencies['visibility']['maximum_ms'] ?? INF) <= 20000;

$report = [
    'metadata' => $metadata,
    'target_assessment' => $profile === 'quick'
        ? ['label' => 'smoke-only', 'performance_pass' => null, 'note' => 'Quick results are not scaled and do not prove the production targets.']
        : ['label' => 'full', 'performance_pass' => $performancePass],
    'throughput' => [
        'scheduled_rate_logs_per_second' => (int) $metadata['mixed']['scheduled_logs_per_second'],
        'planned_mixed_logs' => $plannedMixed,
        'attempted_ingestion_requests' => $attempted,
        'successful_ingestion_requests' => $successful,
        'failed_ingestion_requests' => $failed,
        'dropped_iterations' => $dropped,
        'http_accepted_logs' => $accepted,
        'durable_unique_mixed_rows' => $durableMixed,
        'scheduled_window_durable_logs_per_second' => $scheduledThroughput,
        'observed_commit_logs_per_second' => $observedSeconds > 0 ? $durableMixed / $observedSeconds : null,
        'k6_drain_inclusive_accepted_logs_per_second' => $runDurationMs > 0 ? $accepted / ($runDurationMs / 1000) : null,
        'exact_sequence_coverage' => $exactSequences,
    ],
    'latency' => $latencies,
    'application_timings' => $applicationTimings,
    'durability' => [
        'seed' => $seedDurability + ['exact_sequence_coverage' => $exactSeedSequences, 'http_accepted_logs' => $seedAccepted],
        'mixed' => $mixedDurability,
    ],
    'resources' => [
        'asserted_limits' => $metadata['resource_limits'],
        'seed' => resources("$directory/seed-resources.jsonl"),
        'mixed' => resources("$directory/mixed-resources.jsonl"),
        'final_container_state' => readJson("$directory/final-containers.json"),
    ],
    'postgresql' => [
        'before_mixed' => readJson("$directory/postgres-before.json"),
        'after_mixed' => readJson("$directory/postgres-after.json"),
        'filtered_query_plan' => readJson("$directory/explain-filtered.json"),
        'aggregation_plan' => readJson("$directory/explain-aggregation.json"),
    ],
    'k6' => ['seed' => $seed, 'mixed' => $mixed],
];

file_put_contents("$directory/report.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

$containerState = $report['resources']['final_container_state'];
$appLog = is_file("$directory/app-container.log") ? (string) file_get_contents("$directory/app-container.log") : '';
$postgresLog = is_file("$directory/postgres-container.log") ? (string) file_get_contents("$directory/postgres-container.log") : '';
$runtimeLogs = $appLog."\n".$postgresLog;
$yesNo = static fn (bool $value): string => $value ? 'Yes' : 'No';
$seconds = static fn (int|float|null $milliseconds): string => $milliseconds === null
    ? 'not captured'
    : number_format((float) $milliseconds / 1000, 2).'s';
$milliseconds = static fn (int|float|null $value): string => $value === null
    ? 'not captured'
    : number_format((float) $value, 2).'ms';
$oomKilled = (bool) ($containerState['app']['oom_killed'] ?? false)
    || (bool) ($containerState['postgres']['oom_killed'] ?? false);
$containerRestarted = (int) ($containerState['app']['restarts'] ?? 0) > 0
    || (int) ($containerState['postgres']['restarts'] ?? 0) > 0;
$phpTimeout = preg_match('/execution timed out|max_execution_time|child .* exited on signal/i', $runtimeLogs) === 1;
$nginxTimeout = preg_match('/upstream timed out/i', $runtimeLogs) === 1;
$connectionReset = preg_match('/worker_connections are not enough|reset by peer|unexpected EOF|connection reset/i', $runtimeLogs) === 1;
$fpmAtCapacity = preg_match('/reached pm\.max_children/i', $runtimeLogs) === 1;
$postgresMaximumCpu = $report['resources']['mixed']['postgres']['maximum_cpu_percent'] ?? null;
$correctnessPass = $exactSeedSequences
    && $exactSequences
    && $accepted === $durableMixed
    && $attempted === $successful
    && $failed === 0
    && $dropped === 0;
$resultLabel = !$correctnessPass
    ? 'FAIL'
    : ($profile === 'quick'
        ? 'SMOKE PASS (not a Step 11 performance result)'
        : ($performancePass ? 'PASS' : 'FAIL'));

echo "\nGot vs expected\n";
printf("Ingestion: %s / %s logs\n", number_format($durableMixed), number_format($plannedMixed));
printf("Scheduled throughput: %s logs/s / expected 15,000+\n", number_format($scheduledThroughput));
printf("Successful attempted requests: %.2f%% / expected 100%%\n", $successfulAttemptRate);
printf("Dropped ingestion requests: %s / expected 0\n", number_format($droppedIngestionRequests));
printf("Ingestion p95: %s / informational\n", $seconds($latencies['ingestion']['p95_ms']));
printf("Query p95: %s / expected <1s\n", $seconds($latencies['filtered_query']['p95_ms']));
printf("Aggregation p95: %s / expected <1s\n", $seconds($latencies['aggregation']['p95_ms']));
printf("Visibility max: %s / expected <=20s\n", $seconds($latencies['visibility']['maximum_ms']));
printf("Final durable rows: %s; exact sequence coverage: %s\n", number_format($durableMixed), $yesNo($exactSequences));

echo "\nLaravel hot path\n";
echo "raw JSON -> one PHP loop -> six arrays -> one bound UNNEST -> plain JsonResponse\n";
printf("JSON decode p95: %s\n", $milliseconds($applicationTimings['json_decode']['p95_ms']));
printf("Validation/transformation p95: %s\n", $milliseconds($applicationTimings['validation_transform']['p95_ms']));
printf("PostgreSQL array encoding p95: %s\n", $milliseconds($applicationTimings['postgresql_array_encoding']['p95_ms']));
printf("Connection acquisition p95: %s\n", $milliseconds($applicationTimings['connection_wait']['p95_ms']));
printf("SQL execution p95: %s\n", $milliseconds($applicationTimings['sql_execution']['p95_ms']));
printf("Complete Laravel processing p95: %s\n", $milliseconds($applicationTimings['complete_request']['p95_ms']));

echo "\nReset investigation\n";
printf("OOM kill: %s\n", $yesNo($oomKilled));
printf("Container restart: %s\n", $yesNo($containerRestarted));
printf("PHP worker timeout: %s\n", $yesNo($phpTimeout));
printf("Nginx upstream timeout: %s\n", $yesNo($nginxTimeout));
printf("Connection reset signal: %s\n", $yesNo($connectionReset));
printf("PHP-FPM reached worker cap: %s\n", $yesNo($fpmAtCapacity));
printf("Maximum PostgreSQL CPU: %s\n", $postgresMaximumCpu === null ? 'not captured' : number_format((float) $postgresMaximumCpu, 2).'%');
printf("\nRESULT: %s\n", $resultLabel);
printf("Full JSON report: benchmark-results/%s/report.json\n", $metadata['run_id']);

if (!$correctnessPass) {
    fwrite(STDERR, "Benchmark correctness gate failed.\n");
    exit(1);
}

if ($profile === 'full' && !$performancePass) {
    fwrite(STDERR, "Benchmark performance gate failed.\n");
    exit(1);
}
