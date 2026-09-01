<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-key monotonic counters for concurrency-safe human-facing codes
 * (customer codes now; booking numbers etc. later). A row is locked
 * `FOR UPDATE` while its value is read and bumped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
