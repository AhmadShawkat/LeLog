<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ExceptionResponseTest extends TestCase
{
    public function test_api_exceptions_return_a_safe_stable_json_error(): void
    {
        Route::get('/api/test/failure', static function (): never {
            throw new RuntimeException('sensitive internal failure');
        });

        $response = $this->getJson('/api/test/failure');

        $response
            ->assertInternalServerError()
            ->assertExactJson(['error' => 'Internal server error.'])
            ->assertDontSee('sensitive internal failure');
    }

    public function test_missing_api_routes_use_the_same_error_contract(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['error' => 'Not found.']);
    }
}
