<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Persists a small set of branding values back to `.env` — the ACTUAL
 * backing store `config/branding.php` reads via `env()`. There is no
 * database-backed settings table for branding (`Permission::SettingsView`/
 * `SettingsUpdate` are an M1 placeholder, never implemented), so `.env` is
 * the only writable "existing configuration source" for a value like the
 * Plot KYC Receipt's seller Director Name / PAN.
 *
 * Writes only the exact key(s) given — every other line in the file is left
 * byte-for-byte untouched, and a key with no existing line is appended
 * rather than guessed at. The target path defaults to the real `.env`
 * (`base_path('.env')`) but is constructor-overridable so tests can point it
 * at a throwaway file and never touch the project's real one.
 */
final class BrandingConfigWriter
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('.env');
    }

    /**
     * @param  array<string, string>  $values  keyed by literal .env variable name
     */
    public function update(array $values): void
    {
        if ($values === []) {
            return;
        }

        if (! is_file($this->path) || ! is_writable($this->path)) {
            throw new RuntimeException("Cannot write branding config — {$this->path} is missing or not writable.");
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$this->path}.");
        }

        foreach ($values as $key => $value) {
            $contents = $this->setLine($contents, $key, $value);
        }

        if (file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException("Could not write {$this->path}.");
        }
    }

    private function setLine(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$this->quote($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            // preg_replace_callback (not preg_replace) so a literal backslash
            // in the quoted value ($line) is never reinterpreted as a
            // replacement-string backreference.
            return (string) preg_replace_callback($pattern, fn () => $line, $contents, 1);
        }

        return rtrim($contents, "\n")."\n".$line."\n";
    }

    /**
     * Quotes a value only when it needs it (spaces, #, quotes, backslashes,
     * `$`) — matches typical .env conventions. `$` is ALWAYS escaped when
     * quoting: phpdotenv interpolates `${OTHER_VAR}` inside a double-quoted
     * value, so an unescaped `$` here would let operator-entered text (e.g.
     * a Plot KYC Receipt Director Name) pull the value of an unrelated
     * secret env var (APP_KEY, DB_PASSWORD, …) into `.env` — which then
     * renders straight into a customer-facing PDF the next time it loads.
     */
    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\$]/', $value) === 1) {
            return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        }

        return $value;
    }
}
