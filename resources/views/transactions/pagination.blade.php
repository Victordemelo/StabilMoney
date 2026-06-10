{{-- Paginação minimal no padrão do design (usada via links('transactions.pagination')) --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Paginação">
        {{-- Anterior --}}
        @if ($paginator->onFirstPage())
            <span class="pg disabled" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 6l-6 6 6 6"/></svg>
            </span>
        @else
            <a class="pg" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Página anterior">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 6l-6 6 6 6"/></svg>
            </a>
        @endif

        {{-- Números --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pg-gap">…</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $pagina => $url)
                    @if ($pagina == $paginator->currentPage())
                        <span class="pg current" aria-current="page">{{ $pagina }}</span>
                    @else
                        <a class="pg" href="{{ $url }}">{{ $pagina }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Próxima --}}
        @if ($paginator->hasMorePages())
            <a class="pg" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Próxima página">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg>
            </a>
        @else
            <span class="pg disabled" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 6l6 6-6 6"/></svg>
            </span>
        @endif
    </nav>
@endif
