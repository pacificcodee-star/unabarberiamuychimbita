<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_equis_limpia_los_valores_sin_eliminar_la_fila_del_servicio(): void
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
            ->assertSee('title="Limpiar"', false)
            ->assertSee('aria-label="Limpiar servicio"', false)
            ->assertSee('remove-item', false)
            ->assertSee("serviceSelect.value = '';", false)
            ->assertSee("priceInput.value = '';", false)
            ->assertSee("row.querySelector('.quantity-input').value = '1';", false)
            ->assertSee("row.querySelector('.subtotal').textContent = money(0);", false)
            ->assertDontSee('row.remove();', false)
            ->assertDontSee('id="noItemsRow"', false)
            ->assertDontSee('id="registerSaleButton"', false)
            ->assertDontSee('toggleItemsState', false);
    }
}
