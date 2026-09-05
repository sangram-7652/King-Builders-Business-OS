<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-M16-3 — record marketing consent / opt-out so the communication engine can
 * gate MARKETING-category messages. Transactional messages are unaffected.
 *
 * Minimal by design (not a marketing-automation system): a nullable
 * "consent given at" and "opted out at" per contactable party. Consent is
 * active when `marketing_consent_at` is set and `marketing_opt_out_at` is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['buyers', 'leads'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->timestamp('marketing_consent_at')->nullable()->after('email');
                $t->timestamp('marketing_opt_out_at')->nullable()->after('marketing_consent_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['buyers', 'leads'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn(['marketing_consent_at', 'marketing_opt_out_at']);
            });
        }
    }
};
