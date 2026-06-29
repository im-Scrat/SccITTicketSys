<?php

it('exposes the infrastructure health endpoint with the expected shape', function () {
    $this->getJson('/api/health')
        ->assertJsonStructure([
            'status',
            'checks' => ['app', 'database', 'redis'],
            'laravel',
        ]);
});

it('reports the database connection as ok during tests', function () {
    // RefreshDatabase migrates the dedicated test database, so the PDO
    // connection used by the health endpoint must succeed.
    $this->getJson('/api/health')
        ->assertJsonPath('checks.database', 'ok');
});
