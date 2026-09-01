<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document handover (M9) — one per booking (unique), created automatically when
 * the registry case completes. A completed handover is terminal; correcting one
 * needs the dedicated reversal ability. The acknowledgement file is a `document`
 * attached to the handover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('registry_case_id')->constrained('registry_cases')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete(); // HANDOVER_ACK slot
            $table->string('status', 24)->default('registry_completed')->index(); // App\Enums\HandoverStatus

            $table->timestamp('scheduled_at')->nullable();
            $table->date('handover_date')->nullable();
            $table->string('received_by')->nullable(); // person who physically received
            $table->text('handover_notes')->nullable();

            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_handovers');
    }
};
