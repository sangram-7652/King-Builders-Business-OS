<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: creates every permission and system role, then (re)syncs the
 * default permission set for each system role. Safe to run repeatedly.
 *
 * Custom roles created through the UI are never touched here.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (PermissionEnum::cases() as $permission) {
                Permission::findOrCreate($permission->value, 'web');
            }

            foreach (RoleName::cases() as $roleName) {
                $role = Role::findOrCreate($roleName->value, 'web');
                $role->syncPermissions($this->permissionsFor($roleName));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(RoleName $role): array
    {
        $all = PermissionEnum::values();

        $viewOnly = array_values(array_filter(
            $all,
            static fn (string $p): bool => str_ends_with($p, '.view'),
        ));

        return match ($role) {
            RoleName::SuperAdmin, RoleName::Admin => $all,

            RoleName::SalesManager => [
                'projects.view', 'projects.create', 'projects.update', 'projects.activate', 'projects.archive',
                'plots.view', 'plots.create', 'plots.update', 'plots.delete',
                'plots.hold', 'plots.release', 'plots.activate', 'plots.archive', 'plots.bulk_create',
                'leads.view', 'leads.view_all', 'leads.create', 'leads.update', 'leads.delete',
                'leads.assign', 'leads.convert', 'leads.follow_up', 'leads.merge',
                'follow_ups.view', 'follow_ups.create', 'follow_ups.update', 'follow_ups.complete',
                'partners.view', 'partners.attribute', 'commission.view',
                'buyers.view', 'buyers.create', 'buyers.update', 'buyers.delete',
                'buyers.archive', 'buyers.documents', 'buyers.portal',
                'bookings.view', 'bookings.create', 'bookings.update', 'bookings.confirm',
                'bookings.cancel', 'bookings.delete',
                'pricing.view', 'pricing.manage', 'pricing.override',
                'payment_plans.view', 'payment_plans.create', 'payment_plans.update', 'payment_plans.activate',
                'payments.view', 'payments.create',
                'receipts.view',
                'collections.view', 'collections.view_all', 'collections.assign', 'collections.reports',
                'promises.view', 'cheques.view', 'penalties.view',
                'documents.view', 'documents.upload', 'documents.download',
                'agreements.view', 'agreements.create',
                'registry.view', 'registry_expenses.view', 'handover.view',
                'possession.view', 'transfer.view', 'transfer.create', 'ownership.view',
                'communications.view', 'communications.manage',
                'reports.view', 'reports.export',
                'masters.view',
            ],

            RoleName::SalesExecutive => [
                'projects.view',
                'plots.view', 'plots.hold', 'plots.release',
                'leads.view', 'leads.create', 'leads.update', 'leads.convert', 'leads.follow_up',
                'follow_ups.view', 'follow_ups.create', 'follow_ups.update', 'follow_ups.complete',
                'partners.view', 'partners.attribute',
                'buyers.view', 'buyers.create', 'buyers.update',
                'bookings.view', 'bookings.create', 'bookings.update',
                'pricing.view',
                'payment_plans.view', 'payments.view', 'receipts.view',
                'masters.view',
            ],

            RoleName::Accountant => [
                'payment_plans.view', 'payment_plans.create', 'payment_plans.update', 'payment_plans.activate',
                'payments.view', 'payments.create', 'payments.verify', 'payments.allocate', 'payments.reverse',
                'receipts.view', 'receipts.generate',
                'collections.view', 'collections.view_all', 'collections.reports',
                'promises.view', 'cheques.view', 'cheques.update', 'cheques.bounce',
                'penalties.view', 'penalties.assess', 'penalties.approve',
                'buyers.view', 'buyers.documents', 'bookings.view', 'registry.view',
                'documents.view', 'documents.download',
                'registry_expenses.view', 'registry_expenses.create', 'registry_expenses.approve',
                'pricing.view', 'pricing.manage',
                'commission_schemes.view',
                'commission.view', 'commission.generate', 'commission.recalculate',
                'commission.approve', 'commission.payout', 'commission.reverse',
                'reports.view', 'reports.export',
                'masters.view', 'masters.create', 'masters.update',
            ],

            RoleName::CollectionManager => [
                'collections.view', 'collections.view_all', 'collections.create', 'collections.update',
                'collections.assign', 'collections.follow_up', 'collections.reports',
                'promises.view', 'promises.create', 'promises.update',
                'cheques.view', 'cheques.update', 'cheques.bounce',
                'penalties.view', 'penalties.assess', 'penalties.approve',
                'bookings.view', 'buyers.view', 'payments.view', 'payment_plans.view', 'receipts.view',
                'reports.view', 'reports.export', 'masters.view',
            ],

            RoleName::CollectionExecutive => [
                'collections.view', 'collections.create', 'collections.update', 'collections.follow_up',
                'promises.view', 'promises.create', 'promises.update',
                'cheques.view',
                'penalties.view',
                'bookings.view', 'buyers.view', 'payments.view', 'payment_plans.view', 'receipts.view',
                'masters.view',
            ],

            RoleName::RegistryManager => [
                'documents.view', 'documents.upload', 'documents.verify', 'documents.reject',
                'documents.download', 'documents.delete',
                'agreements.view', 'agreements.create', 'agreements.update', 'agreements.approve',
                'registry.view', 'registry.create', 'registry.update', 'registry.schedule', 'registry.complete',
                'registry_expenses.view', 'registry_expenses.create', 'registry_expenses.approve',
                'handover.view', 'handover.create', 'handover.complete',
                'possession.view', 'transfer.view', 'ownership.view',
                'plots.view', 'buyers.view', 'bookings.view',
                'payments.view', 'payment_plans.view', 'collections.view', 'collections.view_all',
                'reports.view', 'reports.export',
                'masters.view',
            ],

            RoleName::PossessionManager => [
                'possession.view', 'possession.create', 'possession.schedule',
                'possession.inspect', 'possession.complete', 'possession.clear',
                'transfer.view', 'transfer.create', 'transfer.review', 'transfer.approve', 'transfer.complete',
                'ownership.view',
                'documents.view', 'documents.upload', 'documents.verify', 'documents.reject', 'documents.download',
                'plots.view', 'buyers.view', 'buyers.documents', 'bookings.view',
                'payments.view', 'payment_plans.view', 'receipts.view',
                'collections.view', 'collections.view_all',
                'registry.view',
                'reports.view', 'reports.export',
                'masters.view',
            ],

            RoleName::AssociateManager => [
                'partners.view', 'partners.create', 'partners.update', 'partners.approve',
                'partners.authorize', 'partners.attribute',
                'commission_schemes.view', 'commission_schemes.manage', 'commission_schemes.publish',
                'commission.view', 'commission.generate', 'commission.recalculate',
                'commission.approve', 'commission.reverse',
                'projects.view', 'leads.view', 'leads.view_all', 'buyers.view', 'bookings.view',
                'documents.view', 'documents.upload', 'documents.verify', 'documents.reject', 'documents.download',
                'reports.view', 'reports.export',
                'masters.view',
            ],

            RoleName::Viewer => [...$viewOnly, 'leads.view_all'],
        };
    }
}
