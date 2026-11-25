<ul class="nav nav-pills" aria-label="Secciones de satisfacción">
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('surveys.index', 'surveys.show') ? 'active' : '' }}" href="{{ route('surveys.index') }}"><i class="bi bi-bar-chart me-1" aria-hidden="true"></i> Resultados</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('surveys.reviews') ? 'active' : '' }}" href="{{ route('surveys.reviews') }}"><i class="bi bi-chat-quote me-1" aria-hidden="true"></i> Opiniones</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('surveys.questions*') ? 'active' : '' }}" href="{{ route('surveys.questions') }}"><i class="bi bi-list-check me-1" aria-hidden="true"></i> Preguntas</a></li>
</ul>
