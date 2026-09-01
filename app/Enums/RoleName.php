<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The initial, system-defined roles. Additional roles can be created at runtime
 * through the Roles UI; these are the ones the seeder guarantees exist and that
 * are protected from deletion.
 */
enum RoleName: string
{
    use HasLabel;

    case SuperAdmin = 'Super Admin';
    case Admin = 'Admin';
    case SalesManager = 'Sales Manager';
    case SalesExecutive = 'Sales Executive';
    case Accountant = 'Accountant';
    case CollectionManager = 'Collection Manager';
    case CollectionExecutive = 'Collection Executive';
    case RegistryManager = 'Registry Manager';
    case PossessionManager = 'Possession Manager';
    case AssociateManager = 'Associate Manager';
    case Viewer = 'Viewer';

    public function label(): string
    {
        return $this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Unrestricted access to everything, including all future modules.',
            self::Admin => 'Full administrative access: user & role management and all business modules.',
            self::SalesManager => 'Manages projects, plots, buyers and bookings.',
            self::SalesExecutive => 'Day-to-day sales: view inventory, manage buyers, create bookings.',
            self::Accountant => 'Manages and approves payments; read access to sales.',
            self::CollectionManager => 'Oversees the whole collection queue: assigns cases, approves penalties.',
            self::CollectionExecutive => 'Works assigned collection cases: follow-ups and promises to pay.',
            self::RegistryManager => 'Handles registry records and related sales data.',
            self::PossessionManager => 'Handles possession hand-over and related sales data.',
            self::AssociateManager => 'Manages promoters and associates.',
            self::Viewer => 'Read-only access across built modules.',
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }

    /** Roles that must always exist and cannot be deleted through the UI. */
    public function isSystem(): bool
    {
        return true;
    }
}
