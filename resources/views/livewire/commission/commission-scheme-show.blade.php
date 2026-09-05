@php use App\Enums\CommissionCalcType; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Commission schemes', 'url' => route('commission-schemes.index')],
        ['label' => $scheme->name.' · v'.$scheme->version],
    ]" />

    <x-ui.page-header :title="$scheme->name" :description="$scheme->code.' · version '.$scheme->version">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge :variant="$scheme->status->color()">{{ $scheme->status->label() }}</x-ui.badge>
                @can('update', $scheme)
                    <x-ui.button variant="secondary" size="sm" :href="route('commission-schemes.edit', $scheme)" wire:navigate>Edit</x-ui.button>
                @endcan
                @can('publish', $scheme)
                    <x-ui.button size="sm" wire:click="publish" wire:confirm="Publish this version? It becomes immutable and replaces any version in force.">Publish</x-ui.button>
                @endcan
                @can('createVersion', $scheme)
                    @if (! $scheme->isDraft())
                        <x-ui.button variant="secondary" size="sm" wire:click="newVersion" wire:confirm="Start a new draft version from this one?">New version</x-ui.button>
                    @endif
                @endcan
                @can('archive', $scheme)
                    <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="archive" wire:confirm="Archive this version?">Archive</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($scheme->status->isImmutable())
        <x-ui.alert variant="info" title="Frozen version">
            This version is {{ $scheme->status->label() }} and can no longer be edited. Create a new version to change the numbers — existing commission snapshots keep referencing this one.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Definition" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Basis</dt><dd>{{ $scheme->basis->label() }}</dd></div>
                <div><dt class="text-(--content-muted)">Auto-match type</dt><dd>{{ $scheme->partner_type?->label() ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Default fallback</dt><dd>{{ $scheme->is_default ? 'Yes' : 'No' }}</dd></div>
                <div><dt class="text-(--content-muted)">Effective</dt><dd>{{ $scheme->effective_from?->format('d M Y') ?? '—' }}{{ $scheme->effective_to ? ' → '.$scheme->effective_to->format('d M Y') : '' }}</dd></div>
                <div><dt class="text-(--content-muted)">Created by</dt><dd>{{ $scheme->createdBy?->name ?? 'System' }}</dd></div>
                <div><dt class="text-(--content-muted)">Published by</dt><dd>{{ $scheme->publishedBy?->name ?? '—' }} {{ $scheme->published_at ? '· '.$scheme->published_at->format('d M Y') : '' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Description</dt><dd class="whitespace-pre-line">{{ $scheme->description ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Versions">
            <ol class="space-y-1 text-sm">
                @foreach ($scheme->versions as $v)
                    <li wire:key="v-{{ $v->id }}" class="flex items-center justify-between gap-2">
                        <a href="{{ route('commission-schemes.show', $v) }}" wire:navigate
                           @class(['font-medium' => $v->id === $scheme->id, 'text-(--brand-primary) hover:underline' => $v->id !== $scheme->id])>v{{ $v->version }}</a>
                        <x-ui.badge :variant="$v->status->color()" size="sm">{{ $v->status->label() }}</x-ui.badge>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    </div>

    <x-ui.card title="Calculation rules" subtitle="One default rule (no project) plus optional per-project overrides.">
        <x-slot:actions>
            @can('manageRules', $scheme)
                <x-ui.button size="sm" wire:click="editRule">Add / edit rule</x-ui.button>
            @endcan
        </x-slot:actions>

        @if ($scheme->rules->isEmpty())
            <x-ui.empty-state icon="inbox" title="No rule yet" description="Add a default rule before publishing." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Scope</th><th class="py-2 pr-4">Type</th><th class="py-2 pr-4">Rate / amount</th><th class="py-2 pr-4">Floor / cap</th><th class="py-2 pr-4 text-right">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($scheme->rules as $rule)
                            <tr wire:key="rule-{{ $rule->id }}">
                                <td class="py-2 pr-4 font-medium">{{ $rule->project?->name ?? 'Default (all projects)' }}</td>
                                <td class="py-2 pr-4">{{ $rule->calc_type->label() }}</td>
                                <td class="py-2 pr-4 tabular-nums">
                                    @if ($rule->calc_type === CommissionCalcType::Percentage)
                                        {{ rtrim(rtrim(number_format((float) $rule->rate, 4), '0'), '.') }}%
                                    @elseif ($rule->calc_type === CommissionCalcType::Fixed)
                                        ₹{{ number_format((float) $rule->flat_amount, 2) }}
                                    @else
                                        {{ $rule->slabs->count() }} brackets · {{ $rule->slab_mode?->label() }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4 tabular-nums text-(--content-muted)">
                                    {{ $rule->min_amount ? '₹'.number_format((float) $rule->min_amount, 0) : '—' }}
                                    /
                                    {{ $rule->max_amount ? '₹'.number_format((float) $rule->max_amount, 0) : '—' }}
                                </td>
                                <td class="py-2 pr-4">
                                    <div class="flex justify-end gap-1">
                                        @can('manageRules', $scheme)
                                            <x-ui.button variant="ghost" size="sm" wire:click="editRule({{ $rule->id }})">Edit</x-ui.button>
                                            @if ($rule->project_id)
                                                <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Remove this project override?">✕</x-ui.button>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @if ($rule->calc_type === CommissionCalcType::Slab && $rule->slabs->isNotEmpty())
                                <tr wire:key="slabs-{{ $rule->id }}" class="bg-(--surface-muted)/40">
                                    <td colspan="5" class="px-4 py-2">
                                        <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-(--content-muted)">
                                            @foreach ($rule->slabs as $slab)
                                                <span>
                                                    ₹{{ number_format((float) $slab->from_amount, 0) }}–{{ $slab->to_amount ? '₹'.number_format((float) $slab->to_amount, 0) : '∞' }}:
                                                    @if ($slab->calc_type === CommissionCalcType::Percentage){{ rtrim(rtrim(number_format((float) $slab->rate, 4), '0'), '.') }}%@else ₹{{ number_format((float) $slab->flat_amount, 2) }}@endif
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Rule editor drawer --}}
    @if ($showRuleForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="rule-form">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="$set('showRuleForm', false)"></div>
            <div class="relative w-full max-w-lg overflow-y-auto rounded-xl border border-(--border) bg-(--surface) shadow-xl" style="max-height: 90vh">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Calculation rule</h3></div>
                <form wire:submit="saveRule" class="space-y-4 px-5 py-4">
                    <x-ui.select label="Scope" wire:model="ruleProjectId" placeholder="Default (all projects)" :options="$projects->toArray()" :error="$errors->first('ruleProjectId')" />
                    <x-ui.select label="Type" wire:model.live="ruleCalcType" :options="$calcTypes" :placeholder="null" :error="$errors->first('ruleCalcType')" />

                    @if ($ruleCalcType === 'percentage')
                        <x-ui.input label="Rate %" type="number" step="0.0001" wire:model="ruleRate" />
                    @elseif ($ruleCalcType === 'fixed')
                        <x-ui.input label="Fixed amount ₹" type="number" step="0.01" wire:model="ruleFlatAmount" />
                    @else
                        <x-ui.select label="Slab mode" wire:model="ruleSlabMode" :options="$slabModes" :placeholder="null" />
                        <div class="space-y-2">
                            @foreach ($slabRows as $i => $row)
                                <div wire:key="slab-{{ $i }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-(--border) p-2">
                                    <div class="w-24"><x-ui.input label="From ₹" type="number" wire:model="slabRows.{{ $i }}.from_amount" /></div>
                                    <div class="w-24"><x-ui.input label="To ₹" type="number" wire:model="slabRows.{{ $i }}.to_amount" placeholder="∞" /></div>
                                    <div class="w-28"><x-ui.select label="Type" wire:model.live="slabRows.{{ $i }}.calc_type" :options="['percentage' => '%','fixed' => '₹']" :placeholder="null" /></div>
                                    <div class="w-20"><x-ui.input label="Value" type="number" step="0.0001" wire:model="slabRows.{{ $i }}.{{ $row['calc_type'] === 'fixed' ? 'flat_amount' : 'rate' }}" /></div>
                                    <x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removeSlabRow({{ $i }})">✕</x-ui.button>
                                </div>
                            @endforeach
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="addSlabRow">Add bracket</x-ui.button>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input label="Min commission ₹" type="number" step="0.01" wire:model="ruleMinAmount" />
                        <x-ui.input label="Max commission ₹" type="number" step="0.01" wire:model="ruleMaxAmount" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showRuleForm', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save rule</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
