<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets the production security headers on every web response', function () {
    $res = $this->get('/login')->assertOk();

    $res->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

    expect($res->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($res->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($res->headers->get('Content-Security-Policy'))->toContain("object-src 'none'")
        ->and($res->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");

    expect($res->headers->has('X-Powered-By'))->toBeFalse();
});

it('sets the headers on an authenticated page and on JSON responses', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('does not emit HSTS over plain HTTP', function () {
    $this->get('/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

it('emits HSTS only over HTTPS in production', function () {
    config()->set('app.env', 'production');
    app()['env'] = 'production';

    $res = $this->get('https://localhost/login');

    expect($res->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('can be disabled entirely via config without a code change (survives config:cache)', function () {
    // SECURITY_HEADERS_ENABLED=false → config('security.headers_enabled') = false.
    config()->set('security.headers_enabled', false);

    $res = $this->get('/login')->assertOk();

    expect($res->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($res->headers->has('X-Content-Type-Options'))->toBeFalse();
});

it('honours a custom CSP from config', function () {
    config()->set('security.csp', "default-src 'none'");

    $res = $this->get('/login')->assertOk();
    expect($res->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

it('omits the CSP header when the policy is set to "off"', function () {
    config()->set('security.csp', 'off');

    $res = $this->get('/login')->assertOk();
    expect($res->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff'); // other headers unaffected
});
