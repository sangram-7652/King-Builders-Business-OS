<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use, expiring tokens for portal activation and password reset (M15).
 * The token is stored HASHED (never in plaintext); the plaintext is only ever
 * returned once, in the activation link handed to staff to share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('purpose', 12);           // invite | reset
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'purpose', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invitations');
    }
};
