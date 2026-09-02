@php use App\Enums\BuyerStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Buyers', 'url' => route('buyers.index')],
        ['label' => $buyer->fullName()],
    ]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $buyer->fullName() }}</h1>
                <x-ui.badge :variant="$buyer->status->color()">{{ $buyer->status->label() }}</x-ui.badge>
            </div>
            <p class="mt-1 font-mono text-sm text-(--content-muted)">{{ $buyer->customer_code }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('changeStatus', $buyer)
                <div x-data="{ open: false }" class="relative">
                    <x-ui.button variant="secondary" size="sm" x-on:click="open = !open" @click.outside="open = false">
                        Status <x-app.icon name="chevron-down" class="size-3.5" />
                    </x-ui.button>
                    <div x-show="open" x-transition style="display:none" class="absolute right-0 z-10 mt-1 w-40 rounded-lg border border-(--border) bg-(--surface) p-1 shadow-lg">
                        @foreach ($allowedStatuses as $s)
                            <button type="button" wire:click="changeStatus('{{ $s->value }}')" x-on:click="open = false"
                                class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-(--surface-muted)">→ {{ $s->label() }}</button>
                        @endforeach
                    </div>
                </div>
            @endcan
            @if (auth()->user()?->can('documents.view'))
                <x-ui.button size="sm" variant="secondary" :href="route('buyers.documents', $buyer)" wire:navigate>KYC documents</x-ui.button>
            @endif
            @can('update', $buyer)
                <x-ui.button size="sm" :href="route('buyers.edit', $buyer)" wire:navigate>Edit</x-ui.button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Personal" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Phone</dt><dd class="mt-0.5">{{ $buyer->phone }}</dd></div>
                <div><dt class="text-(--content-muted)">Alternate phone</dt><dd class="mt-0.5">{{ $buyer->alternate_phone ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Email</dt><dd class="mt-0.5">{{ $buyer->email ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Date of birth</dt><dd class="mt-0.5">{{ $buyer->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Gender</dt><dd class="mt-0.5">{{ $buyer->gender?->label() ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Occupation</dt><dd class="mt-0.5">{{ $buyer->occupation ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Address">
            <dl class="space-y-2 text-sm">
                <div><dt class="text-(--content-muted)">Address</dt><dd>{{ $buyer->address ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">City / State</dt><dd>{{ $buyer->locationLabel() }}</dd></div>
                <div><dt class="text-(--content-muted)">Pincode</dt><dd>{{ $buyer->pincode ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>
    </div>

    {{-- Sensitive identifiers --}}
    <x-ui.card title="KYC identifiers">
        <x-slot:actions>
            @if ($canViewDocuments && ($pan || $aadhaar))
                <x-ui.button variant="ghost" size="sm" wire:click="toggleReveal">
                    {{ $revealDocuments ? 'Hide' : 'Reveal' }}
                </x-ui.button>
            @endif
        </x-slot:actions>

        @if (! $pan && ! $aadhaar)
            <p class="text-sm text-(--content-muted)">No KYC identifiers on record.</p>
        @else
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">PAN</dt><dd class="mt-0.5 font-mono">{{ $pan ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Aadhaar</dt><dd class="mt-0.5 font-mono">{{ $aadhaar ?? '—' }}</dd></div>
            </dl>
            @unless ($canViewDocuments)
                <p class="mt-2 text-xs text-(--content-muted)">You do not have permission to view the full values.</p>
            @endunless
        @endif
    </x-ui.card>

    {{-- Collection profile (M8) --}}
    @if ($collectionProfile)
        <x-ui.card title="Collection profile" subtitle="Across all confirmed bookings where this buyer is primary. Figures from the M7 ledger.">
            <dl class="grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                <div><dt class="text-(--content-muted)">Total bookings</dt><dd class="mt-0.5 text-lg font-semibold">{{ $collectionProfile['total_bookings'] }}</dd></div>
                <div><dt class="text-(--content-muted)">Total value</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">₹{{ number_format((float) $collectionProfile['total_value'], 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Total paid</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">₹{{ number_format((float) $collectionProfile['total_paid'], 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Outstanding</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">₹{{ number_format((float) $collectionProfile['total_outstanding'], 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Overdue</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-red-600">₹{{ number_format((float) $collectionProfile['total_overdue'], 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Open promises</dt><dd class="mt-0.5 text-lg font-semibold">{{ $collectionProfile['open_promises'] }}</dd></div>
                <div><dt class="text-(--content-muted)">Broken promises</dt><dd class="mt-0.5 text-lg font-semibold">{{ $collectionProfile['broken_promises'] }}</dd></div>
                <div>
                    <dt class="text-(--content-muted)">Last payment</dt>
                    <dd class="mt-0.5">
                        @if ($collectionProfile['last_payment_date'])
                            ₹{{ number_format((float) $collectionProfile['last_payment_amount'], 2) }}
                            <span class="text-(--content-muted)">on {{ \Illuminate\Support\Carbon::parse($collectionProfile['last_payment_date'])->format('d M Y') }}</span>
                        @else — @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    @endif

    {{-- Ownership & transfers (M10) --}}
    @if ($ownerships->isNotEmpty() || $transfers->isNotEmpty() || $nominees->isNotEmpty())
        <x-ui.card title="Ownership, transfers & nominees">
            @if ($ownerships->isNotEmpty())
                <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Plot ownership</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($ownerships as $o)
                        <li>Plot {{ $o->plot?->plot_number ?? '—' }} · {{ $o->booking?->booking_number }} ·
                            {{ rtrim(rtrim(number_format((float) $o->ownership_percentage, 2), '0'), '.') }}% · {{ $o->ownership_type->label() }}
                            <span class="text-(--content-muted)">
                                {{ $o->started_at?->format('d M Y') }} – {{ $o->ended_at?->format('d M Y') ?? 'present' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($transfers->isNotEmpty())
                <p class="mt-3 text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Transfers</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($transfers as $t)
                        <li>{{ $t->request_number }} · {{ $t->transfer_type->label() }} · {{ $t->booking?->booking_number }}
                            <x-ui.badge size="sm" :variant="$t->status->color()">{{ $t->status->label() }}</x-ui.badge>
                            <span class="text-(--content-muted)">{{ $t->new_buyer_id === $buyer->id ? 'incoming' : 'outgoing' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($nominees->isNotEmpty())
                <p class="mt-3 text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Nominees</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($nominees as $n)
                        <li>{{ $n->name }} <span class="text-(--content-muted)">· {{ $n->relation ?? '—' }} · {{ ucfirst($n->status) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    {{-- Converted from --}}
    <x-ui.card title="Origin">
        @if ($buyer->leads->isEmpty())
            <p class="text-sm text-(--content-muted)">Created directly (not from a lead).</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($buyer->leads as $l)
                    <li>
                        <a href="{{ route('leads.show', $l) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $l->name }}</a>
                        <span class="text-(--content-muted)"> · converted {{ $l->converted_at?->format('d M Y') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
