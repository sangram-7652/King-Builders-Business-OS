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

    // --- Inventory (future modules) ---------------------------------------
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';

    case PlotsView = 'plots.view';
    case PlotsCreate = 'plots.create';
    case PlotsUpdate = 'plots.update';
    case PlotsDelete = 'plots.delete';

    // --- Sales (future modules) ------------------------------------------
    case BuyersView = 'buyers.view';
    case BuyersCreate = 'buyers.create';
    case BuyersUpdate = 'buyers.update';
    case BuyersDelete = 'buyers.delete';

    case BookingsView = 'bookings.view';
    case BookingsCreate = 'bookings.create';
    case BookingsUpdate = 'bookings.update';
    case BookingsCancel = 'bookings.cancel';

    // --- Finance (future modules) ---------------------------------------
    case PaymentsView = 'payments.view';
    case PaymentsCreate = 'payments.create';
    case PaymentsUpdate = 'payments.update';
    case PaymentsApprove = 'payments.approve';

    // --- Operations (future modules) ----------------------------------
    case RegistryView = 'registry.view';
    case RegistryUpdate = 'registry.update';

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
            'buyers', 'bookings' => PermissionGroup::Sales,
            'payments' => PermissionGroup::Finance,
            'registry', 'possession' => PermissionGroup::Operations,
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
