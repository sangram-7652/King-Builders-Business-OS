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

    // --- Sales ---------------------------------------------------------
    case LeadsView = 'leads.view';
    case LeadsViewAll = 'leads.view_all';
    case LeadsCreate = 'leads.create';
    case LeadsUpdate = 'leads.update';
    case LeadsDelete = 'leads.delete';
    case LeadsAssign = 'leads.assign';
    case LeadsConvert = 'leads.convert';
    case LeadsFollowUp = 'leads.follow_up';

    case BuyersView = 'buyers.view';
    case BuyersCreate = 'buyers.create';
    case BuyersUpdate = 'buyers.update';
    case BuyersDelete = 'buyers.delete';
    case BuyersArchive = 'buyers.archive';
    case BuyersDocuments = 'buyers.documents';

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

    // --- Finance: Payments / Installments / Receipts (M7) ---------------
    case PaymentPlansView = 'payment_plans.view';
    case PaymentPlansCreate = 'payment_plans.create';
    case PaymentPlansUpdate = 'payment_plans.update';
    case PaymentPlansActivate = 'payment_plans.activate';

    case PaymentsView = 'payments.view';
    case PaymentsCreate = 'payments.create';
    case PaymentsVerify = 'payments.verify';
    case PaymentsAllocate = 'payments.allocate';
    case PaymentsReverse = 'payments.reverse';

    case ReceiptsView = 'receipts.view';
    case ReceiptsGenerate = 'receipts.generate';

    // --- Collections (M8) -------------------------------------------
    case CollectionsView = 'collections.view';
    case CollectionsViewAll = 'collections.view_all';
    case CollectionsCreate = 'collections.create';
    case CollectionsUpdate = 'collections.update';
    case CollectionsAssign = 'collections.assign';
    case CollectionsFollowUp = 'collections.follow_up';
    case CollectionsReports = 'collections.reports';

    case PromisesView = 'promises.view';
    case PromisesCreate = 'promises.create';
    case PromisesUpdate = 'promises.update';

    case ChequesView = 'cheques.view';
    case ChequesUpdate = 'cheques.update';
    case ChequesBounce = 'cheques.bounce';

    case PenaltiesView = 'penalties.view';
    case PenaltiesAssess = 'penalties.assess';
    case PenaltiesApprove = 'penalties.approve';

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

    // --- Operations (future modules) ----------------------------------
    case PossessionView = 'possession.view';
    case PossessionUpdate = 'possession.update';

    // --- Associates (future modules) --------------------------------
    case AssociatesView = 'associates.view';
    case AssociatesCreate = 'associates.create';
    case AssociatesUpdate = 'associates.update';

    // --- Reports (future modules) -----------------------------------
    case ReportsView = 'reports.view';

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
            'leads', 'buyers', 'bookings' => PermissionGroup::Sales,
            'payments', 'pricing', 'payment_plans', 'receipts' => PermissionGroup::Finance,
            'collections', 'promises', 'cheques', 'penalties' => PermissionGroup::Collections,
            'documents', 'agreements', 'registry', 'registry_expenses', 'handover', 'possession' => PermissionGroup::Operations,
            'associates' => PermissionGroup::Associates,
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
