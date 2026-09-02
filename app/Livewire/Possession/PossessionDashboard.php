<?php

declare(strict_types=1);

namespace App\Livewire\Possession;

use App\Enums\PossessionCaseStatus;
use App\Models\PossessionCase;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Possession')]
class PossessionDashboard extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PossessionCase::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $counts = PossessionCase::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $cases = PossessionCase::query()
            ->with(['booking:id,booking_number,project_id,plot_id', 'booking.project:id,name', 'plot:id,plot_number', 'liveAppointment'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where('case_number', 'like', "%{$this->search}%")
                ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'like', "%{$this->search}%")))
            ->orderByRaw("case status
                when 'ready_for_handover' then 0 when 'inspection' then 1 when 'scheduled' then 2
                when 'ready' then 3 when 'eligibility_pending' then 4 when 'on_hold' then 5 else 6 end")
            ->latest('id')
            ->paginate(20);

        return view('livewire.possession.possession-dashboard', [
            'counts' => $counts,
            'cases' => $cases,
            'statuses' => PossessionCaseStatus::options(),
        ]);
    }
}
