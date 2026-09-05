<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only partner activity timeline (M14). `updated_at` is disabled; rows
 * are written only through Partner::recordActivity(). `properties` never holds
 * PII (no PAN, no bank account number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('type', 40)->index();          // App\Enums\PartnerActivityType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['partner_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_activities');
    }
};
