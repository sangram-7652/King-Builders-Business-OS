<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * F-M12-1 / F-M12-3 / S-4 — `php artisan config:cache` must be safe. It only is
 * if no application runtime code calls env() (env() returns null once config is
 * cached because the .env file is no longer loaded at boot).
 */
it('has no runtime env() calls anywhere under app/', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $n => $line) {
            // Ignore comments / docblocks referencing env().
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/(?<![A-Za-z_])env\s*\(/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Runtime env() call(s) found — move the setting to a config file: '.implode(', ', $offenders));
});

it('exposes the security settings through config, not env', function () {
    expect(config('security.headers_enabled'))->toBeBool()
        ->and(config('security.csp'))->toBeString()
        ->and(config('security.hsts'))->toContain('max-age');
});

it('the security-headers middleware reads the CSP from config', function () {
    config()->set('security.csp', "default-src 'self'; script-src 'self'");

    $res = $this->get('/login')->assertOk();

    expect($res->headers->get('Content-Security-Policy'))->toBe("default-src 'self'; script-src 'self'");
});
