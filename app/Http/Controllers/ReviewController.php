<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index()
    {
        // Primero las que esperan aprobación.
        $reviews = Review::orderBy('approved')->latest()->paginate(15);

        return view('reviews.index', compact('reviews'));
    }

    public function create()
    {
        return view('reviews.form', ['review' => null]);
    }

    public function store(Request $request)
    {
        Review::create($this->validatedData($request));

        return redirect()->route('reviews.index')->with('success', 'Reseña agregada.');
    }

    public function edit(Review $review)
    {
        return view('reviews.form', compact('review'));
    }

    public function update(Request $request, Review $review)
    {
        $review->update($this->validatedData($request));

        return redirect()->route('reviews.index')->with('success', 'Reseña actualizada.');
    }

    /** Publica u oculta una reseña con un clic. */
    public function toggle(Review $review)
    {
        $review->update(['approved' => ! $review->approved]);

        return back()->with('success', $review->approved ? 'Reseña publicada en la web.' : 'Reseña oculta de la web.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return redirect()->route('reviews.index')->with('success', 'Reseña eliminada.');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:120'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'max:1500'],
        ]);
        $data['approved'] = $request->boolean('approved');

        return $data;
    }
}
