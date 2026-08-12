import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

const phase = __ENV.BENCHMARK_PHASE;
const baseUrl = __ENV.BASE_URL || 'http://app:8080';
const runId = __ENV.RUN_ID;
const batchSize = Number(__ENV.BATCH_SIZE);
const seedLogs = Number(__ENV.SEED_LOGS);
const seedIterations = seedLogs / batchSize;
const mixedRate = Number(__ENV.MIXED_RATE);
const mixedDuration = Number(__ENV.MIXED_DURATION_SECONDS);
const queryRate = Number(__ENV.QUERY_RATE);
const aggregationRate = Number(__ENV.AGGREGATION_RATE);
const baseEpochMs = Number(__ENV.BASE_EPOCH_MS);
const summaryFile = __ENV.SUMMARY_FILE;
const plannedMixedBatches = mixedRate * mixedDuration;
const plannedQueries = queryRate * mixedDuration;
const plannedAggregations = aggregationRate * mixedDuration;

if (!['seed', 'mixed'].includes(phase) || !runId || !summaryFile) {
    throw new Error('BENCHMARK_PHASE, RUN_ID, and SUMMARY_FILE are required.');
}

const ingestionAttempted = new Counter('ingestion_attempted');
const ingestionSuccessful = new Counter('ingestion_successful');
const ingestionFailed = new Counter('ingestion_failed');
const acceptedLogs = new Counter('accepted_logs');
const rejectedLogs = new Counter('rejected_logs');
const ingestionSuccessRate = new Rate('ingestion_success_rate');
const querySuccessRate = new Rate('query_success_rate');
const aggregationSuccessRate = new Rate('aggregation_success_rate');
const visibilitySuccessRate = new Rate('visibility_success_rate');
const ingestionLatency = new Trend('ingestion_latency', true);
const queryLatency = new Trend('query_latency', true);
const aggregationLatency = new Trend('aggregation_latency', true);
const visibilityLag = new Trend('visibility_lag', true);
const jsonDecodeTiming = new Trend('app_json_decode', true);
const validationTransformTiming = new Trend('app_validation_transform', true);
const arrayEncodingTiming = new Trend('app_pg_array_encoding', true);
const connectionWaitTiming = new Trend('app_connection_wait', true);
const sqlExecutionTiming = new Trend('app_sql_execution', true);
const completeRequestTiming = new Trend('app_complete_request', true);

const commonThresholds = {
    checks: ['rate==1'],
    ingestion_success_rate: ['rate==1'],
    rejected_logs: ['count==0'],
};

export const options = phase === 'seed'
    ? {
        scenarios: {
            seed: {
                executor: 'shared-iterations',
                exec: 'seedBatch',
                vus: 4,
                iterations: seedIterations,
                maxDuration: '30m',
            },
        },
        thresholds: commonThresholds,
        summaryTrendStats: ['count', 'min', 'med', 'avg', 'p(90)', 'p(95)', 'max'],
    }
    : {
        scenarios: {
            ingest: {
                executor: 'constant-arrival-rate',
                exec: 'mixedBatch',
                rate: mixedRate,
                timeUnit: '1s',
                duration: `${mixedDuration}s`,
                preAllocatedVUs: Math.max(4, mixedRate),
                maxVUs: Math.max(16, mixedRate * 4),
            },
            filtered_queries: {
                executor: 'constant-arrival-rate',
                exec: 'filteredQuery',
                rate: queryRate,
                timeUnit: '1s',
                duration: `${mixedDuration}s`,
                preAllocatedVUs: Math.max(2, queryRate),
                maxVUs: Math.max(8, queryRate * 2),
            },
            aggregation: {
                executor: 'constant-arrival-rate',
                exec: 'aggregate',
                rate: aggregationRate,
                timeUnit: '1s',
                duration: `${mixedDuration}s`,
                preAllocatedVUs: 2,
                maxVUs: 4,
            },
        },
        thresholds: {
            ...commonThresholds,
            query_success_rate: ['rate==1'],
            aggregation_success_rate: ['rate==1'],
            visibility_success_rate: ['rate==1'],
            dropped_iterations: ['count==0'],
        },
        summaryTrendStats: ['count', 'min', 'med', 'avg', 'p(90)', 'p(95)', 'max'],
    };

const services = ['checkout', 'catalog', 'identity', 'payments'];
const levels = ['debug', 'info', 'warn', 'error'];

function timestampFor(sequence, isSeed) {
    if (!isSeed) {
        return new Date(baseEpochMs + Math.floor((sequence - seedLogs) / batchSize / mixedRate) * 1000).toISOString();
    }

    const span = 29 * 24 * 60 * 60 * 1000;
    return new Date(baseEpochMs - span + Math.floor((sequence / Math.max(seedLogs, 1)) * span)).toISOString();
}

