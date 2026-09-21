<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\Portal\DocumentDownloadController as PortalDocumentDownload;
use App\Http\Controllers\Portal\LogoutController as PortalLogoutController;
use App\Http\Controllers\Portal\ReceiptDownloadController as PortalReceiptDownload;
use App\Http\Controllers\ReceiptPdfController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Bookings\BookingCommission;
use App\Livewire\Bookings\BookingDocuments;
use App\Livewire\Bookings\BookingForm;
use App\Livewire\Bookings\BookingIndex;
use App\Livewire\Bookings\BookingPartners;
use App\Livewire\Bookings\BookingPossession;
use App\Livewire\Bookings\BookingRegistry;
use App\Livewire\Bookings\BookingShow;
use App\Livewire\Bookings\BookingTransfers;
use App\Livewire\Buyers\BuyerDocuments;
use App\Livewire\Buyers\BuyerForm;
use App\Livewire\Buyers\BuyerIndex;
use App\Livewire\Buyers\BuyerShow;
use App\Livewire\Commission\CommissionCaseShow;
use App\Livewire\Commission\CommissionIndex;
use App\Livewire\Dashboard;
use App\Livewire\Documents\DocumentDashboard;
use App\Livewire\Masters\MasterDashboard;
use App\Livewire\Masters\MasterForm;
use App\Livewire\Masters\MasterIndex;
use App\Livewire\Partners\PartnerDocuments;
use App\Livewire\Partners\PartnerForm;
use App\Livewire\Partners\PartnerIndex;
use App\Livewire\Partners\PartnerShow;
use App\Livewire\Payments\BookingPayments;
use App\Livewire\Payments\PaymentDashboard;
use App\Livewire\Payments\PaymentIndex;
use App\Livewire\Payments\PaymentShow;
use App\Livewire\Payments\ReceiptShow;
use App\Livewire\Plots\PlotBulkCreate;
use App\Livewire\Plots\PlotForm;
use App\Livewire\Plots\PlotIndex;
use App\Livewire\Plots\PlotShow;
use App\Livewire\Portal\Auth\Activate as PortalActivate;
use App\Livewire\Portal\Auth\ForgotPassword as PortalForgotPassword;
use App\Livewire\Portal\Auth\Login as PortalLogin;
use App\Livewire\Portal\Bookings\Index as PortalBookingIndex;
use App\Livewire\Portal\Bookings\Show as PortalBookingShow;
use App\Livewire\Portal\Dashboard as PortalDashboard;
use App\Livewire\Portal\Documents\Index as PortalDocumentIndex;
use App\Livewire\Portal\Payments\Index as PortalPaymentIndex;
use App\Livewire\Portal\Payments\Show as PortalPaymentShow;
use App\Livewire\Portal\Profile as PortalProfile;
use App\Livewire\Possession\PossessionDashboard;
use App\Livewire\Projects\ProjectForm;
use App\Livewire\Projects\ProjectIndex;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Registry\RegistryDashboard;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RoleIndex;
use App\Livewire\Roles\RoleShow;
use App\Livewire\Transfer\TransferDashboard;
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
// Staff area — pinned to the `web` guard so a customer-portal session can
// never satisfy it (M15 added the separate `customer` guard).
Route::middleware(['auth:web', 'active'])->group(function (): void {
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
    Route::get('/bookings/{booking}/partners', BookingPartners::class)
        ->middleware('permission:'.Permission::PartnersAttribute->value)
        ->whereNumber('booking')
        ->name('bookings.partners');
    Route::get('/bookings/{booking}/commission', BookingCommission::class)
        ->middleware('permission:'.Permission::CommissionView->value)
        ->whereNumber('booking')
        ->name('bookings.commission');

    // --- Payments / Receipts (M7) --------------------------------------
    Route::get('/finance', PaymentDashboard::class)
        ->middleware('permission:'.Permission::PaymentsView->value)
        ->name('finance.dashboard');

    Route::get('/bookings/{booking}/payments', BookingPayments::class)
        ->middleware('permission:'.Permission::PaymentsView->value)
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

    // --- Documentation / Agreement / Registry (M9) --------------------
    Route::get('/documents', DocumentDashboard::class)
        ->middleware('permission:'.Permission::DocumentsView->value)
        ->name('documents.dashboard');
    Route::get('/registry', RegistryDashboard::class)
        ->middleware('permission:'.Permission::RegistryView->value)
        ->name('registry.dashboard');

    Route::get('/buyers/{buyer}/documents', BuyerDocuments::class)
        ->middleware('permission:'.Permission::DocumentsView->value)
        ->whereNumber('buyer')
        ->name('buyers.documents');
    Route::get('/bookings/{booking}/documents', BookingDocuments::class)
        ->middleware('permission:'.Permission::DocumentsView->value)
        ->whereNumber('booking')
        ->name('documents.booking');
    Route::get('/bookings/{booking}/registry', BookingRegistry::class)
        ->middleware('permission:'.Permission::RegistryView->value)
        ->whereNumber('booking')
        ->name('registry.booking');

    Route::get('/documents/{document}/versions/{version}/download', DocumentDownloadController::class)
        ->middleware('permission:'.Permission::DocumentsDownload->value)
        ->whereNumber('document')->whereNumber('version')
        ->name('documents.download');

    // --- Possession / Transfer / Ownership (M10) ---------------------
    Route::get('/possession', PossessionDashboard::class)
        ->middleware('permission:'.Permission::PossessionView->value)
        ->name('possession.dashboard');
    Route::get('/transfers', TransferDashboard::class)
        ->middleware('permission:'.Permission::TransferView->value)
        ->name('transfers.dashboard');
    Route::get('/bookings/{booking}/possession', BookingPossession::class)
        ->middleware('permission:'.Permission::PossessionView->value)
        ->whereNumber('booking')
        ->name('possession.booking');
    Route::get('/bookings/{booking}/transfers', BookingTransfers::class)
        ->middleware('permission:'.Permission::TransferView->value)
        ->whereNumber('booking')
        ->name('transfers.booking');

    // --- Reports (M11.1 — foundation + global filters) ----------------
    Route::prefix('reports')->name('reports.')
        ->middleware('permission:'.Permission::ReportsView->value)
        ->group(function (): void {
            Route::get('/', [ReportController::class, 'overview'])->name('overview');
            Route::get('/sales', [ReportController::class, 'sales'])->name('sales');
            Route::get('/inventory', [ReportController::class, 'inventory'])->name('inventory');
            Route::get('/mis', [ReportController::class, 'mis'])->name('mis');

            // Report export (M11.5) — additionally gated by reports.export.
            Route::get('/{type}/export/{format}', ReportExportController::class)
                ->middleware('permission:'.Permission::ReportsExport->value)
                ->whereIn('type', ['sales', 'inventory', 'mis'])
                ->whereIn('format', ['csv', 'xlsx', 'pdf', 'print'])
                ->name('export');
        });

    // --- Channel Partners / Brokers (M14) ---------------------------
    Route::get('/partners/create', PartnerForm::class)
        ->middleware('permission:'.Permission::PartnersCreate->value)
        ->name('partners.create');
    Route::get('/partners/{partner}/edit', PartnerForm::class)
        ->middleware('permission:'.Permission::PartnersUpdate->value)
        ->whereNumber('partner')
        ->name('partners.edit');
    Route::get('/partners/{partner}/documents', PartnerDocuments::class)
        ->middleware('permission:'.Permission::DocumentsView->value)
        ->whereNumber('partner')
        ->name('partners.documents');
    Route::get('/partners', PartnerIndex::class)
        ->middleware('permission:'.Permission::PartnersView->value)
        ->name('partners.index');
    Route::get('/partners/{partner}', PartnerShow::class)
        ->middleware('permission:'.Permission::PartnersView->value)
        ->whereNumber('partner')
        ->name('partners.show');

    // --- Commission cases (M14.4–M14.5) — flat Promoter model, no schemes ---
    Route::get('/commissions', CommissionIndex::class)
        ->middleware('permission:'.Permission::CommissionView->value)
        ->name('commissions.index');

    Route::get('/commissions/cases/{case}', CommissionCaseShow::class)
        ->middleware('permission:'.Permission::CommissionView->value)
        ->whereNumber('case')
        ->name('commission-cases.show');

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

/*
| ---------------------------------------------------------------------------
| Customer self-service portal (M15) — the `customer` guard only
| ---------------------------------------------------------------------------
| Completely separate from the staff area: a customer is a Buyer on a different
| auth guard and can never resolve a staff role/permission or reach an admin
| route.
*/
Route::prefix('portal')->name('portal.')->group(function (): void {

    Route::middleware('guest:customer')->group(function (): void {
        Route::get('/login', PortalLogin::class)->name('login');
        Route::get('/forgot-password', PortalForgotPassword::class)->name('password.request');
        Route::get('/activate/{token}', PortalActivate::class)->name('activate');
        Route::get('/reset-password/{token}', PortalActivate::class)
            ->defaults('purpose', 'reset')
            ->name('password.reset');
    });

    Route::middleware(['auth:customer', 'customer.active'])->group(function (): void {
        Route::post('/logout', PortalLogoutController::class)->name('logout');

        Route::get('/', PortalDashboard::class)->name('dashboard');
        Route::get('/profile', PortalProfile::class)->name('profile');

        // --- My bookings + payments (M15.2) ---------------------------
        Route::get('/bookings', PortalBookingIndex::class)->name('bookings.index');
        Route::get('/bookings/{booking}', PortalBookingShow::class)->whereNumber('booking')->name('bookings.show');
        Route::get('/payments', PortalPaymentIndex::class)->name('payments.index');
        Route::get('/payments/{payment}', PortalPaymentShow::class)->whereNumber('payment')->name('payments.show');
        Route::get('/receipts/{receipt}/pdf', PortalReceiptDownload::class)->whereNumber('receipt')->name('receipts.pdf');

        // --- My documents (M15.2) --------------------------------------
        Route::get('/documents', PortalDocumentIndex::class)->name('documents.index');
        Route::get('/documents/{document}/versions/{version}/download', PortalDocumentDownload::class)
            ->whereNumber(['document', 'version'])
            ->name('documents.download');
    });
});
