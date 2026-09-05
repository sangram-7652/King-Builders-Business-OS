<?php

declare(strict_types=1);

namespace App\Livewire\Partners;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Models\Partner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Channel Partners')]
class PartnerIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    #[Url]
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Partner::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type', 'perPage'], true)) {
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
        $this->reset('search', 'status', 'type');
        $this->resetPage();
    }

    /** @return Builder<Partner> */
    protected function query(): Builder
    {
        $sortable = ['created_at', 'name', 'status', 'partner_code'];
        $sort = in_array($this->sort, $sortable, true) ? $this->sort : 'created_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return Partner::query()
            ->with(['state:id,name', 'city:id,name'])
            ->withCount('activeProjectAuthorizations')
            ->search($this->search)
            ->status($this->status)
            ->type($this->type)
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function render(): View
    {
        return view('livewire.partners.partner-index', [
            'partners' => $this->query()->paginate($this->perPage),
            'statuses' => PartnerStatus::options(),
            'types' => PartnerType::options(),
        ]);
    }
}
