<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Bookings')]
class BookingIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $project = '';

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'project'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'project');
        $this->resetPage();
    }

    /**
     * @return Builder<Booking>
     */
    protected function query(): Builder
    {
        $sortable = ['created_at', 'booking_number', 'booking_date', 'final_amount', 'status'];
        $sort = in_array($this->sort, $sortable, true) ? $this->sort : 'created_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return Booking::query()
            ->with(['project:id,name', 'plot:id,plot_number', 'primaryBookingBuyer.buyer:id,customer_code,first_name,middle_name,last_name'])
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->project !== '', fn (Builder $q) => $q->where('project_id', $this->project))
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function render(): View
    {
        return view('livewire.bookings.booking-index', [
            'bookings' => $this->query()->paginate($this->perPage),
            'statuses' => BookingStatus::options(),
            'projects' => Project::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
