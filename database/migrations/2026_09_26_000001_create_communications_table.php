<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One outbound communication and its delivery lifecycle (M16). `body` is the
 * rendered snapshot — immutable once written, so editing a template never
 * changes historical messages. `idempotency_key` is unique: the same logical
 * event never produces two messages. `provider_message_id` matches inbound
 * webhooks (M16.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('channel', 12);                    // App\Enums\CommunicationChannel
            $table->string('category', 16)->default('transactional'); // App\Enums\CommunicationCategory
            $table->string('status', 12)->default('pending')->index();  // App\Enums\CommunicationStatus

            $table->string('to_address');                     // email or E.164 phone
            $table->string('subject')->nullable();
            $table->text('body');                             // rendered snapshot — never mutated
            $table->json('context')->nullable();             // rendered variable snapshot for audit

            $table->string('event_key', 60)->nullable();      // e.g. payment.received
            $table->nullableMorphs('subject');               // the domain record it concerns
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'created_at']);
            $table->index(['channel', 'status']);
            $table->index(['event_key', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
