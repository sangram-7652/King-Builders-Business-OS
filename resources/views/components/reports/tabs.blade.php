@props([
    'reports',   // array<key, array{title,route,...}>
    'active',    // current key
    'filters',   // App\Support\Reports\ReportFilterData
])

<nav class="flex flex-wrap gap-1 border-b border-(--border) pb-px">
    @foreach ($reports as $key => $r)
        @php $isActive = $key === $active; @endphp
        <a href="{{ route($r['route'], $filters->toQueryString()) }}"
           @class([
               'rounded-t-lg px-3 py-2 text-sm font-medium transition',
               'border-b-2 border-(--brand-primary) text-(--content)' => $isActive,
               'text-(--content-muted) hover:text-(--content)' => ! $isActive,
           ])
           @if ($isActive) aria-current="page" @endif>
            {{ \Illuminate\Support\Str::of($r['title'])->before(' report')->replace('Executive dashboard', 'Overview')->ucfirst() }}
        </a>
    @endforeach
</nav>
