<?php

declare(strict_types=1);

namespace App\Masters;

use App\Models\Masters\MasterModel;
use BackedEnum;
use Closure;

/**
 * Declarative description of one master-data field. Drives both the generic
 * form (App\Livewire\Masters\MasterForm) and the generic list table
 * (App\Livewire\Masters\MasterIndex) — no per-master Blade.
 */
final class Field
{
    /** @var array<string|int, string>|Closure():array<string|int,string>|null */
    private $options = null;

    private bool $inTable = true;

    private bool $required = false;

    private ?string $help = null;

    private ?string $placeholder = null;

    private ?string $suffix = null;

    private ?string $step = null;

    private ?string $tableLabel = null;

    /** @var Closure(MasterModel):(string|null)|null */
    private $displayUsing = null;

    private mixed $default = null;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type, // text|textarea|number|select|date|toggle
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    public static function textarea(string $key, string $label): self
    {
        return (new self($key, $label, 'textarea'))->hideFromTable();
    }

    public static function number(string $key, string $label, string $step = '1'): self
    {
        $f = new self($key, $label, 'number');
        $f->step = $step;

        return $f;
    }

    public static function sortOrder(): self
    {
        return self::number('sort_order', 'Sort order')->default(0)->hideFromTable();
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public static function toggle(string $key, string $label): self
    {
        return new self($key, $label, 'toggle');
    }

    /**
     * @param  array<string|int, string>|Closure():array<string|int,string>  $options
     */
    public static function select(string $key, string $label, array|Closure $options): self
    {
        $f = new self($key, $label, 'select');
        $f->options = $options;

        return $f;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function hideFromTable(): self
    {
        $this->inTable = false;

        return $this;
    }

    public function tableLabel(string $label): self
    {
        $this->tableLabel = $label;

        return $this;
    }

    /**
     * Custom renderer for this field's list cell.
     *
     * @param  Closure(MasterModel):(string|null)  $callback
     */
    public function display(Closure $callback): self
    {
        $this->displayUsing = $callback;

        return $this;
    }

    /** Render this field's value for a table cell. */
    public function renderCell(MasterModel $row): string
    {
        if ($this->displayUsing !== null) {
            return (string) (($this->displayUsing)($row) ?? '—');
        }

        $value = $row->getAttribute($this->key);

        if ($value === null || $value === '') {
            return '—';
        }

        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        return (string) $value;
    }

    public function isToggle(): bool
    {
        return $this->type === 'toggle';
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function showsInTable(): bool
    {
        return $this->inTable;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function getSuffix(): ?string
    {
        return $this->suffix;
    }

    public function getStep(): ?string
    {
        return $this->step;
    }

    public function getTableLabel(): string
    {
        return $this->tableLabel ?? $this->label;
    }

    /**
     * @return array<string|int, string>
     */
    public function resolveOptions(): array
    {
        if ($this->options instanceof Closure) {
            return ($this->options)();
        }

        return $this->options ?? [];
    }
}
