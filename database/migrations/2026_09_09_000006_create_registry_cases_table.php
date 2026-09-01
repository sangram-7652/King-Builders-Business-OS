<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry case (M9) — one per booking (unique). `case_number` (REG-000001) is
 * unique + concurrency-safe. The case CONSUMES M6/M7/M8 state via the
 * eligibility engine; it never modifies booking pricing and is not a financial
 * record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 20)->unique();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->string('status', 24)->default('not_started')->index(); // App\Enums\RegistryCaseStatus

            $table->json('eligibility_snapshot')->nullable(); // last eligibility evaluation
            $table->timestamp('eligibility_checked_at')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->string('registry_office')->nullable();
            $table->string('appointment_reference')->nullable();
            $table->foreignId('appointment_owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('registered_document_number')->nullable();
            $table->date('registration_date')->nullable();

            $table->string('hold_reason')->nullable();
            $table->string('status_before_hold', 24)->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_cases');
    }
};
