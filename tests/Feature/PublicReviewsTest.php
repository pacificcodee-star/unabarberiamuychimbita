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
        $this->get('/resenas')->assertOk()->assertDontSee('class="rv-score"', false)->assertSee('¿Te atendimos');
    }

    public function test_reviews_page_has_no_form_and_explains_the_whatsapp_survey(): void
    {
        $this->get('/resenas')->assertOk()
            ->assertDontSee('<form', false)
            ->assertDontSee('Enviar mi opinión')
            ->assertSee('te escribimos por WhatsApp');
        $this->get('/')->assertDontSee('dejar la tuya');

        $this->post('/resenas', ['client_name' => 'Andrea', 'rating' => 4, 'comment' => 'Excelente corte y muy buena atención.'])
            ->assertStatus(405);
        $this->assertSame(0, Review::count());
    }

    public function test_only_4_and_5_star_cards_are_shown_but_the_average_counts_every_published_review(): void
    {
        Review::create(['client_name' => 'Cinco', 'rating' => 5, 'comment' => 'Opinión de cinco estrellas.', 'approved' => true]);
        Review::create(['client_name' => 'Cuatro', 'rating' => 4, 'comment' => 'Opinión de cuatro estrellas.', 'approved' => true]);
        Review::create(['client_name' => 'Tres', 'rating' => 3, 'comment' => 'Opinión de tres estrellas.', 'approved' => true]);

        foreach (['/', '/resenas'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Opinión de cinco estrellas.')
                ->assertSee('Opinión de cuatro estrellas.')
                ->assertDontSee('Opinión de tres estrellas.')
                // (5 + 4 + 3) / 3 = 4,0 con 3 opiniones: el promedio no se infla.
                ->assertSee('Calificación promedio 4,0 de 5, con 3', false);
        }
    }

    public function test_reviews_sections_hide_when_every_published_review_is_below_4_stars(): void
    {
        Review::create(['client_name' => 'Dos', 'rating' => 2, 'comment' => 'Opinión de dos estrellas.', 'approved' => true]);

        $this->get('/')->assertOk()->assertDontSee('cx-proof', false)->assertDontSee('Opinión de dos estrellas.');
        $this->get('/resenas')->assertOk()->assertDontSee('class="rv-score"', false)->assertDontSee('Opinión de dos estrellas.');
    }

    public function test_previously_approved_reviews_still_appear(): void
    {
        Review::create(['client_name' => 'Andrea', 'rating' => 4, 'comment' => 'Excelente corte y muy buena atención.', 'approved' => true]);

        $this->get('/')->assertOk()->assertSee('Excelente corte y muy buena atención.')->assertSee('Andrea');
        $this->get('/resenas')->assertOk()->assertSee('Excelente corte y muy buena atención.');
    }

    public function test_review_management_is_unavailable_to_administrators(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->get('/reviews')->assertNotFound();
        $this->patch('/reviews/1/toggle')->assertNotFound();
    }
}
