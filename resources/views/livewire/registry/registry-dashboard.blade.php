@php use App\Enums\RegistryCaseStatus as S; @endphp
<div class="space-y-6">
    <x-ui.page-header title="Registry dashboard" description="Registry cases by stage. Eligibility comes from the shared engine." />

    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <x-ui.stat-card label="Ready" :value="$counts[S::Ready->value] ?? 0" />
        <x-ui.stat-card label="Scheduled" :value="$counts[S::Scheduled->value] ?? 0" />
        <x-ui.stat-card label="In process" :value="$counts[S::InProcess->value] ?? 0" />
        <x-ui.stat-card label="Completed" :value="$counts[S::Completed->value] ?? 0" />
        <x-ui.stat-card label="On hold" :value="$counts[S::OnHold->value] ?? 0" trend="down" />
    </div>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:items-end">
            <div class="min-w-44 flex-1"><x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Case or booking number…" /></div>
            <div class="w-full sm:w-48"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
        </div>

        @if ($cases->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="building" title="No registry cases" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="px-4 py-3">Case</th><th class="px-4 py-3">Booking / Plot</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Appointment</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($cases as $case)
                            <tr wire:key="rc-{{ $case->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('registry.booking', $case->booking) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $case->case_number }}</a>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $case->booking?->booking_number }}<div class="text-xs">{{ $case->booking?->project?->name }} · Plot {{ $case->booking?->plot?->plot_number }}</div>
                                </td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $case->liveAppointment?->scheduled_at?->format('d M Y H:i') ?? '—' }}
                                    @if ($case->liveAppointment)<div class="text-xs">{{ $case->liveAppointment->registry_office }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $cases->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
