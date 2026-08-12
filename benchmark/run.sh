#!/usr/bin/env bash
set -euo pipefail

profile="${1:-quick}"
[[ "$profile" == 'quick' || "$profile" == 'full' ]] || { echo 'Usage: bash benchmark/run.sh [quick|full]' >&2; exit 2; }

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
run_stamp="$(date -u '+%Y%m%dt%H%M%Sz')"
run_id="lelog-${profile}-${run_stamp}-$$"
export COMPOSE_PROJECT_NAME="$run_id"
export APP_PORT=0
compose=(docker compose --project-directory "$repo" -f "$repo/compose.yaml" -f "$repo/benchmark/compose.yaml")
result_dir="$repo/benchmark-results/$run_id"
sampler_marker=''
sampler_pid=''

if [[ "$profile" == 'full' ]]; then
    batch_size=1000
    seed_logs=860000
    mixed_rate=16
    mixed_duration=20
    query_rate=10
    aggregation_rate=1
else
    batch_size=100
    seed_logs=2000
    mixed_rate=2
    mixed_duration=5
    query_rate=2
    aggregation_rate=1
fi

planned_mixed_logs=$((mixed_rate * mixed_duration * batch_size))
first_mixed_sequence=$seed_logs
last_mixed_sequence=$((seed_logs + planned_mixed_logs - 1))
base_epoch_ms="$(date -u '+%s000')"

mkdir -p "$result_dir"

