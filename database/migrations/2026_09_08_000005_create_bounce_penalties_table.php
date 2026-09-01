<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bounce penalty (M8) — assessment foundation only. A penalty is explicitly
 * assessed, authorised (ASSESSED → APPROVED) and fully traceable. It does NOT
 * post to the M7 booking financial total; accounting posting is a later
 * milestone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounce_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cheque_bounce_id')->constrained('cheque_bounces')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->decimal('penalty_amount', 15, 2);
            $table->string('reason');
            $table->string('status', 12)->default('assessed')->index(); // App\Enums\PenaltyStatus

            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounce_penalties');
    }
};
