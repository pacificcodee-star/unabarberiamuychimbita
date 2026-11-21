<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;

/**
 * Opiniones que dejan los clientes desde la página pública de Reseñas.
 * Se guardan sin publicar.
 */
class PublicReviewController extends Controller
{
    public function store(Request $request)
    {
        // Campo trampa invisible para robots: una persona nunca lo llena.
        if (filled($request->input('website'))) {
            return redirect()->to(route('public.reviews').'#opinar')->with('review_sent', true);
        }

        $data = $request->validate([
            'client_name' => ['required', 'string', 'min:2', 'max:60'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'min:10', 'max:600'],
        ], [
            'client_name.required' => 'Escribe tu nombre.',
            'client_name.min' => 'Tu nombre debe tener al menos 2 letras.',
            'rating.required' => 'Elige de 1 a 5 estrellas.',
            'rating.min' => 'Elige de 1 a 5 estrellas.',
            'rating.max' => 'Elige de 1 a 5 estrellas.',
            'comment.required' => 'Cuéntanos cómo te fue.',
            'comment.min' => 'Tu opinión debe tener al menos 10 caracteres.',
            'comment.max' => 'Tu opinión puede tener máximo 600 caracteres.',
        ]);

        Review::create([
            'client_name' => trim(strip_tags($data['client_name'])),
            'rating' => (int) $data['rating'],
            'comment' => trim(strip_tags($data['comment'])),
            'approved' => false,
        ]);

        return redirect()->to(route('public.reviews').'#opinar')->with('review_sent', true);
    }
}
