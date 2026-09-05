<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for report exports (M11.5).
 *
 * The same lightweight activity pattern as `lead_activities` /
 * `collection_activities` — not a second audit system. One row per export
 * download (`report.exported`): who, which report, which format, the applied
 * filters and when. `filters` holds only the resolved filter ids / enum values
 * — never customer or payment data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_type', 32);   // App\Enums\ReportType
            $table->string('format', 16);        // App\Enums\ExportFormat
            $table->json('filters')->nullable(); // resolved filter query string — no PII
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['report_type', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
