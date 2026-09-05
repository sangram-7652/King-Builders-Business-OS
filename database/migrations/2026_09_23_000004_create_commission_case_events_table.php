<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only commission case timeline (M14.4+). `updated_at` disabled; rows
 * written only via CommissionCase::recordEvent(). `properties` never holds PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_case_id')->constrained('commission_cases')->cascadeOnDelete();
            $table->string('type', 40)->index();      // App\Enums\CommissionCaseEventType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['commission_case_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_case_events');
    }
};
