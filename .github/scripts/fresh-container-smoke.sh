#!/usr/bin/env bash
set -euo pipefail

export COMPOSE_PROJECT_NAME="lelog-contract-smoke-${GITHUB_RUN_ID:-$$}"
export APP_PORT=0
compose=(docker compose)
response_file="$(mktemp)"
base_url=''

cleanup() {
    rm -f "$response_file"
    "${compose[@]}" down --volumes --remove-orphans
}

trap cleanup EXIT

fail() {
    echo "$1" >&2
    exit 1
}

request() {
    local method="$1"
    local path="$2"
    local expected_status="$3"
    local body="${4:-}"
    local status

    if [[ -n "$body" ]]; then
        status="$(curl --silent --show-error --output "$response_file" --write-out '%{http_code}' \
            --request "$method" --header 'Content-Type: application/json' --data "$body" \
            "$base_url$path")"
    else
        status="$(curl --silent --show-error --output "$response_file" --write-out '%{http_code}' \
            --request "$method" "$base_url$path")"
    fi

    [[ "$status" == "$expected_status" ]] || fail "$method $path returned $status: $(cat "$response_file")"
}

assert_json() {
    local expected="$1"

    "${compose[@]}" exec --no-TTY app php -r '
        $actual = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $expected = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        if ($actual !== $expected) {
            fwrite(STDERR, "Unexpected JSON: ".json_encode($actual).PHP_EOL);
            exit(1);
        }
    ' "$expected" < "$response_file"
}

"${compose[@]}" down --volumes --remove-orphans
"${compose[@]}" up --build --detach --wait

app_id="$("${compose[@]}" ps --quiet app)"
postgres_id="$("${compose[@]}" ps --quiet postgres)"
published_port="$("${compose[@]}" port app 8080)"
base_url="http://127.0.0.1:${published_port##*:}"

[[ "$(docker inspect --format '{{.HostConfig.NanoCpus}}' "$app_id")" == '500000000' ]] || fail 'App CPU limit is not 0.5 CPU.'
[[ "$(docker inspect --format '{{.HostConfig.Memory}}' "$app_id")" == '268435456' ]] || fail 'App memory limit is not 256 MiB.'
[[ "$(docker inspect --format '{{.HostConfig.NanoCpus}}' "$postgres_id")" == '1000000000' ]] || fail 'PostgreSQL CPU limit is not 1 CPU.'
[[ "$(docker inspect --format '{{.HostConfig.Memory}}' "$postgres_id")" == '1073741824' ]] || fail 'PostgreSQL memory limit is not 1 GiB.'
[[ "$("${compose[@]}" exec --no-TTY app id -u)" != '0' ]] || fail 'The application container is running as root.'
[[ "$("${compose[@]}" exec --no-TTY postgres psql --username lelog --dbname lelog --tuples-only --no-align \
    --command "SELECT to_regclass('public.logs') IS NOT NULL
        AND (SELECT count(*) FROM migrations) = 3
        AND (SELECT reloptions @> ARRAY['autovacuum_analyze_scale_factor=0.40'] FROM pg_class WHERE oid = 'public.logs'::regclass)")" == 't' ]] \
    || fail 'Fresh migrations did not create the expected schema.'

request GET /health 200
assert_json '{"status":"ok"}'

timestamp="$(date --utc --date='1 minute ago' '+%Y-%m-%dT%H:%M:00Z')"
until="$(date --utc --date="$timestamp + 1 minute" '+%Y-%m-%dT%H:%M:00Z')"
payload="{\"logs\":[{\"timestamp\":\"$timestamp\",\"level\":\"info\",\"service\":\"checkout\",\"message\":\"paid first\",\"attributes\":{\"tenant\":\"one\"}},{\"timestamp\":\"$timestamp\",\"level\":\"fatal\",\"service\":\"checkout\",\"message\":\"invalid\"},{\"timestamp\":\"$timestamp\",\"level\":\"error\",\"service\":\"checkout\",\"message\":\"paid second\",\"attributes\":{\"tenant\":\"one\"}}]}"

request POST /logs 200 "$payload"
assert_json '{"accepted":2,"rejected":[{"index":1,"reason":"level must be one of debug, info, warn, or error"}]}'
[[ "$("${compose[@]}" exec --no-TTY postgres psql --username lelog --dbname lelog --tuples-only --no-align \
    --command 'SELECT count(*) FROM logs')" == '2' ]] || fail 'Accepted logs were not durable.'

request GET '/logs?service=checkout&attr.tenant=one&q=paid&limit=1' 200
cursor="$("${compose[@]}" exec --no-TTY app php -r '
    $body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (count($body["logs"] ?? []) !== 1 || !is_string($body["next_cursor"] ?? null)) {
        fwrite(STDERR, "First query page did not match the contract.".PHP_EOL);
        exit(1);
    }
    echo rawurlencode($body["next_cursor"]);
' < "$response_file")"
request GET "/logs?service=checkout&limit=1&cursor=$cursor" 200
"${compose[@]}" exec --no-TTY app php -r '
    $body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (count($body["logs"] ?? []) !== 1 || !array_key_exists("next_cursor", $body) || $body["next_cursor"] !== null) {
        fwrite(STDERR, "Second query page did not match the contract.".PHP_EOL);
        exit(1);
    }
' < "$response_file"

request GET "/logs/aggregate?since=$timestamp&until=$until&bucket=1m&group_by=level&service=checkout" 200
assert_json "{\"buckets\":[{\"bucket\":\"${timestamp%Z}.000000Z\",\"group\":\"error\",\"count\":1},{\"bucket\":\"${timestamp%Z}.000000Z\",\"group\":\"info\",\"count\":1}]}"

request POST /logs 400 '{'
assert_json '{"error":"Malformed JSON."}'
request GET '/logs?cursor=malformed' 400
request GET '/logs?service=checkout%27%20OR%201%3D1%20--' 200
assert_json '{"logs":[],"next_cursor":null}'
request GET "/logs/aggregate?since=$timestamp&until=$until&bucket=1m&group_by=service%2Cmessage" 400

"${compose[@]}" stop postgres
request GET /health 503
assert_json '{"status":"unhealthy"}'

echo 'Fresh-container contract smoke test passed.'
