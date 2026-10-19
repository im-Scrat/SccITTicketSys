<?php

use Illuminate\Support\Facades\DB;

it('exposes the infrastructure health endpoint with the expected shape', function () {
    $this->getJson('/api/health')
        ->assertJsonStructure([
            'status',
            'checks' => ['app', 'database', 'redis'],
        ]);
});

it('reports the database connection as ok during tests', function () {
    // RefreshDatabase migrates the dedicated test database, so the PDO
    // connection used by the health endpoint must succeed.
    $this->getJson('/api/health')
        ->assertJsonPath('checks.database', 'ok');
});

it('does not disclose the framework version to an anonymous caller', function () {
    $this->getJson('/api/health')->assertJsonMissingPath('laravel');
});

it('reports a failed dependency without the reason, which can name hosts and users', function () {
    config(['database.connections.health_probe' => [
        'driver' => 'pgsql',
        'host' => 'db-host-that-must-not-leak.invalid',
        'port' => 5432,
        'database' => 'secret_db_name',
        'username' => 'secret_db_user',
        'password' => 'x',
    ]]);
    DB::setDefaultConnection('health_probe');

    try {
        $response = $this->getJson('/api/health');
    } finally {
        DB::setDefaultConnection('pgsql');
    }

    $response->assertStatus(503)->assertJsonPath('checks.database', 'error');

    expect($response->getContent())
        ->not->toContain('db-host-that-must-not-leak')
        ->not->toContain('secret_db_user')
        ->not->toContain('secret_db_name');
});

it('does not check Redis when no driver uses it', function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'broadcasting.default' => 'null',
    ]);

    $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.redis', 'not_used');
});
