<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Actions\Blocks\DeleteBlock;
use App\Actions\Blocks\SaveBlock;
use App\Actions\Blocks\ToggleBlockActive;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Block management, nested inside the project detail "Blocks" tab.
 */
class ProjectBlocks extends Component
{
    public Project $project;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    // --- Block form fields ---------------------------------------------
    public string $name = '';

    public string $code = '';

    public string $description = '';

    public int $sort_order = 0;

    public bool $is_active = true;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function blocks()
    {
        return $this->project->blocks()
            ->search($this->search)
            ->ordered()
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('update', $this->project) ?? false;
    }

    public function openCreate(): void
    {
        $this->authorize('create', Block::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $block = $this->project->blocks()->findOrFail($id);
        $this->authorize('update', $block);

        $this->editingId = $block->id;
        $this->name = $block->name;
        $this->code = $block->code;
        $this->description = (string) $block->description;
        $this->sort_order = $block->sort_order;
        $this->is_active = $block->is_active;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('blocks', 'code')
                    ->where('project_id', $this->project->id)
                    ->ignore($this->editingId)
                    ->withoutTrashed(),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.unique' => 'A block with this code already exists in this project.',
            'code.regex' => 'The code may only contain letters, numbers and hyphens.',
        ];
    }

    public function saveBlock(): void
    {
        $block = $this->editingId !== null
            ? $this->project->blocks()->findOrFail($this->editingId)
            : null;

        $this->authorize($block ? 'update' : 'create', $block ?? Block::class);

        $data = $this->validate();

        app(SaveBlock::class)->handle($this->project, $data, $block);

        $this->dispatch('toast', message: $block ? 'Block updated.' : 'Block added.', variant: 'success');
        $this->closeForm();
        unset($this->blocks);
    }

    public function toggleBlock(int $id): void
    {
        $block = $this->project->blocks()->findOrFail($id);
        $this->authorize('toggleStatus', $block);

        app(ToggleBlockActive::class)->handle($block);
        $this->dispatch('toast', message: 'Block updated.', variant: 'success');
        unset($this->blocks);
    }

    public function deleteBlock(int $id): void
    {
        $block = $this->project->blocks()->findOrFail($id);
        $this->authorize('delete', $block);

        try {
            app(DeleteBlock::class)->handle($block);
            $this->dispatch('toast', message: 'Block deleted.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        unset($this->blocks);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'code', 'description', 'sort_order', 'is_active');
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.projects.project-blocks');
    }
}