cleanup() {
    if [[ -n "$sampler_marker" ]]; then rm -f "$sampler_marker"; fi
    if [[ -n "$sampler_pid" ]]; then wait "$sampler_pid" 2>/dev/null || true; fi
    "${compose[@]}" down --volumes --remove-orphans --rmi local >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() { echo "$1" >&2; exit 1; }

psql() {
    "${compose[@]}" exec --no-TTY postgres psql --username lelog --dbname lelog --no-psqlrc "$@"
}

psql_value() {
    psql --tuples-only --no-align --command "$1"
}

capture_postgres() {
    local output="$1"
    psql --tuples-only --no-align --command "
        SELECT jsonb_pretty(jsonb_build_object(
            'captured_at', clock_timestamp(),
            'pg_stat_wal', (SELECT to_jsonb(s) FROM pg_stat_wal AS s),
            'pg_stat_io', (SELECT coalesce(jsonb_agg(to_jsonb(s)), '[]'::jsonb) FROM pg_stat_io AS s),
            'pg_stat_user_tables', (SELECT coalesce(jsonb_agg(to_jsonb(s)), '[]'::jsonb) FROM pg_stat_user_tables AS s WHERE relname = 'logs'),
            'pg_stat_checkpointer', (SELECT to_jsonb(s) FROM pg_stat_checkpointer AS s),
            'settings', (SELECT jsonb_object_agg(name, setting) FROM pg_settings WHERE name IN ('fsync','synchronous_commit','full_page_writes','track_io_timing','shared_buffers','work_mem','max_connections')),
            'indexes', (SELECT jsonb_agg(jsonb_build_object('name', c.relname, 'definition', pg_get_indexdef(c.oid), 'options', c.reloptions, 'size_bytes', pg_relation_size(c.oid)) ORDER BY c.relname) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = 'logs'::regclass),
            'relations', jsonb_build_object('table_bytes', pg_relation_size('logs'), 'indexes_bytes', pg_indexes_size('logs'), 'total_bytes', pg_total_relation_size('logs'))
        ));" > "$output"
}

start_sampler() {
    local phase="$1"
    local output="$result_dir/${phase}-resources.jsonl"
    sampler_marker="$result_dir/.sample-$phase"
    : > "$sampler_marker"
    (
        while [[ -f "$sampler_marker" ]]; do
            local app_stats postgres_stats connections
            app_stats="$(docker stats --no-stream --format '{{.CPUPerc}}|{{.MemUsage}}|{{.PIDs}}' "$app_id")"
            postgres_stats="$(docker stats --no-stream --format '{{.CPUPerc}}|{{.MemUsage}}|{{.PIDs}}' "$postgres_id")"
            connections="$(psql_value "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database();" | tr -d '[:space:]')"
            IFS='|' read -r app_cpu app_memory app_processes <<< "$app_stats"
            IFS='|' read -r postgres_cpu postgres_memory postgres_processes <<< "$postgres_stats"
            printf '{"captured_at":"%s","phase":"%s","app":{"cpu":"%s","memory":"%s","processes":%s},"postgres":{"cpu":"%s","memory":"%s","processes":%s},"postgres_connections":%s}\n' \
                "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$phase" "$app_cpu" "$app_memory" "$app_processes" "$postgres_cpu" "$postgres_memory" "$postgres_processes" "$connections" >> "$output"
            sleep 1
        done
    ) &
    sampler_pid=$!
}

stop_sampler() {
    rm -f "$sampler_marker"
    wait "$sampler_pid"
    sampler_marker=''
    sampler_pid=''
}

run_k6() {
    local phase="$1"
    local summary_file="${phase}-k6.json"
    "${compose[@]}" run --rm --no-deps \
        -e BENCHMARK_PHASE="$phase" \
        -e BASE_URL='http://app:8080' \
        -e RUN_ID="$run_id" \
        -e BATCH_SIZE="$batch_size" \
        -e SEED_LOGS="$seed_logs" \
        -e MIXED_RATE="$mixed_rate" \
        -e MIXED_DURATION_SECONDS="$mixed_duration" \
        -e QUERY_RATE="$query_rate" \
        -e AGGREGATION_RATE="$aggregation_rate" \
        -e BASE_EPOCH_MS="$base_epoch_ms" \
        -e SUMMARY_FILE="$run_id/$summary_file" \
        k6 run /scripts/benchmark.js
}

capture_durability() {
    local phase="$1" first="$2" expected="$3" output="$4"
    local last=$((first + expected - 1))
    psql --tuples-only --no-align --set=run_id="$run_id" --set=phase="$phase" --set=first="$first" --set=last="$last" > "$output" <<'SQL'
        WITH selected AS (
            SELECT (attributes_text ->> 'benchmark_seq')::bigint AS sequence, received_at
            FROM logs
            WHERE attributes_text @> jsonb_build_object('run_id', :'run_id', 'phase', :'phase')
              AND (attributes_text ->> 'benchmark_seq')::bigint BETWEEN :'first'::bigint AND :'last'::bigint
        ), bounds AS (
            SELECT count(*) AS rows, count(DISTINCT sequence) AS distinct_sequences,
                   count(*) - count(DISTINCT sequence) AS duplicate_sequences,
                   min(sequence) AS minimum_sequence, max(sequence) AS maximum_sequence,
                   min(received_at) AS first_commit, max(received_at) AS last_commit
            FROM selected
        )
        SELECT jsonb_pretty(to_jsonb(bounds) || jsonb_build_object(
            'observed_commit_seconds', extract(epoch FROM last_commit - first_commit)
        )) FROM bounds;
SQL
}

command -v docker >/dev/null || fail 'Docker is required.'
docker compose version >/dev/null

git_commit="$(git -C "$repo" rev-parse HEAD)"
git_dirty=false
[[ -z "$(git -C "$repo" status --short)" ]] || git_dirty=true

cat > "$result_dir/metadata.json" <<JSON
{
  "run_id": "$run_id",
  "profile": "$profile",
  "smoke_only": $([[ "$profile" == 'quick' ]] && echo true || echo false),
  "git_commit": "$git_commit",
  "git_dirty": $git_dirty,
  "base_epoch_ms": $base_epoch_ms,
  "seed": {"logs": $seed_logs, "batch_size": $batch_size, "concurrency": 4},
  "mixed": {
    "batch_size": $batch_size,
    "arrival_rate_requests_per_second": $mixed_rate,
    "scheduled_logs_per_second": $((mixed_rate * batch_size)),
    "duration_seconds": $mixed_duration,
    "planned_logs": $planned_mixed_logs,
    "filtered_queries_per_second": $query_rate,
    "aggregations_per_second": $aggregation_rate,
    "first_sequence": $first_mixed_sequence,
    "last_sequence": $last_mixed_sequence
  },
  "resource_limits": {
    "app": {"cpus": 0.5, "memory_bytes": 268435456},
    "postgres": {"cpus": 1.0, "memory_bytes": 1073741824},
    "maximum_database_workers": 4
  }
}
JSON

"${compose[@]}" down --volumes --remove-orphans --rmi local >/dev/null 2>&1 || true
"${compose[@]}" up --build --detach --wait app postgres

app_id="$("${compose[@]}" ps --quiet app)"
postgres_id="$("${compose[@]}" ps --quiet postgres)"
[[ "$(docker inspect --format '{{.HostConfig.NanoCpus}}' "$app_id")" == '500000000' ]] || fail 'App CPU limit is not 0.5 CPU.'
[[ "$(docker inspect --format '{{.HostConfig.Memory}}' "$app_id")" == '268435456' ]] || fail 'App memory limit is not 256 MiB.'
[[ "$(docker inspect --format '{{.HostConfig.NanoCpus}}' "$postgres_id")" == '1000000000' ]] || fail 'PostgreSQL CPU limit is not 1 CPU.'
[[ "$(docker inspect --format '{{.HostConfig.Memory}}' "$postgres_id")" == '1073741824' ]] || fail 'PostgreSQL memory limit is not 1 GiB.'
[[ "$(psql_value 'SELECT count(*) FROM logs;' | tr -d '[:space:]')" == '0' ]] || fail 'Benchmark database was not empty.'

start_sampler seed
run_k6 seed
stop_sampler
capture_durability seed 0 "$seed_logs" "$result_dir/seed-durability.json"
psql --command 'VACUUM ANALYZE logs;'
psql --command 'CHECKPOINT;'
capture_postgres "$result_dir/postgres-before.json"

start_sampler mixed
run_k6 mixed
stop_sampler
capture_postgres "$result_dir/postgres-after.json"
capture_durability mixed "$first_mixed_sequence" "$planned_mixed_logs" "$result_dir/mixed-durability.json"

psql --tuples-only --no-align --set=run_id="$run_id" --set=since="$(date -u -d "@$((base_epoch_ms / 1000 - 86400))" '+%Y-%m-%dT%H:%M:%SZ')" > "$result_dir/explain-filtered.json" <<'SQL'
    EXPLAIN (ANALYZE, BUFFERS, WAL, FORMAT JSON)
    SELECT id, event_timestamp, received_at, service, level, message, attributes
    FROM logs
    WHERE service = 'checkout' AND level = 'debug'
      AND attributes_text @> jsonb_build_object('run_id', :'run_id')
      AND message ILIKE '%benchmark%'
    ORDER BY event_timestamp DESC, id DESC LIMIT 100;
SQL

psql --tuples-only --no-align --set=run_id="$run_id" --set=since="$(date -u -d "@$((base_epoch_ms / 1000 - 86400))" '+%Y-%m-%dT%H:%M:%SZ')" --set=until="$(date -u -d "@$((base_epoch_ms / 1000 + 3600))" '+%Y-%m-%dT%H:%M:%SZ')" > "$result_dir/explain-aggregation.json" <<'SQL'
    EXPLAIN (ANALYZE, BUFFERS, WAL, FORMAT JSON)
    SELECT date_bin(INTERVAL '1 hour', event_timestamp, TIMESTAMPTZ '2001-01-01 00:00:00+00'), service, count(*)
    FROM logs
    WHERE event_timestamp >= :'since'::timestamptz AND event_timestamp < :'until'::timestamptz
      AND attributes_text @> jsonb_build_object('run_id', :'run_id')
    GROUP BY 1, 2 ORDER BY 1, 2;
SQL

app_restarts="$(docker inspect --format '{{.RestartCount}}' "$app_id")"
postgres_restarts="$(docker inspect --format '{{.RestartCount}}' "$postgres_id")"
app_oom="$(docker inspect --format '{{.State.OOMKilled}}' "$app_id")"
postgres_oom="$(docker inspect --format '{{.State.OOMKilled}}' "$postgres_id")"
health_status="$(docker inspect --format '{{.State.Health.Status}}' "$app_id")"
cat > "$result_dir/final-containers.json" <<JSON
{"app":{"restarts":$app_restarts,"oom_killed":$app_oom},"postgres":{"restarts":$postgres_restarts,"oom_killed":$postgres_oom},"health":"$health_status"}
JSON
[[ "$app_restarts" == '0' && "$postgres_restarts" == '0' && "$app_oom" == 'false' && "$postgres_oom" == 'false' && "$health_status" == 'healthy' ]] || fail 'A benchmark container restarted, was OOM-killed, or became unhealthy.'

"${compose[@]}" run --rm --no-deps reporter /scripts/report.php "/reports/$run_id" "$profile"
echo "$profile benchmark completed successfully."
