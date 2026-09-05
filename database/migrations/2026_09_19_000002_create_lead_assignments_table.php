<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M13.1 — append-only lead assignment history.
 *
 * `leads.assigned_to` remains the current owner (fast to query). This table is
 * the permanent record: one row per assignment span, closed with `ended_at`
 * when the lead is reassigned or unassigned. Nothing ever updates a row's
 * `assigned_to` / `assigned_by` / `assigned_at` — history is immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();          // null = current span
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'assigned_at']);
            $table->index(['assigned_to', 'ended_at']);
        });

        // Seed one open span per already-assigned lead so history is complete.
        $rows = DB::table('leads')->whereNotNull('assigned_to')->get(['id', 'assigned_to', 'created_by', 'created_at']);
        foreach ($rows as $lead) {
            DB::table('lead_assignments')->insert([
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to,
                'assigned_by' => $lead->created_by,
                'assigned_at' => $lead->created_at ?? now(),
                'ended_at' => null,
                'reason' => 'backfilled from leads.assigned_to (M13.1)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');
    }
};
