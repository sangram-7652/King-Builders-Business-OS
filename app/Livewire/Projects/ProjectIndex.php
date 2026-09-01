<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\ToggleProjectActive;
use App\Enums\ProjectStatus;
use App\Exceptions\DomainException;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Projects')]
class ProjectIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $city = '';

    #[Url]
    public string $active = '';

    #[Url]
    public string $sort = 'name';

    #[Url]
    public string $direction = 'asc';

    #[Url]
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'state', 'city', 'active', 'perPage'], true)) {
            $this->resetPage();
        }

        if ($property === 'state') {
            $this->city = '';
        }
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'state', 'city', 'active');
        $this->resetPage();
    }

    public function toggleActive(int $project): void
    {
        $model = Project::findOrFail($project);
        $this->authorize($model->is_active ? 'archive' : 'activate', $model);

        $updated = app(ToggleProjectActive::class)->handle($model);
        $this->dispatch('toast', message: $updated->is_active ? 'Project activated.' : 'Project archived.', variant: 'success');
    }

    public function delete(int $project): void
    {
        $model = Project::findOrFail($project);
        $this->authorize('delete', $model);

        try {
            app(DeleteProject::class)->handle($model);
            $this->dispatch('toast', message: 'Project deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * @return Builder<Project>
     */
    protected function query(): Builder
    {
        $sortable = ['name', 'code', 'status', 'created_at'];
        $sort = in_array($this->sort, $sortable, true) ? $this->sort : 'name';
        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        return Project::query()
            ->with(['state:id,name', 'city:id,name'])
            ->withCount('blocks')
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->state !== '', fn (Builder $q) => $q->where('state_id', $this->state))
            ->when($this->city !== '', fn (Builder $q) => $q->where('city_id', $this->city))
            ->when($this->active === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->active === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy($sort, $direction);
    }

    public function render(): View
    {
        return view('livewire.projects.project-index', [
            'projects' => $this->query()->paginate($this->perPage),
            'statuses' => ProjectStatus::options(),
            'states' => State::query()->orderBy('name')->pluck('name', 'id'),
            'cities' => $this->state !== ''
                ? City::query()->where('state_id', $this->state)->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }
}
