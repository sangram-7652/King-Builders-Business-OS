<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Actions\Masters\SaveMaster;
use App\Masters\MasterRegistry;
use App\Masters\MasterResource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class MasterForm extends Component
{
    public string $resource = '';

    public ?int $recordId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(string $resource, ?int $record = null): void
    {
        $config = MasterRegistry::findOrFail($resource);
        $this->resource = $config->slug();

        if ($record !== null) {
            $model = $config->model()::query()->findOrFail($record);
            $this->authorize('update', $model);
            $this->recordId = $model->getKey();
            $this->form = $config->toFormState($model);
        } else {
            $this->authorize('create', $config->model());
            $this->form = $config->defaultFormState();
        }
    }

    #[Computed]
    public function config(): MasterResource
    {
        return MasterRegistry::findOrFail($this->resource);
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->recordId !== null;
    }

    public function save()
    {
        $config = $this->config();

        $validated = Validator::make(
            $this->form,
            $config->rules($this->recordId, $this->form),
            [],
            $this->attributeLabels(),
        )->validate();

        $model = $this->recordId !== null
            ? $config->model()::query()->findOrFail($this->recordId)
            : null;

        $this->authorize($model ? 'update' : 'create', $model ?? $config->model());

        app(SaveMaster::class)->handle($config, $validated, $model);

        $this->dispatch('toast', message: $config->singularLabel().($this->editing ? ' updated.' : ' created.'), variant: 'success');

        $this->redirectRoute('masters.index', ['resource' => $config->slug()], navigate: true);
    }

    /**
     * @return array<string, string>
     */
    private function attributeLabels(): array
    {
        $labels = [];
        foreach ($this->config()->fields() as $field) {
            $labels[$field->key] = strtolower($field->label);
        }

        return $labels;
    }

    public function render(): View
    {
        return view('livewire.masters.form', [
            'config' => $this->config(),
        ])->title(($this->editing ? 'Edit ' : 'New ').strtolower($this->config()->singularLabel()));
    }
}
