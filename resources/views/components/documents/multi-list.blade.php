@props(['documents'])

<ul class="divide-y divide-(--border)">
    @foreach ($documents as $doc)
        <li wire:key="multi-doc-{{ $doc->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
            <div>
                <span class="font-medium">{{ $doc->currentVersion?->original_filename ?? $doc->title }}</span>
                <x-ui.badge size="sm" :variant="$doc->status->color()" class="ml-1.5">{{ $doc->status->label() }}</x-ui.badge>
                <div class="text-xs text-(--content-muted)">
                    @if ($doc->currentVersion)
                        {{ $doc->currentVersion->humanSize() }} · {{ $doc->currentVersion->uploaded_at?->format('d M Y H:i') }}
                    @endif
                </div>
                @if ($doc->rejection_reason && $doc->status === \App\Enums\DocumentStatus::Rejected)
                    <div class="text-xs text-red-600">{{ $doc->rejection_reason }}</div>
                @endif
            </div>
            <div class="flex flex-wrap items-center justify-end gap-1">
                @can('download', $doc)
                    <a href="{{ route('documents.download', ['document' => $doc->id, 'version' => $doc->current_version_id]) }}"
                       target="_blank" class="text-xs text-(--brand-primary) hover:underline">download</a>
                @endcan
                @if ($doc->status === \App\Enums\DocumentStatus::Uploaded)
                    <x-ui.button size="sm" variant="ghost" wire:click="submitForReview({{ $doc->id }})">Send to review</x-ui.button>
                @endif
                @if (in_array($doc->status, [\App\Enums\DocumentStatus::Uploaded, \App\Enums\DocumentStatus::UnderReview], true))
                    @can('verify', $doc)
                        <x-ui.button size="sm" variant="ghost" wire:click="verify({{ $doc->id }})" wire:confirm="Verify this document?">Verify</x-ui.button>
                    @endcan
                    @can('reject', $doc)
                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="openReject({{ $doc->id }})">Reject</x-ui.button>
                    @endcan
                @endif
                @if (! $doc->isProtected())
                    @can('delete', $doc)
                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="Remove this document?">✕</x-ui.button>
                    @endcan
                @endif
            </div>
        </li>
    @endforeach
</ul>
