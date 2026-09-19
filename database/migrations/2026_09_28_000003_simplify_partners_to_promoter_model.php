<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simplifies the M14 channel-partner model to a flat PROMOTER model:
 *
 *   - one promoter maximum per booking (no co-broker split)
 *   - no commission schemes / rules / slabs — commission is always
 *     Booking final_amount × Partner.commission_percentage
 *
 * commission_schemes / commission_rules / commission_slabs held zero live
 * rows at the time of this change (verified before writing this migration),
 * so dropping them loses no historical data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_scheme_id');
            $table->dropConstrainedForeignId('commission_rule_id');
            $table->decimal('advance_adjusted_amount', 15, 2)->default(0)->after('commission_amount');
            $table->decimal('payable_amount', 15, 2)->default(0)->after('advance_adjusted_amount');
        });

        Schema::dropIfExists('commission_slabs');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('commission_schemes');

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('commission_scheme_code');
            $table->decimal('commission_percentage', 5, 2)->nullable()->after('rera_number');
        });

        Schema::table('booking_partner_attributions', function (Blueprint $table) {
            $table->dropColumn(['share_percentage', 'role']);
        });

        Schema::table('commission_calculations', function (Blueprint $table) {
            $table->dropColumn([
                'scheme_code', 'scheme_version', 'basis', 'share_percentage',
                'calc_type', 'gross_before_caps', 'gross_amount',
            ]);
            $table->decimal('advance_adjusted_amount', 15, 2)->default(0)->after('commission_amount');
            $table->decimal('payable_amount', 15, 2)->default(0)->after('advance_adjusted_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_calculations', function (Blueprint $table) {
            $table->dropColumn(['advance_adjusted_amount', 'payable_amount']);
            $table->string('scheme_code', 20)->default('');
            $table->unsignedInteger('scheme_version')->default(1);
            $table->string('basis', 24)->default('booking_value');
            $table->decimal('share_percentage', 5, 2)->default(100);
            $table->string('calc_type', 12)->default('percentage');
            $table->decimal('gross_before_caps', 15, 4)->default(0);
            $table->decimal('gross_amount', 15, 2)->default(0);
        });

        Schema::table('booking_partner_attributions', function (Blueprint $table) {
            $table->decimal('share_percentage', 5, 2)->default(100);
            $table->string('role', 16)->default('primary');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('commission_percentage');
            $table->string('commission_scheme_code', 20)->nullable()->after('rera_number');
        });

        Schema::create('commission_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->string('basis', 24)->default('booking_value');
            $table->string('partner_type', 24)->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['status', 'partner_type']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_scheme_id')->constrained('commission_schemes')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('calc_type', 12);
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('flat_amount', 15, 2)->nullable();
            $table->string('slab_mode', 12)->nullable();
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['commission_scheme_id', 'project_id']);
        });

        Schema::create('commission_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('from_amount', 15, 2)->default(0);
            $table->decimal('to_amount', 15, 2)->nullable();
            $table->string('calc_type', 12);
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('flat_amount', 15, 2)->nullable();
            $table->timestamps();
            $table->index(['commission_rule_id', 'sort_order']);
        });

        Schema::table('commission_cases', function (Blueprint $table) {
            $table->dropColumn(['advance_adjusted_amount', 'payable_amount']);
            $table->foreignId('commission_scheme_id')->nullable()->constrained('commission_schemes')->nullOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
        });
    }
};
