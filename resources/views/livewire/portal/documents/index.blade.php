@php use App\Models\Booking; @endphp

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold">My documents</h1>
        <p class="text-sm text-(--content-muted)">Your KYC documents and every document attached to your bookings — agreement, registry, possession, and more.</p>
    </div>

    <x-ui.card>
        @if ($documents->isEmpty())
            <x-ui.empty-state icon="inbox" title="No documents yet" description="Documents will appear here once they are uploaded or generated." />
        @else
            <ul class="divide-y divide-(--border)">
                @foreach ($documents as $document)
                    <li wire:key="doc-{{ $document->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                        <div>
                            <span class="font-medium">{{ $document->documentType?->name ?? $document->title ?? 'Document' }}</span>
                            <x-ui.badge size="sm" :variant="$document->status->color()" class="ml-1.5">{{ $document->status->label() }}</x-ui.badge>
                            <div class="mt-0.5 text-xs text-(--content-muted)">
                                {{ $document->documentable instanceof Booking ? 'Booking '.$document->documentable->booking_number : 'My KYC' }}
                                @if ($document->currentVersion)
                                    · {{ $document->currentVersion->original_filename }} ({{ $document->currentVersion->humanSize() }})
                                    · {{ $document->currentVersion->uploaded_at?->format('d M Y') }}
                                @endif
                            </div>
                        </div>
                        @if ($document->current_version_id)
                            <a href="{{ route('portal.documents.download', ['document' => $document->id, 'version' => $document->current_version_id]) }}"
                                target="_blank" class="shrink-0 text-(--brand-primary) hover:underline">Download</a>
                        @else
                            <span class="shrink-0 text-xs text-(--content-muted)">Awaiting upload</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
