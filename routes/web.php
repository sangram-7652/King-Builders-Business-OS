<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\ReceiptPdfController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Bookings\BookingForm;
use App\Livewire\Bookings\BookingIndex;
use App\Livewire\Bookings\BookingShow;
use App\Livewire\Buyers\BuyerForm;
use App\Livewire\Buyers\BuyerIndex;
use App\Livewire\Buyers\BuyerShow;
use App\Livewire\Collections\BookingCollection;
use App\Livewire\Collections\CollectionCaseShow;
use App\Livewire\Collections\CollectionDashboard;
use App\Livewire\Collections\CollectionQueue;
use App\Livewire\Collections\CollectionReports;
use App\Livewire\Dashboard;
use App\Livewire\Leads\LeadConvert;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadIndex;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Masters\MasterDashboard;
use App\Livewire\Masters\MasterForm;
use App\Livewire\Masters\MasterIndex;
use App\Livewire\Payments\BookingPayments;
use App\Livewire\Payments\PaymentDashboard;
use App\Livewire\Payments\PaymentIndex;
use App\Livewire\Payments\PaymentShow;
use App\Livewire\Payments\ReceiptShow;
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

    // --- Leads (M5) ------------------------------------------------------
    Route::get('/leads/create', LeadForm::class)
        ->middleware('permission:'.Permission::LeadsCreate->value)
        ->name('leads.create');
    Route::get('/leads/{lead}/edit', LeadForm::class)
        ->middleware('permission:'.Permission::LeadsUpdate->value)
        ->whereNumber('lead')
        ->name('leads.edit');
    Route::get('/leads/{lead}/convert', LeadConvert::class)
        ->middleware('permission:'.Permission::LeadsConvert->value)
        ->whereNumber('lead')
        ->name('leads.convert');
    Route::get('/leads', LeadIndex::class)
        ->middleware('permission:'.Permission::LeadsView->value)
        ->name('leads.index');
    Route::get('/leads/{lead}', LeadShow::class)
        ->middleware('permission:'.Permission::LeadsView->value)
        ->whereNumber('lead')
        ->name('leads.show');

    // --- Buyers / Customers (M5) --------------------------------------
    Route::get('/buyers/create', BuyerForm::class)
        ->middleware('permission:'.Permission::BuyersCreate->value)
        ->name('buyers.create');
    Route::get('/buyers/{buyer}/edit', BuyerForm::class)
        ->middleware('permission:'.Permission::BuyersUpdate->value)
        ->whereNumber('buyer')
        ->name('buyers.edit');
    Route::get('/buyers', BuyerIndex::class)
        ->middleware('permission:'.Permission::BuyersView->value)
        ->name('buyers.index');
    Route::get('/buyers/{buyer}', BuyerShow::class)
        ->middleware('permission:'.Permission::BuyersView->value)
        ->whereNumber('buyer')
        ->name('buyers.show');

    // --- Bookings (M6) -------------------------------------------------
    Route::get('/bookings/create', BookingForm::class)
        ->middleware('permission:'.Permission::BookingsCreate->value)
        ->name('bookings.create');
    Route::get('/bookings/{booking}/edit', BookingForm::class)
        ->middleware('permission:'.Permission::BookingsUpdate->value)
        ->whereNumber('booking')
        ->name('bookings.edit');
    Route::get('/bookings', BookingIndex::class)
        ->middleware('permission:'.Permission::BookingsView->value)
        ->name('bookings.index');
    Route::get('/bookings/{booking}', BookingShow::class)
        ->middleware('permission:'.Permission::BookingsView->value)
        ->whereNumber('booking')
        ->name('bookings.show');

    // --- Payments / Installments / Receipts (M7) ----------------------
    Route::get('/finance', PaymentDashboard::class)
        ->middleware('permission:'.Permission::PaymentsView->value)
        ->name('finance.dashboard');

    Route::get('/bookings/{booking}/payments', BookingPayments::class)
        ->middleware('permission:'.Permission::PaymentPlansView->value)
        ->whereNumber('booking')
        ->name('payments.booking');

    Route::get('/payments', PaymentIndex::class)
        ->middleware('permission:'.Permission::PaymentsView->value)
        ->name('payments.index');
    Route::get('/payments/{payment}', PaymentShow::class)
        ->middleware('permission:'.Permission::PaymentsView->value)
        ->whereNumber('payment')
        ->name('payments.show');

    Route::get('/receipts/{receipt}', ReceiptShow::class)
        ->middleware('permission:'.Permission::ReceiptsView->value)
        ->whereNumber('receipt')
        ->name('receipts.show');
    Route::get('/receipts/{receipt}/pdf', ReceiptPdfController::class)
        ->middleware('permission:'.Permission::ReceiptsView->value)
        ->whereNumber('receipt')
        ->name('receipts.pdf');

    // --- Collections / Dues / Aging (M8) ------------------------------
    Route::get('/collections/dashboard', CollectionDashboard::class)
        ->middleware('permission:'.Permission::CollectionsView->value)
        ->name('collections.dashboard');
    Route::get('/collections/reports', CollectionReports::class)
        ->middleware('permission:'.Permission::CollectionsReports->value)
        ->name('collections.reports');
    Route::get('/collections', CollectionQueue::class)
        ->middleware('permission:'.Permission::CollectionsView->value)
        ->name('collections.queue');
    Route::get('/collections/{case}', CollectionCaseShow::class)
        ->middleware('permission:'.Permission::CollectionsView->value)
        ->whereNumber('case')
        ->name('collections.show');
    Route::get('/bookings/{booking}/collection', BookingCollection::class)
        ->middleware('permission:'.Permission::CollectionsView->value)
        ->whereNumber('booking')
        ->name('collections.booking');

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
