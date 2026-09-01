<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agreement (M9). `agreement_number` (AGR-000001) is unique + concurrency-safe.
 * The prepared / signed files are `documents` attached to the agreement (so
 * versioning + private storage are shared) — a signed agreement is never
 * overwritten. The agreement references the historical M6 booking terms; it
 * never alters the price snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->string('agreement_number', 20)->unique();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('type', 24)->default('booking_agreement'); // App\Enums\AgreementType
            $table->string('status', 16)->default('draft')->index(); // App\Enums\AgreementStatus

            // The BOOKING_AGREEMENT document slot on the booking (versioned files).
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->json('terms_snapshot')->nullable(); // frozen booking terms at prepare time

            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by')->nullable(); // customer / counter-party name
            $table->foreignId('signed_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
