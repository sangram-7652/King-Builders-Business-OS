<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Actions\Projects\SaveProject;
use App\Exceptions\DomainException;
use App\Models\Masters\City;
use App\Models\Masters\State;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Project')]
class ProjectForm extends Component
{
    public ?Project $project = null;

    // --- Bound fields ------------------------------------------------
    public string $name = '';

    public string $code = '';

    public string $description = '';

    public string $address = '';

    public string $state_id = '';

    public string $city_id = '';

    public string $pincode = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $contact_name = '';

    public string $contact_phone = '';

    public string $contact_email = '';

    public string $logo_path = '';

    public string $cover_image_path = '';

    public string $launch_date = '';

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            $this->authorize('update', $project);
            $this->project = $project;

            foreach ([
                'name', 'code', 'description', 'address', 'pincode',
                'contact_name', 'contact_phone', 'contact_email',
                'logo_path', 'cover_image_path',
            ] as $field) {
                $this->{$field} = (string) $project->{$field};
            }

            $this->state_id = (string) ($project->state_id ?? '');
            $this->city_id = (string) ($project->city_id ?? '');
            $this->latitude = $project->latitude !== null ? (string) $project->latitude : '';
            $this->longitude = $project->longitude !== null ? (string) $project->longitude : '';
            $this->launch_date = $project->launch_date?->format('Y-m-d') ?? '';
        } else {
            $this->authorize('create', Project::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->project !== null;
    }

    #[Computed]
    public function cities()
    {
        if ($this->state_id === '') {
            return collect();
        }

        return City::query()->where('state_id', $this->state_id)->orderBy('name')->pluck('name', 'id');
    }

    public function updatedStateId(): void
    {
        $this->city_id = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $id = $this->project?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('projects', 'code')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'state_id' => ['required', Rule::exists('states', 'id')->withoutTrashed()],
            'city_id' => [
                'nullable',
                Rule::exists('cities', 'id')->where('state_id', $this->state_id ?: null)->withoutTrashed(),
            ],
            'pincode' => ['nullable', 'string', 'max:12'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'cover_image_path' => ['nullable', 'string', 'max:2048'],
            'launch_date' => ['nullable', 'date'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.regex' => 'The code may only contain letters, numbers and hyphens.',
            'city_id.exists' => 'The selected city does not belong to the selected state.',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        try {
            $project = app(SaveProject::class)->handle($data, $this->project);
        } catch (DomainException $e) {
            $this->addError('city_id', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: $this->editing ? 'Project updated.' : 'Project created.', variant: 'success');

        $this->redirectRoute('projects.show', ['project' => $project->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.projects.project-form', [
            'states' => State::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
