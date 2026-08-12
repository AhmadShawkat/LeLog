<?php

return [
    'ingestion' => [
        'max_batch_size' => (int) env('LOGS_MAX_BATCH_SIZE', 1000),
        'future_tolerance_seconds' => (int) env('LOGS_FUTURE_TOLERANCE_SECONDS', 300),
        'async' => (bool) env('LOGS_INGEST_ASYNC', false),
    ],

    'query' => [
        'default_limit' => (int) env('LOGS_QUERY_DEFAULT_LIMIT', 100),
        'max_limit' => (int) env('LOGS_QUERY_MAX_LIMIT', 1000),
    ],

    'retention' => [
        'days' => (int) env('LOGS_RETENTION_DAYS', 30),
        'interval_seconds' => (int) env('LOGS_RETENTION_INTERVAL_SECONDS', 60),
        'batch_size' => (int) env('LOGS_RETENTION_BATCH_SIZE', 10000),
        'max_batches_per_run' => (int) env('LOGS_RETENTION_MAX_BATCHES', 10),
    ],

    'runtime' => [
        'database_worker_limit' => (int) env('LOGS_DATABASE_WORKER_LIMIT', 4),
        'shutdown_timeout_seconds' => (int) env('LOGS_SHUTDOWN_TIMEOUT_SECONDS', 30),
    ],
];
