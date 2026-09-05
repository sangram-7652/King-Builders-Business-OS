<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth for portal routes (M15): even with a valid `customer`
 * session, a buyer whose portal access was suspended (or whose buyer record
 * was archived) is signed out. Mirrors {@see EnsureUserIsActive} for staff.
 */
class EnsureCustomerPortalActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if ($customer === null || ! $customer->canAccessPortal()) {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')->withErrors([
                'email' => 'Your portal access is not active. Please contact us.',
            ]);
        }

        return $next($request);
    }
}
