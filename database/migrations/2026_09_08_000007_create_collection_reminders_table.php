<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal collection reminders (M8) — an in-app foundation only, no WhatsApp /
 * SMS / Email. Regenerated idempotently by a scheduled job; the composite
 * unique key means the same reminder is never created twice for the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('collection_case_id')->nullable()->constrained('collection_cases')->nullOnDelete();
            $table->string('type', 24); // App\Enums\CollectionReminderType
            $table->string('reference_type', 32)->nullable(); // installment | promise | payment
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->date('remind_on');
            $table->string('message');
            $table->string('status', 12)->default('pending')->index(); // pending | seen | dismissed
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'type', 'reference_type', 'reference_id', 'remind_on'], 'collection_reminders_dedupe');
            $table->index(['status', 'remind_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_reminders');
    }
};
