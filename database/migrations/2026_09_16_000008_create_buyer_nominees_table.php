<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer nominee foundation (M10). A nominee is NOT an owner and never appears in
 * `plot_ownership_history`. A "nominee change" supersedes the current active
 * row (append-only) rather than editing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_nominees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('name');
            $table->string('relation', 40)->nullable();
            $table->string('phone', 20)->nullable();
            $table->decimal('share_percentage', 5, 2)->nullable();
            $table->string('status', 12)->default('active')->index(); // active | superseded

            $table->foreignId('id_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('superseded_by')->nullable()->constrained('buyer_nominees')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_nominees');
    }
};
