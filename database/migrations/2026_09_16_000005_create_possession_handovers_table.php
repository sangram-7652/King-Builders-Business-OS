<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical possession handover to the customer (M10) — one per possession case
 * (unique). READY_FOR_HANDOVER → HANDOVER → ACKNOWLEDGEMENT → COMPLETED. A
 * completed handover is terminal and never duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('possession_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('possession_case_id')->unique()->constrained('possession_cases')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('status', 24)->default('ready_for_handover')->index(); // App\Enums\PossessionHandoverStatus

            $table->date('handover_date')->nullable();
            $table->string('received_by')->nullable();       // person who took possession
            $table->string('receiver_identity')->nullable(); // ID reference recorded at handover
            $table->string('receiver_relation')->nullable(); // self / spouse / attorney / …
            $table->text('remarks')->nullable();

            // The POSSESSION_ACK document slot on the booking (versioned).
            $table->foreignId('acknowledgement_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('possession_handovers');
    }
};
