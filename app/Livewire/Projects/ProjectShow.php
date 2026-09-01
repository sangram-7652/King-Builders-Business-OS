<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Actions\Projects\ChangeProjectStatus;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\ToggleProjectActive;
use App\Enums\ProjectStatus;
use App\Exceptions\DomainException;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ProjectShow extends Component
{
    public Project $project;

    #[Url]
    public string $tab = 'overview';

    /** @var list<string> */
    public array $tabs = ['overview', 'location', 'blocks', 'activity'];

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project->load(['state', 'city']);

        if (! in_array($this->tab, $this->tabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs, true) ? $tab : 'overview';
    }

    public function changeStatus(string $status): void
    {
        $this->authorize('changeStatus', $this->project);

        $target = ProjectStatus::tryFrom($status);

        if ($target === null) {
            $this->dispatch('toast', message: 'Unknown status.', variant: 'danger');

            return;
        }

        try {
            app(ChangeProjectStatus::class)->handle($this->project, $target);
            $this->project->refresh();
            $this->dispatch('toast', message: "Status changed to {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function toggleActive(): void
    {
        $this->authorize($this->project->is_active ? 'archive' : 'activate', $this->project);

        app(ToggleProjectActive::class)->handle($this->project);
        $this->project->refresh();

        $this->dispatch('toast', message: $this->project->is_active ? 'Project activated.' : 'Project archived.', variant: 'success');
    }

    public function delete()
    {
        $this->authorize('delete', $this->project);

        try {
            app(DeleteProject::class)->handle($this->project);
            $this->dispatch('toast', message: 'Project deleted.', variant: 'success');

            return $this->redirectRoute('projects.index', navigate: true);
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');

            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.projects.project-show', [
            'allowedTransitions' => $this->project->status->allowedTransitions(),
            'blockStats' => [
                'total' => $this->project->blocks()->count(),
                'active' => $this->project->blocks()->where('is_active', true)->count(),
            ],
        ])->title($this->project->name);
    }
}
