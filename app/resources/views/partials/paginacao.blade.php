@if ($paginator->hasPages())
<nav class="paginacao" aria-label="Paginação">
    @if ($paginator->onFirstPage())<span class="muted">← Anterior</span>@else<a class="button" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anterior</a>@endif
    <span>Página {{ $paginator->currentPage() }}{{ method_exists($paginator, 'lastPage') ? ' de '.$paginator->lastPage() : '' }}</span>
    @if ($paginator->hasMorePages())<a class="button" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima →</a>@else<span class="muted">Próxima →</span>@endif
</nav>
@endif
