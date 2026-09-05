<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-M16-1 — provider delivery webhooks. Every accepted event is recorded here
 * with a UNIQUE `(channel, event_id)` so a provider that re-sends the same
 * event (at-least-once delivery) can never apply the state change twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 12);
            $table->string('event_id', 191);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('event_type', 32);
            $table->foreignId('communication_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['channel', 'event_id'], 'communication_webhook_events_channel_event_unique');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_webhook_events');
    }
};
