<?php

declare(strict_types=1);

namespace App\Livewire\Commission;

use App\Actions\Commission\ArchiveCommissionScheme;
use App\Actions\Commission\CreateCommissionSchemeVersion;
use App\Actions\Commission\PublishCommissionScheme;
use App\Actions\Commission\SaveCommissionRule;
use App\Enums\CommissionCalcType;
use App\Enums\SlabMode;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CommissionSchemeShow extends Component
{
    public CommissionScheme $scheme;

    // --- rule editor state ---------------------------------------------
    public bool $showRuleForm = false;

    public string $ruleProjectId = '';

    public string $ruleCalcType = 'percentage';

    public string $ruleRate = '';

    public string $ruleFlatAmount = '';

    public string $ruleSlabMode = 'whole';

    public string $ruleMinAmount = '';

    public string $ruleMaxAmount = '';

    /** @var list<array{from_amount: string, to_amount: string, calc_type: string, rate: string, flat_amount: string}> */
    public array $slabRows = [];

    public function mount(CommissionScheme $scheme): void
    {
        $this->authorize('view', $scheme);
        $this->scheme = $scheme;
    }

    private function refresh(): void
    {
        $this->scheme = $this->scheme->fresh();
    }

    // --- lifecycle ----------------------------------------------------

    public function publish(): void
    {
        $this->authorize('publish', $this->scheme);
        $this->run(fn () => app(PublishCommissionScheme::class)->handle($this->scheme, auth()->user()), 'Scheme published.');
    }

    public function archive(): void
    {
        $this->authorize('archive', $this->scheme);
        $this->run(fn () => app(ArchiveCommissionScheme::class)->handle($this->scheme, auth()->user()), 'Scheme archived.');
    }

    public function newVersion(): void
    {
        $this->authorize('createVersion', $this->scheme);

        try {
            $copy = app(CreateCommissionSchemeVersion::class)->handle($this->scheme, auth()->user());
            $this->dispatch('toast', message: "Draft v{$copy->version} created.", variant: 'success');
            $this->redirectRoute('commission-schemes.show', ['scheme' => $copy->id], navigate: true);
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- rule editor ------------------------------------------------

    public function editRule(?int $ruleId = null): void
    {
        $this->authorize('manageRules', $this->scheme);
        $this->resetRuleForm();

        if ($ruleId !== null) {
            $rule = $this->scheme->rules()->with('slabs')->findOrFail($ruleId);
            $this->ruleProjectId = (string) ($rule->project_id ?? '');
            $this->ruleCalcType = $rule->calc_type->value;
            $this->ruleRate = (string) ($rule->rate ?? '');
            $this->ruleFlatAmount = (string) ($rule->flat_amount ?? '');
            $this->ruleSlabMode = $rule->slab_mode?->value ?? 'whole';
            $this->ruleMinAmount = (string) ($rule->min_amount ?? '');
            $this->ruleMaxAmount = (string) ($rule->max_amount ?? '');
            $this->slabRows = $rule->slabs->map(fn ($s) => [
                'from_amount' => (string) $s->from_amount,
                'to_amount' => (string) ($s->to_amount ?? ''),
                'calc_type' => $s->calc_type->value,
                'rate' => (string) ($s->rate ?? ''),
                'flat_amount' => (string) ($s->flat_amount ?? ''),
            ])->values()->all();
        }

        $this->showRuleForm = true;
    }

    public function addSlabRow(): void
    {
        $this->slabRows[] = ['from_amount' => '', 'to_amount' => '', 'calc_type' => 'percentage', 'rate' => '', 'flat_amount' => ''];
    }

    public function removeSlabRow(int $index): void
    {
        unset($this->slabRows[$index]);
        $this->slabRows = array_values($this->slabRows);
    }

    public function saveRule(): void
    {
        $this->authorize('manageRules', $this->scheme);

        $this->validate([
            'ruleProjectId' => ['nullable', 'integer', 'exists:projects,id'],
            'ruleCalcType' => ['required', Rule::enum(CommissionCalcType::class)],
        ]);

        try {
            app(SaveCommissionRule::class)->handle($this->scheme, [
                'project_id' => $this->ruleProjectId !== '' ? (int) $this->ruleProjectId : null,
                'calc_type' => $this->ruleCalcType,
                'rate' => $this->ruleRate ?: null,
                'flat_amount' => $this->ruleFlatAmount ?: null,
                'slab_mode' => $this->ruleSlabMode,
                'min_amount' => $this->ruleMinAmount ?: null,
                'max_amount' => $this->ruleMaxAmount ?: null,
                'slabs' => $this->slabRows,
            ], auth()->user());

            $this->resetRuleForm();
            $this->showRuleForm = false;
            $this->refresh();
            $this->dispatch('toast', message: 'Rule saved.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function deleteRule(int $ruleId): void
    {
        $this->authorize('manageRules', $this->scheme);
        $rule = $this->scheme->rules()->findOrFail($ruleId);

        if ($rule->project_id === null) {
            $this->dispatch('toast', message: 'The default rule cannot be deleted while the scheme is a draft.', variant: 'danger');

            return;
        }

        $rule->delete();
        $this->refresh();
        $this->dispatch('toast', message: 'Project rule removed.', variant: 'success');
    }

    private function resetRuleForm(): void
    {
        $this->reset(
            'ruleProjectId', 'ruleCalcType', 'ruleRate', 'ruleFlatAmount',
            'ruleSlabMode', 'ruleMinAmount', 'ruleMaxAmount', 'slabRows', 'showRuleForm',
        );
        $this->ruleCalcType = 'percentage';
        $this->ruleSlabMode = 'whole';
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->refresh();
            $this->dispatch('toast', message: $ok, variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $scheme = $this->scheme->load([
            'rules.project:id,name', 'rules.slabs', 'createdBy', 'publishedBy', 'versions',
        ]);

        return view('livewire.commission.commission-scheme-show', [
            'scheme' => $scheme,
            'calcTypes' => CommissionCalcType::options(),
            'slabModes' => SlabMode::options(),
            'projects' => Project::query()->orderBy('name')->pluck('name', 'id'),
        ])->title("{$scheme->name} · v{$scheme->version}");
    }
}
