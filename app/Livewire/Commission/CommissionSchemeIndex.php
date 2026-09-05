<?php

declare(strict_types=1);

namespace App\Livewire\Commission;

use App\Enums\CommissionSchemeStatus;
use App\Models\CommissionScheme;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Commission schemes')]
class CommissionSchemeIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CommissionScheme::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    /** @return Builder<CommissionScheme> */
    protected function query(): Builder
    {
        return CommissionScheme::query()
            ->with('createdBy:id,name')
            ->withCount('rules')
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->orderBy('code')
            ->orderByDesc('version');
    }

    public function render(): View
    {
        return view('livewire.commission.commission-scheme-index', [
            'schemes' => $this->query()->paginate(20),
            'statuses' => CommissionSchemeStatus::options(),
        ]);
    }
}
