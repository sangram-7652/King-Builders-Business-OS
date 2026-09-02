<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site inspection log (M10) — many per case; the latest row is the current
 * result. A FAILED / REINSPECTION_REQUIRED latest inspection blocks the final
 * possession handover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('possession_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('possession_case_id')->constrained('possession_cases')->cascadeOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('inspection_date');
            $table->string('status', 24)->default('pending'); // App\Enums\InspectionStatus
            $table->text('remarks')->nullable();

            // Optional SITE_INSPECTION_REPORT document slot on the booking.
            $table->foreignId('report_document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['possession_case_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('possession_inspections');
    }
};
