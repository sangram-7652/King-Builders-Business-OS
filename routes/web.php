<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Auth\LogoutController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Dashboard;
use App\Livewire\Masters\MasterDashboard;
use App\Livewire\Masters\MasterForm;
use App\Livewire\Masters\MasterIndex;
use App\Livewire\Plots\PlotBulkCreate;
use App\Livewire\Plots\PlotForm;
use App\Livewire\Plots\PlotIndex;
use App\Livewire\Plots\PlotShow;
use App\Livewire\Projects\ProjectForm;
use App\Livewire\Projects\ProjectIndex;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RoleIndex;
use App\Livewire\Roles\RoleShow;
use App\Livewire\Users\UserForm;
use App\Livewire\Users\UserIndex;
use App\Livewire\Users\UserShow;
use Illuminate\Support\Facades\Route;

/*
| ---------------------------------------------------------------------------
| Web routes — M1 Authentication + RBAC
| ---------------------------------------------------------------------------
*/

Route::redirect('/', '/dashboard');

// --- Guest -----------------------------------------------------------------
Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

// --- Authenticated + active ---------------------------------------------
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::view('/ui-kit', 'ui-kit')->name('ui-kit');

    // --- Projects / Sites (M3) -----------------------------------------
    Route::get('/projects/create', ProjectForm::class)
        ->middleware('permission:'.Permission::ProjectsCreate->value)
        ->name('projects.create');
    Route::get('/projects/{project}/edit', ProjectForm::class)
        ->middleware('permission:'.Permission::ProjectsUpdate->value)
        ->whereNumber('project')
        ->name('projects.edit');
    Route::get('/projects', ProjectIndex::class)
        ->middleware('permission:'.Permission::ProjectsView->value)
        ->name('projects.index');
    Route::get('/projects/{project}', ProjectShow::class)
        ->middleware('permission:'.Permission::ProjectsView->value)
        ->whereNumber('project')
        ->name('projects.show');

    // --- Plot Inventory (M4) — nested under project + block --------------
    Route::prefix('projects/{project}/blocks/{block}/plots')
        ->scopeBindings()
        ->whereNumber('project')->whereNumber('block')
        ->group(function (): void {
            Route::get('/create', PlotForm::class)
                ->middleware('permission:'.Permission::PlotsCreate->value)
                ->name('plots.create');
            Route::get('/bulk', PlotBulkCreate::class)
                ->middleware('permission:'.Permission::PlotsBulkCreate->value)
                ->name('plots.bulk');
            Route::get('/{plot}/edit', PlotForm::class)
                ->middleware('permission:'.Permission::PlotsUpdate->value)
                ->whereNumber('plot')
                ->name('plots.edit');
            Route::get('/{plot}', PlotShow::class)
                ->middleware('permission:'.Permission::PlotsView->value)
                ->whereNumber('plot')
                ->name('plots.show');
            Route::get('/', PlotIndex::class)
                ->middleware('permission:'.Permission::PlotsView->value)
                ->name('plots.index');
        });

    // --- Users -------------------------------------------------------------
    Route::get('/users/create', UserForm::class)
        ->middleware('permission:'.Permission::UsersCreate->value)
        ->name('users.create');
    Route::get('/users/{user}/edit', UserForm::class)
        ->middleware('permission:'.Permission::UsersUpdate->value)
        ->name('users.edit');
    Route::get('/users', UserIndex::class)
        ->middleware('permission:'.Permission::UsersView->value)
        ->name('users.index');
    Route::get('/users/{user}', UserShow::class)
        ->middleware('permission:'.Permission::UsersView->value)
        ->whereNumber('user')
        ->name('users.show');

    // --- Roles & permissions --------------------------------------------
    Route::get('/roles/create', RoleForm::class)
        ->middleware('permission:'.Permission::RolesCreate->value)
        ->name('roles.create');
    Route::get('/roles/{role}/edit', RoleForm::class)
        ->middleware('permission:'.Permission::RolesUpdate->value)
        ->name('roles.edit');
    Route::get('/roles', RoleIndex::class)
        ->middleware('permission:'.Permission::RolesView->value)
        ->name('roles.index');
    Route::get('/roles/{role}', RoleShow::class)
        ->middleware('permission:'.Permission::RolesView->value)
        ->whereNumber('role')
        ->name('roles.show');

    // --- Settings › Master Data (M2) ------------------------------------
    Route::prefix('settings/masters')->name('masters.')->group(function (): void {
        Route::get('/', MasterDashboard::class)
            ->middleware('permission:'.Permission::MastersView->value)
            ->name('dashboard');

        Route::get('/{resource}/create', MasterForm::class)
            ->middleware('permission:'.Permission::MastersCreate->value)
            ->name('create');

        Route::get('/{resource}/{record}/edit', MasterForm::class)
            ->middleware('permission:'.Permission::MastersUpdate->value)
            ->whereNumber('record')
            ->name('edit');

        Route::get('/{resource}', MasterIndex::class)
            ->middleware('permission:'.Permission::MastersView->value)
            ->name('index');
    });
});
