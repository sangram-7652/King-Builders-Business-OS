<?php

declare(strict_types=1);

namespace App\Masters;

use App\Enums\Masters\MasterGroup;
use App\Models\Masters\MasterModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the generic Master Data screens need to render one master.
 * One concrete subclass per master table; no per-master controllers, Livewire
 * classes or Blade files.
 */
abstract class MasterResource
{
    /** @return class-string<MasterModel> */
    abstract public function model(): string;

    /** URL segment, e.g. "plot-categories". */
    abstract public function slug(): string;

    abstract public function singularLabel(): string;

    abstract public function pluralLabel(): string;

    abstract public function group(): MasterGroup;

    /**
     * Field definitions (drives the form and, where `showsInTable()`, the list).
     *
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * Validation rules keyed WITHOUT the `form.` prefix; MasterForm adds it.
     * `$id` is the record being updated (null on create) for unique-ignore;
     * `$state` is the current (unvalidated) form state for compound rules.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, array<int, mixed>|string>
     */
    abstract public function rules(?int $id, array $state = []): array;

    /** Permission prefix — all masters share the `masters.*` set (M2). */
    public function permissionPrefix(): string
    {
        return 'masters';
    }

    public function permission(string $ability): string
    {
        return $this->permissionPrefix().'.'.$ability;
    }

    /**
     * Extra list filters beyond the always-present status filter.
     * Each: ['key' => 'bank_id', 'label' => 'Bank', 'options' => [id => label]].
     *
     * @return list<array{key:string,label:string,options:array<int|string,string>}>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Eager loads for the list query.
     *
     * @return list<string>
     */
    public function with(): array
    {
        return [];
    }

    /**
     * Hook to constrain / order the base list query.
     *
     * @param  Builder<MasterModel>  $query
     */
    public function scopeList(Builder $query): void
    {
        $query->ordered();
    }

    /**
     * Convert a model into the flat form state used by MasterForm.
     *
     * @return array<string, mixed>
     */
    public function toFormState(MasterModel $model): array
    {
        $state = [];

        foreach ($this->fields() as $field) {
            $value = $model->getAttribute($field->key);

            $state[$field->key] = match ($field->type) {
                'toggle' => (bool) $value,
                'date' => $value?->format('Y-m-d'),
                'select' => $value instanceof \BackedEnum ? $value->value : $value,
                default => $value,
            };
        }

        return $state;
    }

    /**
     * Default form state for a new record.
     *
     * @return array<string, mixed>
     */
    public function defaultFormState(): array
    {
        $state = [];

        foreach ($this->fields() as $field) {
            $default = $field->getDefault();

            $state[$field->key] = match (true) {
                $default !== null => $default,
                $field->type === 'toggle' => $field->key === 'is_active',
                $field->type === 'number' => null,
                default => '',
            };
        }

        return $state;
    }

    /**
     * Convert validated form state into model attributes.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function toAttributes(array $state): array
    {
        $attributes = [];

        foreach ($this->fields() as $field) {
            if (! array_key_exists($field->key, $state)) {
                continue;
            }

            $value = $state[$field->key];

            $attributes[$field->key] = match ($field->type) {
                'toggle' => (bool) $value,
                'number' => $value === '' || $value === null ? $field->getDefault() : $value,
                'date' => $value ?: null,
                default => is_string($value) ? trim($value) : $value,
            };
        }

        return $attributes;
    }

    public function newModel(): MasterModel
    {
        $class = $this->model();

        return new $class;
    }

    /**
     * @return list<Field>
     */
    public function tableFields(): array
    {
        return array_values(array_filter($this->fields(), fn (Field $f) => $f->showsInTable()));
    }
}
