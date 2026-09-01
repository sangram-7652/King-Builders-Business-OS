<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Models\CollectionCase;
use App\Services\Collections\CollectionDashboardService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Collections')]
class CollectionDashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', CollectionCase::class);
    }

    public function render(): View
    {
        return view('livewire.collections.collection-dashboard', [
            'data' => app(CollectionDashboardService::class)->build(),
        ]);
    }
}
