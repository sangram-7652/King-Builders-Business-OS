<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\InteractsWithCustomer;
use App\Services\Portal\CustomerPortfolioService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.portal')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    use InteractsWithCustomer;

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.portal.dashboard', [
            'customer' => $customer,
            'summary' => app(CustomerPortfolioService::class)->summary($customer),
            'recentActivity' => $customer->portalActivities()->customerVisible()->limit(8)->get(),
        ]);
    }
}
