<?php

declare(strict_types=1);

namespace App\Livewire\Registry;

use App\Enums\RegistryCaseStatus;
use App\Models\RegistryCase;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Registry')]
class RegistryDashboard extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', RegistryCase::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $counts = RegistryCase::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $cases = RegistryCase::query()
            ->with(['booking:id,booking_number,project_id,plot_id', 'booking.project:id,name', 'booking.plot:id,plot_number', 'liveAppointment'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where('case_number', 'like', "%{$this->search}%")
                ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'like', "%{$this->search}%")))
            ->orderByRaw("case status
                when 'scheduled' then 0 when 'in_process' then 1 when 'ready' then 2
                when 'eligibility_pending' then 3 when 'on_hold' then 4 else 5 end")
            ->latest('id')
            ->paginate(20);

        return view('livewire.registry.registry-dashboard', [
            'counts' => $counts,
            'cases' => $cases,
            'statuses' => RegistryCaseStatus::options(),
        ]);
    }
}
