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
        try {
            $result = $ingestLogs->ingest($request->getContent());
        } catch (InvalidLogBatch $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        return response()->json($result, $result['accepted'] > 0 ? 200 : 400);
    }
}
