<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_approved_reviews_the_reviews_sections_do_not_appear(): void
    {
        Review::create(['client_name' => 'Pendiente', 'rating' => 5, 'comment' => 'Todavía no aprobada.', 'approved' => false]);

        $this->get('/')->assertOk()->assertDontSee('cx-proof', false)->assertDontSee('Todavía no aprobada.');
        $this->get('/resenas')->assertOk()->assertDontSee('rv-list-title', false)->assertSee('¿Cómo te fue');
    }

    public function test_a_client_review_arrives_hidden_until_the_admin_approves_it(): void
    {
        $this->post('/resenas', ['client_name' => 'Andrea', 'rating' => 4, 'comment' => 'Excelente corte y muy buena atención.'])
            ->assertRedirect(route('public.reviews').'#opinar');

        $review = Review::firstOrFail();
        $this->assertFalse($review->approved);
        $this->assertSame(4, $review->rating);
        $this->get('/')->assertDontSee('Excelente corte y muy buena atención.');

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->get(route('reviews.index'))->assertOk()->assertSee('Pendiente')->assertSee('Andrea');
        $this->actingAs($admin)->patch(route('reviews.toggle', $review))->assertRedirect();
        $this->assertTrue($review->fresh()->approved);

        $this->get('/')->assertOk()->assertSee('Excelente corte y muy buena atención.')->assertSee('Andrea');
        $this->get('/resenas')->assertOk()->assertSee('Excelente corte y muy buena atención.');
    }

    public function test_the_review_form_validates_and_ignores_bots(): void
    {
        $this->post('/resenas', ['client_name' => '', 'rating' => 9, 'comment' => 'corto'])
            ->assertSessionHasErrors(['client_name', 'rating', 'comment']);

        $this->post('/resenas', ['client_name' => 'Robot', 'rating' => 5, 'comment' => 'Mensaje automático de spam.', 'website' => 'http://spam.test'])
            ->assertRedirect();
        $this->assertSame(0, Review::count());
    }

    public function test_only_the_admin_manages_reviews(): void
    {
        $barber = User::factory()->create(['role' => 'barbero', 'active' => true]);
        $this->actingAs($barber)->get(route('reviews.index'))->assertForbidden();
    }
}
