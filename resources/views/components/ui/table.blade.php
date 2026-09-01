@props([
    'headers' => [],   // list<string>
])

<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-(--border) bg-(--surface)']) }}>
    <table class="min-w-full divide-y divide-(--border) text-sm">
        @if (! empty($headers) || isset($head))
            <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                @isset($head)
                    {{ $head }}
                @else
                    <tr>
                        @foreach ($headers as $header)
                            <th scope="col" class="px-4 py-3">{{ $header }}</th>
                        @endforeach
                    </tr>
                @endisset
            </thead>
        @endif

        <tbody class="divide-y divide-(--border)">
            {{ $slot }}
        </tbody>

        @isset($foot)
            <tfoot class="border-t border-(--border) bg-(--surface-muted)">{{ $foot }}</tfoot>
        @endisset
    </table>
</div>
