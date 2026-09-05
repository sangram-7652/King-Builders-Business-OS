<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-wise partner authorisation (M14). One row per (partner, project);
 * `status` toggles between `active` and `revoked` so the authorisation history
 * (who granted / revoked and when) is preserved rather than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_project_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('status', 12)->default('active'); // active | revoked

            $table->timestamp('authorized_at')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'project_id']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_project_authorizations');
    }
};
