<div class="space-y-6">
    @php
        $crumbs = [['label' => 'Leads', 'url' => route('leads.index')]];
        $crumbs[] = $this->editing
            ? ['label' => $lead->name, 'url' => route('leads.show', $lead)]
            : ['label' => 'New lead'];
        if ($this->editing) $crumbs[] = ['label' => 'Edit'];
    @endphp
    <x-ui.breadcrumb :items="$crumbs" />

    <x-ui.page-header :title="$this->editing ? 'Edit lead' : 'New lead'" />

    {{-- Live duplicate warning --}}
    @if ($this->duplicates->isNotEmpty())
        <x-ui.alert variant="warning" title="Possible duplicate {{ Str::plural('lead', $this->duplicates->count()) }}">
            <p class="text-sm">A lead with this phone or email already exists. Review before creating a new one — records are never merged automatically.</p>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($this->duplicates as $dup)
                    <li class="flex items-center justify-between gap-3 rounded-md bg-(--surface) px-3 py-1.5">
                        <span>
                            <a href="{{ route('leads.show', $dup) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $dup->name }}</a>
                            <span class="text-(--content-muted)"> · {{ $dup->phone }} · {{ $dup->status->label() }} · {{ $dup->created_at->format('d M Y') }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Contact">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Name" wire:model="name" required :error="$errors->first('name')" />
                <x-ui.input label="Phone" wire:model.live.debounce.400ms="phone" required :error="$errors->first('phone')" />
                <x-ui.input type="email" label="Email" wire:model.live.debounce.400ms="email" :error="$errors->first('email')" />
                <x-ui.select label="Source" wire:model="lead_source_id" placeholder="—" :options="$sources->toArray()" :error="$errors->first('lead_source_id')" />
            </div>
        </x-ui.card>

        @if ($this->canAssign)
            <x-ui.card title="Assignment">
                <x-ui.select label="Assign to" wire:model="assigned_to" placeholder="Unassigned" :options="$users->toArray()" :error="$errors->first('assigned_to')" />
            </x-ui.card>
        @endif

        <x-ui.card title="Notes">
            <x-ui.textarea label="Notes" wire:model="notes" rows="4" :error="$errors->first('notes')" />
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="$this->editing ? route('leads.show', $lead) : route('leads.index')" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $this->editing ? 'Save changes' : 'Create lead' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>
</div>
