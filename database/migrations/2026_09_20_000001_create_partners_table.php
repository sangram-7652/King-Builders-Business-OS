<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel partner / broker master (M14). `partner_code` (PTNR-000001) is unique
 * and concurrency-safe. PAN and bank account number are stored encrypted and
 * never serialised. Payout bank details here are operational only — M14 is NOT
 * an accounting ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('partner_code', 20)->unique();
            $table->string('type', 24)->index();               // App\Enums\PartnerType
            $table->string('status', 24)->default('draft')->index(); // App\Enums\PartnerStatus

            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone', 20);
            $table->string('alternate_phone', 20)->nullable();
            $table->string('email')->nullable();

            $table->string('address')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('pincode', 12)->nullable();

            $table->text('pan_number')->nullable();             // encrypted
            $table->string('rera_number')->nullable();          // broker RERA registration id

            // Operational payout details (NOT accounting).
            $table->string('bank_account_name')->nullable();
            $table->text('bank_account_number')->nullable();    // encrypted
            $table->string('bank_ifsc', 20)->nullable();
            $table->string('bank_name')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
