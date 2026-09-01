<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 32)->nullable()->after('email');
            $table->string('status', 16)->default('active')->after('password')->index();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['mobile']);
            $table->dropIndex(['status']);
            $table->dropColumn(['mobile', 'status', 'last_login_at', 'last_login_ip']);
        });
    }
};
