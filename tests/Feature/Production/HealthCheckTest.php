<?php

declare(strict_types=1);

use App\Support\Health\HealthProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('serves the framework liveness route', function () {
    $this->get('/up')->assertOk();
});

it('reports a healthy readiness probe with all components ok', function () {
    $res = $this->getJson('/healthz')->assertOk();

    expect($res->json('status'))->toBe('ok')
        ->and($res->json('checks'))->toBe([
            'database' => 'ok',
            'cache' => 'ok',
            'storage' => 'ok',
        ]);
});

it('starts no session and sets no cookie on the readiness probe', function () {
    $res = $this->get('/healthz')->assertOk();

    expect($res->headers->getCookies())->toBe([]);
});

it('returns 503 and names only the failing component when a dependency is down', function () {
    // simulate the DB being unreachable
    DB::shouldReceive('connection->select')->andThrow(new RuntimeException('boom'));

    $res = $this->getJson('/healthz')->assertStatus(503);

    expect($res->json('status'))->toBe('degraded')
        ->and($res->json('checks.database'))->toBe('fail');

    // never leaks the driver, host, credentials or the exception message
    $body = $res->getContent();
    expect($body)->not->toContain('boom')
        ->not->toContain('mysql')
        ->not->toContain('sqlite')
        ->not->toContain(base_path());
});

it('the health:check command exits 0 when healthy', function () {
    $this->artisan('health:check')
        ->expectsOutputToContain('ok')
        ->assertExitCode(0);
});

it('the health:check command exits 1 when a dependency fails', function () {
    DB::shouldReceive('connection->select')->andThrow(new RuntimeException('down'));

    $this->artisan('health:check')->assertExitCode(1);
});

it('HealthProbe returns only ok/fail values', function () {
    $result = app(HealthProbe::class)->run();

    expect(array_keys($result))->toBe(['database', 'cache', 'storage'])
        ->and(array_values(array_unique($result)))->each->toBeIn(['ok', 'fail']);
});
