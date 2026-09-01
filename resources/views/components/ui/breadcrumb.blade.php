@props([
    'items' => [],   // list<array{label:string, url?:string}>
])

@if (! empty($items))
    <nav aria-label="Breadcrumb" {{ $attributes }}>
        <ol class="flex flex-wrap items-center gap-1.5 text-sm text-(--content-muted)">
            @foreach ($items as $item)
                @php $isLast = $loop->last; @endphp
                <li class="flex items-center gap-1.5">
                    @if (! empty($item['url']) && ! $isLast)
                        <a href="{{ $item['url'] }}" class="hover:text-(--content)">{{ $item['label'] }}</a>
                    @else
                        <span @class(['font-medium text-(--content)' => $isLast]) @if ($isLast) aria-current="page" @endif>
                            {{ $item['label'] }}
                        </span>
                    @endif

                    @unless ($isLast)
                        <span class="text-(--content-muted)/50">/</span>
                    @endunless
                </li>
            @endforeach
        </ol>
    </nav>
@endif
