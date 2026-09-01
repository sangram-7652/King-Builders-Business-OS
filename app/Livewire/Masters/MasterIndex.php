<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Actions\Masters\DeleteMaster;
use App\Actions\Masters\ToggleMasterStatus;
use App\Exceptions\DomainException;
use App\Masters\MasterRegistry;
use App\Masters\MasterResource;
use App\Models\Masters\MasterModel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class MasterIndex extends Component
{
    use WithPagination;

    public string $resource = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    /** @var array<string, string> */
    #[Url]
    public array $filter = [];

    #[Url]
    public int $perPage = 15;

    public function mount(string $resource): void
    {
        $this->resource = MasterRegistry::findOrFail($resource)->slug();
        $this->authorize('viewAny', $this->config()->model());
    }

    #[Computed]
    public function config(): MasterResource
    {
        return MasterRegistry::findOrFail($this->resource);
    }

    public function updated(string $property): void
    {
        if ($property === 'search' || $property === 'status' || str_starts_with($property, 'filter.')) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'filter');
        $this->resetPage();
    }

    public function toggleStatus(int $id): void
    {
        $model = $this->findModel($id);
        $this->authorize('toggleStatus', $model);

        $updated = app(ToggleMasterStatus::class)->handle($model);

        $this->dispatch('toast', message: $updated->is_active ? 'Activated.' : 'Deactivated.', variant: 'success');
    }

    public function delete(int $id): void
    {
        $model = $this->findModel($id);
        $this->authorize('delete', $model);

        try {
            app(DeleteMaster::class)->handle($model);
            $this->dispatch('toast', message: $this->config()->singularLabel().' deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    private function findModel(int $id): MasterModel
    {
        return $this->config()->model()::query()->findOrFail($id);
    }

    /**
     * @return Builder<MasterModel>
     */
    protected function query(): Builder
    {
        $config = $this->config();

        /** @var Builder<MasterModel> $query */
        $query = $config->model()::query()->with($config->with());

        $query->search($this->search);

        if ($this->status === 'active') {
            $query->where('is_active', true);
        } elseif ($this->status === 'inactive') {
            $query->where('is_active', false);
        }

        foreach ($config->filters() as $filter) {
            $value = $this->filter[$filter['key']] ?? '';
            if ($value !== '') {
                $query->where($filter['key'], $value);
            }
        }

        $config->scopeList($query);

        return $query;
    }

    public function render(): View
    {
        return view('livewire.masters.index', [
            'config' => $this->config(),
            'rows' => $this->query()->paginate($this->perPage),
        ])->title($this->config()->pluralLabel());
    }
}
