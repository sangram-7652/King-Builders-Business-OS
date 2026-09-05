<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a buyer able to sign in to the self-service customer portal (M15).
 * The buyer IS the customer — no separate customers table. `password` is null
 * until the buyer activates; only `portal_status = active` may authenticate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('aadhaar_number');
            $table->rememberToken();
            $table->string('portal_status', 16)->default('none')->after('status')->index();
            $table->timestamp('portal_invited_at')->nullable();
            $table->timestamp('portal_activated_at')->nullable();
            $table->timestamp('portal_last_login_at')->nullable();
            $table->string('portal_last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn([
                'password', 'remember_token', 'portal_status',
                'portal_invited_at', 'portal_activated_at',
                'portal_last_login_at', 'portal_last_login_ip',
            ]);
        });
    }
};
