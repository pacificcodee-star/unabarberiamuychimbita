<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_formulario_permite_quitar_servicios_y_muestra_el_estado_vacio(): void
    {
        $barber = User::factory()->create(['role' => 'barbero', 'active' => true]);
        Service::create([
            'name' => 'Corte clásico',
            'price' => 20000,
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $this->actingAs($barber)->get(route('sales.create'))
            ->assertOk()
            ->assertSee('id="noItemsRow"', false)
            ->assertSee('Agrega al menos un servicio para registrar la venta.', false)
            ->assertSee('id="registerSaleButton"', false)
            ->assertSee('remove-item', false)
            ->assertSee('row.remove();', false)
            ->assertDontSee("itemsBody.querySelectorAll('.item-row').length > 1", false);
    }
}
