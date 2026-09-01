@props(['checklist', 'documents', 'componentId', 'rejectingId' => null])

<div>
    <div class="grid gap-3 sm:grid-cols-5">
        <x-ui.stat-card label="Required" :value="$checklist->requiredCount" />
        <x-ui.stat-card label="Received" :value="$checklist->receivedCount" />
        <x-ui.stat-card label="Verified" :value="$checklist->verifiedCount" />
        <x-ui.stat-card label="Rejected / expired" :value="$checklist->rejectedCount" />
        <x-ui.stat-card label="Pending" :value="$checklist->pendingCount" />
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full divide-y divide-(--border) text-sm">
            <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                <tr>
                    <th class="py-2 pr-4">Document</th>
                    <th class="py-2 pr-4">Required</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4">Latest version</th>
                    <th class="py-2 pr-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-(--border)">
                @foreach ($checklist->items as $item)
                    @php
                        $doc = $documents->get($item['document_type_id']);
                        $status = \App\Enums\DocumentStatus::from($item['status']);
                    @endphp
                    <tr wire:key="slot-{{ $item['document_type_id'] }}" @class(['bg-red-500/5' => $item['missing']])>
                        <td class="py-2 pr-4 font-medium text-(--content)">{{ $item['name'] }}</td>
                        <td class="py-2 pr-4">
                            @if ($item['required'])<x-ui.badge variant="brand" size="sm">Required</x-ui.badge>@else<span class="text-(--content-muted)">Optional</span>@endif
                        </td>
                        <td class="py-2 pr-4"><x-ui.badge :variant="$status->color()">{{ $status->label() }}</x-ui.badge></td>
                        <td class="py-2 pr-4 text-(--content-muted)">
                            @if ($doc?->currentVersion)
                                v{{ $doc->currentVersion->version }} · {{ $doc->currentVersion->humanSize() }}
                                @can('download', $doc)
                                    <a href="{{ route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]) }}"
                                       target="_blank" class="ml-1 text-(--brand-primary) hover:underline">download</a>
                                @endcan
                                @if ($doc->versions->count() > 1)<span class="ml-1 text-xs">({{ $doc->versions->count() }} versions)</span>@endif
                            @else — @endif
                            @if ($doc?->rejection_reason && $status === \App\Enums\DocumentStatus::Rejected)
                                <div class="text-xs text-red-600">{{ $doc->rejection_reason }}</div>
                            @endif
                        </td>
                        <td class="py-2 pr-4">
                            <div class="flex flex-wrap items-center justify-end gap-1">
                                @can('documents.upload')
                                    <label class="cursor-pointer text-xs text-(--brand-primary) hover:underline">
                                        {{ $doc?->hasFile() ? 'Replace' : 'Upload' }}
                                        <input type="file" class="hidden" wire:model="files.{{ $item['document_type_id'] }}"
                                               x-on:change="$wire.upload({{ $item['document_type_id'] }})" />
                                    </label>
                                    @error('files.'.$item['document_type_id']) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                @endcan
                                @if ($doc && $status === \App\Enums\DocumentStatus::Uploaded)
                                    <x-ui.button size="sm" variant="ghost" wire:click="submitForReview({{ $doc->id }})">Send to review</x-ui.button>
                                @endif
                                @if ($doc && in_array($status, [\App\Enums\DocumentStatus::Uploaded, \App\Enums\DocumentStatus::UnderReview], true))
                                    @can('verify', $doc)
                                        <x-ui.button size="sm" variant="ghost" wire:click="verify({{ $doc->id }})" wire:confirm="Verify {{ $item['name'] }}?">Verify</x-ui.button>
                                    @endcan
                                    @can('reject', $doc)
                                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="openReject({{ $doc->id }})">Reject</x-ui.button>
                                    @endcan
                                @endif
                                @if ($doc && ! $doc->isProtected())
                                    @can('delete', $doc)
                                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="Remove this document?">✕</x-ui.button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($rejectingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="reject-{{ $componentId }}">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="$set('rejectingId', null)"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Reject document</h3></div>
                <form wire:submit="reject" class="space-y-4 px-5 py-4">
                    <x-ui.textarea label="Reason (required)" wire:model="rejectReason" rows="2" :error="$errors->first('rejectReason')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('rejectingId', null)">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Reject</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
