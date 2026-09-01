<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collection case (M8) — one per booking, the unit of work that gets an owner
 * and a priority. It carries NO money columns: every balance is read live from
 * the M7 ledger. `next_follow_up_at` / `last_follow_up_at` are denormalised
 * copies for the queue view, kept in sync by the follow-up actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open')->index(); // App\Enums\CollectionCaseStatus
            $table->string('priority', 12)->default('low')->index(); // App\Enums\CollectionPriority
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_follow_up_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_cases');
    }
};
