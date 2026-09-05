<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only lead attribution history (M14.2). One open span at a time
 * (`ended_at IS NULL` = current). A span with `partner_id IS NULL` records an
 * explicit "direct / no partner" decision. Mirrors the M13.1 `lead_assignments`
 * shape — attribution is never destroyed, only superseded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_partner_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('attributed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attributed_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('source', 40)->nullable();  // manual | conversion | campaign
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'attributed_at']);
            $table->index(['partner_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_partner_attributions');
    }
};
