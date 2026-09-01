<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

/*
| ---------------------------------------------------------------------------
| Web routes — M0 Foundation
| ---------------------------------------------------------------------------
| Authentication & RBAC middleware arrives in M1. For now the shell is open
| so the foundation can be verified in a browser.
*/

Route::redirect('/', '/dashboard');

Route::get('/dashboard', Dashboard::class)->name('dashboard');

Route::view('/ui-kit', 'ui-kit')->name('ui-kit');
