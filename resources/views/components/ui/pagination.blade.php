@props([
    'paginator' => null,   // Illuminate\Contracts\Pagination\Paginator
])

@if ($paginator && $paginator->hasPages())
    <nav class="flex items-center justify-between gap-3 border-t border-(--border) px-1 py-3 text-sm" aria-label="Pagination">
        <div class="text-(--content-muted)">
            @if (method_exists($paginator, 'total'))
                Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
            @else
                Page {{ $paginator->currentPage() }}
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="rounded-lg border border-(--border) px-3 py-1.5 text-(--content-muted)/50">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="rounded-lg border border-(--border) px-3 py-1.5 hover:bg-(--surface-muted)">Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="rounded-lg border border-(--border) px-3 py-1.5 hover:bg-(--surface-muted)">Next</a>
            @else
                <span class="rounded-lg border border-(--border) px-3 py-1.5 text-(--content-muted)/50">Next</span>
            @endif
        </div>
    </nav>
@endif
