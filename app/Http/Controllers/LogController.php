<?php

namespace App\Http\Controllers;

use App\Domain\Logs\InvalidLogBatch;
use App\Domain\Logs\InvalidLogQuery;
use App\Domain\Logs\Services\AggregateLogs;
use App\Domain\Logs\Services\IngestLogs;
use App\Domain\Logs\Services\QueryLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogController extends Controller
{
    public function aggregate(Request $request, AggregateLogs $aggregateLogs): JsonResponse
    {
        $queryString = $request->server->get('QUERY_STRING', '');

        try {
            return response()->json($aggregateLogs->aggregate(is_string($queryString) ? $queryString : ''));
        } catch (InvalidLogQuery $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function index(Request $request, QueryLogs $queryLogs): JsonResponse
    {
        $queryString = $request->server->get('QUERY_STRING', '');

        try {
            return response()->json($queryLogs->query(is_string($queryString) ? $queryString : ''));
        } catch (InvalidLogQuery $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function store(Request $request, IngestLogs $ingestLogs): JsonResponse
    {
        $requestStarted = hrtime(true);

        try {
            $result = $ingestLogs->ingest($request->getContent());
        } catch (InvalidLogBatch $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        $timings = $result['timings'];
        $response = new JsonResponse(
            ['accepted' => $result['accepted'], 'rejected' => $result['rejected']],
            $result['accepted'] > 0 ? 200 : 400,
        );
        $completeMilliseconds = (hrtime(true) - $requestStarted) / 1_000_000;
        $response->headers->set('Server-Timing', sprintf(
            'json_decode;dur=%.3f, validation_transform;dur=%.3f, pg_array_encoding;dur=%.3f, connection_wait;dur=%.3f, sql_execution;dur=%.3f, complete_request;dur=%.3f',
            $timings['json_decode_ms'],
            $timings['validation_transform_ms'],
            $timings['array_encoding_ms'],
            $timings['connection_wait_ms'],
            $timings['sql_execution_ms'],
            $completeMilliseconds,
        ));

        return $response;
    }
}
