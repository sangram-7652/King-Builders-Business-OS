<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\CustomerActivityType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        $customer?->recordPortalActivity(CustomerActivityType::Logout, 'Signed out of the portal.');

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
