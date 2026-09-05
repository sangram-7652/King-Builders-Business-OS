<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M14 repurposes the placeholder `associates.*` permission set (M1, never wired
 * to a built module) into the real `partners.*` set. This drops the orphaned
 * rows so `permissions` stays in step with the Permission enum. Role sync is
 * handled by RolePermissionSeeder; nothing in production referenced these.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where('name', 'like', 'associates.%')->delete();
    }

    public function down(): void
    {
        foreach (['associates.view', 'associates.create', 'associates.update'] as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }
};