function payload(firstSequence, isSeed) {
    const logs = new Array(batchSize);

    for (let offset = 0; offset < batchSize; offset += 1) {
        const sequence = firstSequence + offset;
        logs[offset] = {
            timestamp: timestampFor(sequence, isSeed),
            level: levels[sequence % levels.length],
            service: services[sequence % services.length],
            message: `benchmark ${runId} sequence ${sequence}`,
            attributes: {
                run_id: runId,
                benchmark_seq: sequence,
                phase: isSeed ? 'seed' : 'mixed',
            },
        };
    }

    return JSON.stringify({ logs });
}

function ingest(firstSequence, isSeed, verifyVisibility) {
    ingestionAttempted.add(1);
    const startedAt = Date.now();
    const response = http.post(`${baseUrl}/logs`, payload(firstSequence, isSeed), {
        headers: { 'Content-Type': 'application/json' },
        tags: { operation: 'ingest', phase },
        timeout: '30s',
    });
    ingestionLatency.add(response.timings.duration);
    recordApplicationTimings(response.headers['Server-Timing']);

    let body = null;
    try {
        body = response.json();
    } catch (_) {
        // The contract check below reports invalid JSON without hiding the response failure.
    }

    const accepted = Number(body && body.accepted);
    const rejected = Array.isArray(body && body.rejected) ? body.rejected.length : -1;
    const success = response.status === 200 && accepted === batchSize && rejected === 0;
    ingestionSuccessRate.add(success);
    acceptedLogs.add(Number.isFinite(accepted) ? accepted : 0);
    rejectedLogs.add(rejected > 0 ? rejected : 0);

    if (success) {
        ingestionSuccessful.add(1);
    } else {
        ingestionFailed.add(1);
    }

    check(response, {
        'ingestion durably accepts the exact batch': () => success,
    });

    if (success && verifyVisibility) {
        const sequence = firstSequence + batchSize - 1;
        const visibilityResponse = http.get(
            `${baseUrl}/logs?attr.benchmark_seq=${encodeURIComponent(String(sequence))}&attr.run_id=${encodeURIComponent(runId)}&limit=1`,
            { tags: { operation: 'visibility', phase }, timeout: '20s' },
        );
        let visible = false;

        try {
            visible = visibilityResponse.status === 200 && visibilityResponse.json('logs.0.attributes.benchmark_seq') === sequence;
        } catch (_) {
            visible = false;
        }

        visibilitySuccessRate.add(visible);
        visibilityLag.add(Date.now() - startedAt);
        check(visibilityResponse, { 'new log is queryable by exact sequence': () => visible });
    }
}

function recordApplicationTimings(header) {
    if (typeof header !== 'string') {
        return;
    }

    const metrics = {
        json_decode: jsonDecodeTiming,
        validation_transform: validationTransformTiming,
        pg_array_encoding: arrayEncodingTiming,
        connection_wait: connectionWaitTiming,
        sql_execution: sqlExecutionTiming,
        complete_request: completeRequestTiming,
    };

    for (const part of header.split(',')) {
        const match = part.trim().match(/^([a-z_]+);dur=([0-9.]+)$/);

        if (match && metrics[match[1]]) {
            metrics[match[1]].add(Number(match[2]));
        }
    }
}

export function seedBatch() {
    ingest(exec.scenario.iterationInTest * batchSize, true, false);
}

export function mixedBatch() {
    if (exec.scenario.iterationInTest >= plannedMixedBatches) {
        return;
    }

    ingest(seedLogs + exec.scenario.iterationInTest * batchSize, false, true);
}

export function filteredQuery() {
    if (exec.scenario.iterationInTest >= plannedQueries) {
        return;
    }

    const response = http.get(
        `${baseUrl}/logs?service=checkout&level=debug&attr.run_id=${encodeURIComponent(runId)}&q=benchmark&limit=100`,
        { tags: { operation: 'filtered_query', phase }, timeout: '20s' },
    );
    queryLatency.add(response.timings.duration);
    let success = response.status === 200;

    try {
        success = success && Array.isArray(response.json('logs'));
    } catch (_) {
        success = false;
    }

    querySuccessRate.add(success);
    check(response, { 'filtered query matches its contract': () => success });
}

export function aggregate() {
    if (exec.scenario.iterationInTest >= plannedAggregations) {
        return;
    }

    const since = encodeURIComponent(new Date(baseEpochMs - 24 * 60 * 60 * 1000).toISOString());
    const until = encodeURIComponent(new Date(baseEpochMs + 60 * 60 * 1000).toISOString());
    const response = http.get(
        `${baseUrl}/logs/aggregate?since=${since}&until=${until}&bucket=1h&group_by=service&attr.run_id=${encodeURIComponent(runId)}`,
        { tags: { operation: 'aggregation', phase }, timeout: '20s' },
    );
    aggregationLatency.add(response.timings.duration);
    let success = response.status === 200;

    try {
        success = success && Array.isArray(response.json('buckets'));
    } catch (_) {
        success = false;
    }

    aggregationSuccessRate.add(success);
    check(response, { 'aggregation matches its contract': () => success });
}

export function handleSummary(data) {
    return {
        [`/reports/${summaryFile}`]: JSON.stringify(data, null, 2),
        stdout: `${phase} benchmark complete: ${data.metrics.iterations.values.count} iterations\n`,
    };
}
