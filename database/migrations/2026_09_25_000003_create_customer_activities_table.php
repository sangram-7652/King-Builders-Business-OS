<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only customer portal audit trail (M15). `updated_at` disabled; rows
 * written only via Buyer::recordPortalActivity(). `properties` never holds
 * sensitive data (no KYC numbers, no internal notes, no commission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('type', 40)->index();       // App\Enums\CustomerActivityType
            $table->string('description');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['buyer_id', 'id']);
            $table->index(['buyer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activities');
    }
};
