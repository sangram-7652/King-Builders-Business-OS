<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;
use Illuminate\Support\Str;

/**
 * Single source of truth for every permission in the system.
 *
 * Naming: `module.action`. Some modules are not built yet (M1 is Auth + RBAC
 * only) — their permissions are seeded so roles can be configured ahead of the
 * module landing, but nothing references them until then.
 *
 * The permissions actually enforced in M1 are the `users.*`, `roles.*` and
 * `settings.*` sets.
 */
enum Permission: string
{
    use HasLabel;

    // --- Inventory ------------------------------------------------------
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';
    case ProjectsActivate = 'projects.activate';
    case ProjectsArchive = 'projects.archive';

    case PlotsView = 'plots.view';
    case PlotsCreate = 'plots.create';
    case PlotsUpdate = 'plots.update';
    case PlotsDelete = 'plots.delete';
    case PlotsHold = 'plots.hold';
    case PlotsRelease = 'plots.release';
    case PlotsActivate = 'plots.activate';
    case PlotsArchive = 'plots.archive';
    case PlotsBulkCreate = 'plots.bulk_create';

    case BuyersView = 'buyers.view';
    case BuyersCreate = 'buyers.create';
    case BuyersUpdate = 'buyers.update';
    case BuyersDelete = 'buyers.delete';
    case BuyersArchive = 'buyers.archive';
    case BuyersDocuments = 'buyers.documents';
    case BuyersPortal = 'buyers.portal';

    case BookingsView = 'bookings.view';
    case BookingsCreate = 'bookings.create';
    case BookingsUpdate = 'bookings.update';
    case BookingsConfirm = 'bookings.confirm';
    case BookingsCancel = 'bookings.cancel';
    case BookingsDelete = 'bookings.delete';

    // --- Finance ------------------------------------------------------
    case PricingView = 'pricing.view';
    case PricingManage = 'pricing.manage';
    case PricingOverride = 'pricing.override';

    // --- Finance: Payments / Receipts (M7) -------------------------------
    case PaymentsView = 'payments.view';
    case PaymentsCreate = 'payments.create';
    case PaymentsVerify = 'payments.verify';
    case PaymentsReverse = 'payments.reverse';

    case ReceiptsView = 'receipts.view';
    case ReceiptsGenerate = 'receipts.generate';

    // --- Documentation / Agreement / Registry (M9) --------------------
    case DocumentsView = 'documents.view';
    case DocumentsUpload = 'documents.upload';
    case DocumentsVerify = 'documents.verify';
    case DocumentsReject = 'documents.reject';
    case DocumentsDownload = 'documents.download';
    case DocumentsDelete = 'documents.delete';

    case AgreementsView = 'agreements.view';
    case AgreementsCreate = 'agreements.create';
    case AgreementsUpdate = 'agreements.update';
    case AgreementsApprove = 'agreements.approve';

    case RegistryView = 'registry.view';
    case RegistryCreate = 'registry.create';
    case RegistryUpdate = 'registry.update';
    case RegistrySchedule = 'registry.schedule';
    case RegistryComplete = 'registry.complete';

    case RegistryExpensesView = 'registry_expenses.view';
    case RegistryExpensesCreate = 'registry_expenses.create';
    case RegistryExpensesApprove = 'registry_expenses.approve';

    case HandoverView = 'handover.view';
    case HandoverCreate = 'handover.create';
    case HandoverComplete = 'handover.complete';

    // --- Possession / Transfer / Ownership (M10) ---------------------
    case PossessionView = 'possession.view';
    case PossessionCreate = 'possession.create';
    case PossessionSchedule = 'possession.schedule';
    case PossessionInspect = 'possession.inspect';
    case PossessionComplete = 'possession.complete';
    case PossessionClear = 'possession.clear';

    case TransferView = 'transfer.view';
    case TransferCreate = 'transfer.create';
    case TransferReview = 'transfer.review';
    case TransferApprove = 'transfer.approve';
    case TransferComplete = 'transfer.complete';

    case OwnershipView = 'ownership.view';

    // --- Channel Partners / Brokers (M14) ---------------------------
    case PartnersView = 'partners.view';
    case PartnersCreate = 'partners.create';
    case PartnersUpdate = 'partners.update';
    case PartnersApprove = 'partners.approve';
    case PartnersAuthorize = 'partners.authorize';
    case PartnersAttribute = 'partners.attribute';

    // --- Commission cases (M14.4) ----------------------------------
    case CommissionView = 'commission.view';
    case CommissionGenerate = 'commission.generate';
    case CommissionRecalculate = 'commission.recalculate';

    // --- Commission workflow + payout (M14.5) ----------------------
    case CommissionApprove = 'commission.approve';
    case CommissionPayout = 'commission.payout';
    case CommissionReverse = 'commission.reverse';

    // --- Communications (M16) ------------------------------------
    case CommunicationsView = 'communications.view';
    case CommunicationsManage = 'communications.manage';

    // --- Reports (M11) ---------------------------------------------
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // --- Administration (M1) ------------------------------------------
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    // --- Master Data (M2) ------------------------------------------
    case MastersView = 'masters.view';
    case MastersCreate = 'masters.create';
    case MastersUpdate = 'masters.update';
    case MastersDelete = 'masters.delete';

    // --- Settings (M1 placeholder) ----------------------------------
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    public function module(): string
    {
        return Str::before($this->value, '.');
    }

    public function action(): string
    {
        return Str::after($this->value, '.');
    }

    public function label(): string
    {
        return Str::headline($this->action()).' '.Str::headline($this->module());
    }

    public function group(): PermissionGroup
    {
        return match ($this->module()) {
            'projects', 'plots' => PermissionGroup::Inventory,
            'buyers', 'bookings' => PermissionGroup::Sales,
            'payments', 'pricing', 'receipts' => PermissionGroup::Finance,
            'documents', 'agreements', 'registry', 'registry_expenses', 'handover',
            'possession', 'transfer', 'ownership' => PermissionGroup::Operations,
            'partners' => PermissionGroup::Associates,
            'commission' => PermissionGroup::Commission,
            'communications' => PermissionGroup::Communication,
            'reports' => PermissionGroup::Reports,
            'users', 'roles' => PermissionGroup::Administration,
            'masters' => PermissionGroup::MasterData,
            'settings' => PermissionGroup::Settings,
        };
    }

    /**
     * Permissions actually enforced by a built module in M1.
     *
     * @return list<self>
     */
    public static function activeInM1(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $p): bool => in_array($p->module(), ['users', 'roles'], true),
        ));
    }

    /**
     * Grouped for the role editor UI.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (PermissionGroup::cases() as $group) {
            $grouped[$group->value] = array_values(array_filter(
                self::cases(),
                static fn (self $p): bool => $p->group() === $group,
            ));
        }

        return $grouped;
    }
}
