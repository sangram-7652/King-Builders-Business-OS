<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects / Sites (M3) — the top of the business hierarchy
 * (Project → Block → Plot → …). Plot inventory is M4.
 *
 * Geography reuses the M2 `states` / `cities` masters — no new geo tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('status', 16)->default('planning')->index(); // App\Enums\ProjectStatus
            $table->boolean('is_active')->default(true)->index();

            // Location — states / cities are M2 masters (soft-deletable → restrict).
            $table->string('address')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('pincode', 12)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Primary contact for the site.
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();

            // Imagery — stored as paths; upload UI is a later milestone.
            $table->string('logo_path')->nullable();
            $table->string('cover_image_path')->nullable();

            $table->date('launch_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
