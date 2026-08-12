<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ApiExceptionResponse
{
    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*', 'logs', 'logs/*') || $response->getStatusCode() < 400) {
            return $response;
        }

        $status = $response->getStatusCode();

        return new JsonResponse(
            ['error' => $this->messageFor($status)],
            $status,
            $response->headers->all(),
        );
    }

    private function messageFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            409 => 'Conflict.',
            422 => 'Validation failed.',
            429 => 'Too many requests.',
            503 => 'Service unavailable.',
            default => $status >= 500 ? 'Internal server error.' : 'Request failed.',
        };
    }
}
