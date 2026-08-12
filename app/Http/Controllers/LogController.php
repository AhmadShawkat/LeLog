<?php

namespace App\Http\Controllers;

use App\Domain\Logs\InvalidLogBatch;
use App\Domain\Logs\Services\IngestLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogController extends Controller
{
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
