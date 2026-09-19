<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decommissions the Leads and Follow-ups modules (M5 / M13.1) — a client
 * product decision: this CRM no longer tracks pre-buyer leads or their
 * follow-up queue. Buyers, Bookings, Payments, Collections and every other
 * M0-M17 module are untouched; a Buyer is now always created directly.
 *
 * Drop order respects foreign keys: the cross-module `communications.lead_id`
 * column is dropped first, then every table that references `leads` (its
 * children), then `leads` itself, then its own `lead_sources` master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
        });

        Schema::dropIfExists('lead_follow_ups');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('lead_partner_attributions');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_sources');
    }

    public function down(): void
    {
        Schema::create('lead_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->nullable()->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leads', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('phone', 20)->index();
            $table->string('email')->nullable()->index();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->timestamp('marketing_opt_out_at')->nullable();

            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();

            $table->string('status', 24)->default('new')->index();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('next_action')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('follow_up_at')->nullable()->index();

            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'assigned_to']);
            $table->index('next_action_at');
            $table->index('last_activity_at');
            $table->index('partner_id');
            $table->index('created_at');
        });

        Schema::create('lead_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->string('type', 20)->default('call');
            $table->string('priority', 12)->default('normal');
            $table->string('status', 16)->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('lead_follow_ups')->nullOnDelete();

            $table->timestamp('due_at');
            $table->text('note')->nullable();

            $table->string('outcome', 24)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['lead_id', 'completed_at']);
            $table->index('due_at');
            $table->index(['assigned_to', 'status', 'due_at']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('lead_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('description');
            $table->json('properties')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['lead_id', 'id']);
        });

        Schema::create('lead_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'assigned_at']);
            $table->index(['assigned_to', 'ended_at']);
        });

        Schema::create('lead_partner_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('attributed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attributed_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('source', 40)->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'attributed_at']);
            $table->index(['partner_id', 'ended_at']);
        });

        Schema::table('communications', function (Blueprint $table): void {
            $table->foreignId('lead_id')->nullable()->after('buyer_id')->constrained('leads')->nullOnDelete();
        });
    }
};
