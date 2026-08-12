<?php

namespace Tests\Feature;

use Tests\TestCase;

final class FoundationEndpointTest extends TestCase
{
    public function test_the_foundation_route_returns_json(): void
    {
        $this->getJson('/api/foundation')->assertExactJson([
            'service' => 'lelog',
            'status' => 'ready-for-development',
        ]);
    }
}
