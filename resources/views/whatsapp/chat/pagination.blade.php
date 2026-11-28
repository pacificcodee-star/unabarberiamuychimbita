@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginación de conversaciones" class="w-100">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <ul class="pagination">
                <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}" aria-disabled="{{ $paginator->onFirstPage() ? 'true' : 'false' }}">
                    @if ($paginator->onFirstPage())
                        <span class="page-link">Anterior</span>
                    @else
                        <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
                    @endif
                </li>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <li class="page-item {{ $page == $paginator->currentPage() ? 'active' : '' }}" @if ($page == $paginator->currentPage()) aria-current="page" @endif>
                                @if ($page == $paginator->currentPage())
                                    <span class="page-link">{{ $page }}</span>
                                @else
                                    <a class="page-link" href="{{ $url }}" aria-label="Ir a la página {{ $page }}">{{ $page }}</a>
                                @endif
                            </li>
                        @endforeach
                    @endif
                @endforeach

                <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}" aria-disabled="{{ $paginator->hasMorePages() ? 'false' : 'true' }}">
                    @if ($paginator->hasMorePages())
                        <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>
                    @else
                        <span class="page-link">Siguiente</span>
                    @endif
                </li>
            </ul>
        </div>
    </nav>
@endif
