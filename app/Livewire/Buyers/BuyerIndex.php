<?php

declare(strict_types=1);

namespace App\Livewire\Buyers;

use App\Actions\Buyers\ChangeBuyerStatus;
use App\Enums\BuyerStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Buyers')]
class BuyerIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    #[Url]
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Buyer::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'perPage'], true)) {
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
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function setStatus(int $buyer, string $status): void
    {
        $model = Buyer::findOrFail($buyer);
        $this->authorize('changeStatus', $model);

        $target = BuyerStatus::tryFrom($status);
        if ($target === null) {
            return;
        }

        try {
            app(ChangeBuyerStatus::class)->handle($model, $target);
            $this->dispatch('toast', message: "Buyer {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function delete(int $buyer): void
    {
        $model = Buyer::findOrFail($buyer);
        $this->authorize('delete', $model);

        try {
            $model->delete();
            $this->dispatch('toast', message: 'Buyer deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * @return Builder<Buyer>
     */
    protected function query(): Builder
    {
        $sortable = ['created_at', 'customer_code', 'first_name', 'status'];
        $sort = in_array($this->sort, $sortable, true) ? $this->sort : 'created_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return Buyer::query()
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');
    }

    public function render(): View
    {
        return view('livewire.buyers.buyer-index', [
            'buyers' => $this->query()->paginate($this->perPage),
            'statuses' => BuyerStatus::options(),
        ]);
    }
}
