@php
    use App\Enums\DocumentStatus;
    $link = function ($d) {
        $x = $d->documentable;
        return match (true) {
            $x instanceof \App\Models\Buyer => ['label' => $x->fullName(), 'url' => route('buyers.documents', $x)],
            $x instanceof \App\Models\Booking => ['label' => $x->booking_number, 'url' => route('documents.booking', $x)],
            $x instanceof \App\Models\Agreement => ['label' => $x->agreement_number, 'url' => route('documents.booking', $x->booking_id)],
            $x instanceof \App\Models\DocumentHandover => ['label' => 'Handover', 'url' => route('registry.booking', $x->booking_id)],
            default => ['label' => '—', 'url' => '#'],
        };
    };
@endphp
<div class="space-y-6">
    <x-ui.page-header title="Documents dashboard" description="Verification queue, rejected / expired documents and checklist health across all bookings and buyers." />

    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <x-ui.stat-card label="Pending verification" :value="($counts[DocumentStatus::Uploaded->value] ?? 0) + ($counts[DocumentStatus::UnderReview->value] ?? 0)" />
        <x-ui.stat-card label="Verified" :value="$counts[DocumentStatus::Verified->value] ?? 0" />
        <x-ui.stat-card label="Rejected" :value="$counts[DocumentStatus::Rejected->value] ?? 0" trend="down" />
        <x-ui.stat-card label="Expired" :value="$counts[DocumentStatus::Expired->value] ?? 0" trend="down" />
        <x-ui.stat-card label="Pending upload" :value="$counts[DocumentStatus::Pending->value] ?? 0" />
    </div>

    <x-ui.card title="Awaiting verification">
        @if ($pendingVerification->isEmpty())
            <p class="text-sm text-(--content-muted)">Nothing waiting.</p>
        @else
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr><th class="py-2 pr-4">Document</th><th class="py-2 pr-4">Owner</th><th class="py-2 pr-4">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($pendingVerification as $d)
                        @php $l = $link($d); @endphp
                        <tr>
                            <td class="py-2 pr-4">{{ $d->documentType->name }}</td>
                            <td class="py-2 pr-4"><a href="{{ $l['url'] }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $l['label'] }}</a></td>
                            <td class="py-2 pr-4"><x-ui.badge :variant="$d->status->color()">{{ $d->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    <x-ui.card title="Rejected / expired">
        @if ($rejected->isEmpty())
            <p class="text-sm text-(--content-muted)">None.</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach ($rejected as $d)
                    @php $l = $link($d); @endphp
                    <li>
                        <x-ui.badge :variant="$d->status->color()" size="sm">{{ $d->status->label() }}</x-ui.badge>
                        {{ $d->documentType->name }} —
                        <a href="{{ $l['url'] }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $l['label'] }}</a>
                        @if ($d->rejection_reason)<span class="text-(--content-muted)">· {{ $d->rejection_reason }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
