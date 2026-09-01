<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance master data (M2): tax/interest rules, payment configuration and banks.
 * Installment / payment engines are later milestones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tds_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('percentage', 5, 2);
            $table->date('applicable_from');
            $table->date('applicable_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('applicable_from');
        });

        Schema::create('interest_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('interest_type', 16); // App\Enums\Masters\InterestType
            $table->decimal('rate', 8, 4); // annualised %, e.g. 12.5000
            $table->string('frequency', 16); // App\Enums\Masters\InterestFrequency
            $table->unsignedInteger('grace_period_days')->default(0);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('effective_from');
        });

        Schema::create('payment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_modes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->unique();
            $table->string('description')->nullable();
            $table->boolean('requires_reference')->default(false); // cheque no / UTR / txn id
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bank_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->string('ifsc', 11)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('ifsc');
            $table->unique(['bank_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_branches');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('payment_modes');
        Schema::dropIfExists('payment_types');
        Schema::dropIfExists('interest_rules');
        Schema::dropIfExists('tds_rules');
    }
};
