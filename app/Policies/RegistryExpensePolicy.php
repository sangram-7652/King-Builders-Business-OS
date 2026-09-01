<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RegistryExpense;
use App\Models\User;

class RegistryExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RegistryExpensesView->value);
    }

    public function view(User $user, RegistryExpense $expense): bool
    {
        return $user->can(Permission::RegistryExpensesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RegistryExpensesCreate->value);
    }

    public function approve(User $user, RegistryExpense $expense): bool
    {
        return $user->can(Permission::RegistryExpensesApprove->value)
            && $expense->status === RegistryExpense::STATUS_RECORDED;
    }
}
