<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M13.1 — turn the M5 follow-up row into a real task:
 *   title · type · priority · explicit status · its own assignee ·
 *   a rescheduled-from link so the chain is never lost.
 *
 * `due_at` (datetime), `note`, `outcome` (FollowUpOutcome) and `completed_at`
 * are kept as-is. Existing rows are migrated to a consistent state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_follow_ups', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('lead_id');
            $table->string('type', 20)->default('call')->after('title');          // App\Enums\FollowUpType
            $table->string('priority', 12)->default('normal')->after('type');     // App\Enums\FollowUpPriority
            $table->string('status', 16)->default('pending')->after('priority');  // App\Enums\FollowUpStatus
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->after('assigned_to')
                ->constrained('lead_follow_ups')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('completed_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();

            $table->index(['assigned_to', 'status', 'due_at']);
            $table->index(['status', 'due_at']);
        });

        // Migrate existing rows (portable — no UPDATE...JOIN):
        //  completed → 'completed', open & past-due → 'missed', else 'pending'.
        //  type / priority already got their column defaults.
        DB::table('lead_follow_ups')->whereNotNull('completed_at')->update(['status' => 'completed']);
        DB::table('lead_follow_ups')->whereNull('completed_at')->where('due_at', '<', now())->update(['status' => 'missed']);
        DB::table('lead_follow_ups')->whereNull('completed_at')->where('due_at', '>=', now())->update(['status' => 'pending']);

        DB::table('lead_follow_ups')->orderBy('id')->chunkById(500, function ($rows): void {
            $owners = DB::table('leads')->whereIn('id', $rows->pluck('lead_id'))->pluck('assigned_to', 'id');
            foreach ($rows as $row) {
                DB::table('lead_follow_ups')->where('id', $row->id)->update([
                    'assigned_to' => $owners[$row->lead_id] ?? null,
                    'completed_by' => $row->completed_at !== null ? ($owners[$row->lead_id] ?? null) : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('lead_follow_ups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('rescheduled_from_id');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropIndex(['assigned_to', 'status', 'due_at']);
            $table->dropIndex(['status', 'due_at']);
            $table->dropColumn(['title', 'type', 'priority', 'status', 'cancelled_at']);
        });
    }
};
