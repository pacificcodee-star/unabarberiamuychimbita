@extends('layouts.app')

@section('title', 'Reseñas del sitio')

@section('actions')
    <a href="{{ route('reviews.create') }}" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i> Agregar reseña</a>
@endsection

@section('content')
    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Cliente</th><th>Calificación</th><th>Comentario</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    @forelse($reviews as $review)
                        <tr>
                            <td class="fw-semibold">{{ $review->client_name }}</td>
                            <td class="text-warning">{{ str_repeat('★', $review->rating) }}</td>
                            <td class="text-muted" style="min-width:240px">{{ Illuminate\Support\Str::limit($review->comment, 150) }}</td>
                            <td><span class="badge {{ $review->approved ? 'text-bg-success' : 'text-bg-warning' }}">{{ $review->approved ? 'Publicada' : 'Pendiente' }}</span><div class="small text-muted mt-1">{{ $review->created_at?->format('d/m/Y') }}</div></td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('reviews.toggle', $review) }}" class="d-inline">@csrf @method('PATCH')<button class="btn btn-sm {{ $review->approved ? 'btn-outline-secondary' : 'btn-gold' }}">{{ $review->approved ? 'Ocultar' : 'Publicar' }}</button></form>
                                <a href="{{ route('reviews.edit', $review) }}" class="btn btn-sm btn-outline-dark" aria-label="Editar reseña"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="{{ route('reviews.destroy', $review) }}" class="d-inline" onsubmit="return confirm('¿Eliminar esta reseña?');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" aria-label="Eliminar reseña"><i class="bi bi-trash"></i></button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-5 text-center text-muted">Aún no hay reseñas. Las que escriban los clientes en la página de Reseñas aparecerán aquí para que las apruebes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($reviews->hasPages())<div class="card-body border-top">{{ $reviews->links('pagination::bootstrap-5') }}</div>@endif
    </div>
@endsection
