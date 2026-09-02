<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Possession clearances (M10) — one row per (case, category). PENDING → CLEARED
 * / REJECTED / WAIVED. Nothing is ever waived automatically. Financial
 * clearance reads M7/M8 truth; it never recomputes a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('possession_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('possession_case_id')->constrained('possession_cases')->cascadeOnDelete();
            $table->string('category', 16); // App\Enums\ClearanceCategory
            $table->string('status', 12)->default('pending')->index(); // App\Enums\ClearanceStatus
            $table->boolean('required')->default(true);

            $table->string('remarks')->nullable();
            $table->string('waiver_reason')->nullable();
            $table->json('snapshot')->nullable(); // e.g. outstanding at clearance time
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['possession_case_id', 'category'], 'possession_clearances_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('possession_clearances');
    }
};
