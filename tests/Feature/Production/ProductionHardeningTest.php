<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
//  Guest gating — every authenticated area sends a guest to /login (never 200/500)
// ---------------------------------------------------------------------------

dataset('authenticated GET routes', [
    '/dashboard', '/projects', '/projects/create',
    '/buyers', '/buyers/create', '/bookings', '/bookings/create',
    '/payments', '/finance',
    '/documents', '/registry', '/possession', '/transfers',
    '/users', '/users/create', '/roles', '/roles/create', '/settings/masters',
    '/reports', '/reports/sales', '/reports/inventory',
    '/reports/mis',
]);

it('redirects a guest from every authenticated area to the login screen', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with('authenticated GET routes');

it('redirects a guest from every report export to the login screen', function () {
    foreach (['sales', 'inventory', 'mis'] as $type) {
        foreach (['csv', 'xlsx', 'pdf', 'print'] as $format) {
            $this->get("/reports/{$type}/export/{$format}")->assertRedirect('/login');
        }
    }
});

it('never serves a stored document to an anonymous caller', function () {
    $this->get('/documents/1/versions/1/download')->assertRedirect('/login');
});

// ---------------------------------------------------------------------------
//  IDOR — a logged-in user without the right permission is blocked
// ---------------------------------------------------------------------------

it('blocks a permission-less user from every module index (403)', function () {
    $nobody = makeUser(); // authenticated, zero permissions

    foreach (['/projects', '/buyers', '/bookings', '/payments',
        '/documents', '/registry', '/possession', '/transfers',
        '/users', '/roles', '/reports', '/settings/masters'] as $path) {
        $this->actingAs($nobody)->get($path)->assertForbidden();
    }
});

it('a document download is authorised per-document, not per-id (IDOR)', function () {
    $viewer = makeUser(permissions: ['documents.view', 'documents.download']);

    // a document the viewer is NOT entitled to (no access to its buyer/booking)
    $buyer = Buyer::factory()->create();
    $doc = Document::factory()->forDocumentable($buyer)->create();
    $version = DocumentVersion::factory()->for($doc)->create();

    $this->actingAs($viewer)
        ->get("/documents/{$doc->id}/versions/{$version->id}/download")
        ->assertForbidden();
})->skip(fn () => ! class_exists(DocumentVersion::class) || ! method_exists(Document::factory(), 'forDocumentable'));

// ---------------------------------------------------------------------------
//  Exception handling — no internals leak; DomainException keeps its status
// ---------------------------------------------------------------------------

it('renders a DomainException as its declared status for JSON clients', function () {
    Route::middleware('web')->get('/__t/domain', function () {
        throw new DomainException('plot is already sold', 409);
    });

    $this->getJson('/__t/domain')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'plot is already sold']);
});

it('does not leak stack traces or paths on a 404', function () {
    config()->set('app.debug', false);

    $res = $this->get('/no-such-page-'.uniqid());
    $res->assertNotFound();

    expect($res->getContent())
        ->not->toContain(base_path())
        ->not->toContain('vendor/laravel')
        ->not->toContain('Stack trace');
});

// ---------------------------------------------------------------------------
//  Trusted proxies — the app honours X-Forwarded-Proto behind a TLS terminator
// ---------------------------------------------------------------------------

it('treats a request as secure when the proxy says X-Forwarded-Proto: https', function () {
    Route::middleware('web')->get('/__t/scheme', fn () => [
        'secure' => request()->secure(),
        'url' => url('/x'),
    ]);

    // plain HTTP → not secure
    $this->getJson('/__t/scheme')->assertJsonPath('secure', false);

    // behind the TLS-terminating proxy → secure, and url() switches to https
    $res = $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '203.0.113.9',
    ])->getJson('/__t/scheme');

    $res->assertJsonPath('secure', true);
    expect($res->json('url'))->toStartWith('https://');
});

// ---------------------------------------------------------------------------
//  Code hygiene — no debug helpers shipped
// ---------------------------------------------------------------------------

it('ships no dd / dump / var_dump / ray in application code', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = $file->getContents();
        if (preg_match('/(?<![\w:>$\-])(dd|dump|var_dump|print_r|ray)\s*\(/', $src, $m)) {
            // print_r(..., true) is a legitimate string builder — allow it
            if ($m[1] === 'print_r' && preg_match('/print_r\([^;]*,\s*true\s*\)/', $src)) {
                continue;
            }
            $offenders[] = $file->getRelativePathname().' → '.$m[1].'(';
        }
    }

    expect($offenders)->toBe([]);
});

it('has no hardcoded APP_KEY or obvious secret literals in application code', function () {
    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        expect($file->getContents())
            ->not->toMatch('/base64:[A-Za-z0-9+\/]{40,}/')                       // an APP_KEY
            // a literal password value that is NOT a lowercase cast keyword ('hashed' etc.)
            ->not->toMatch('/[\'"]password[\'"]\s*=>\s*[\'"](?![a-z]+[\'"])[^\'"\n]{8,}[\'"]/i');
    }
});
