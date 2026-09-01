<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyers / Customers (M5) — the entity between a Lead and a Booking.
 *
 * Contact details are deliberately NOT unique: households legitimately share a
 * phone / email. `customer_code` is the only unique human key (BUY-000001…),
 * generated concurrency-safely from `code_sequences`.
 *
 * PAN / Aadhaar are stored ENCRYPTED at rest (`encrypted` cast on the model) —
 * the columns are `text`, cannot be searched or indexed, and are masked in the
 * UI unless the viewer holds `buyers.documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 24)->unique();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();

            $table->string('phone', 20)->index();
            $table->string('alternate_phone', 20)->nullable();
            $table->string('email')->nullable()->index();

            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('occupation')->nullable();

            $table->string('address')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('pincode', 12)->nullable();

            // Encrypted at rest — see the model's casts().
            $table->text('pan_number')->nullable();
            $table->text('aadhaar_number')->nullable();

            $table->string('status', 16)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
