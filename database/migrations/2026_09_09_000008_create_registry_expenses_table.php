<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry expense tracking (M9) — DECIMAL only, never float. This is a simple
 * log of what was spent on a registry case (stamp duty, fees, …). It is NOT an
 * accounting ledger and is never posted to the M6/M7 booking financials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_case_id')->constrained('registry_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('expense_type', 24); // App\Enums\RegistryExpenseType
            $table->decimal('amount', 15, 2);
            $table->string('status', 12)->default('recorded')->index(); // recorded | approved | cancelled
            $table->string('paid_by')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['registry_case_id', 'expense_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_expenses');
    }
};
