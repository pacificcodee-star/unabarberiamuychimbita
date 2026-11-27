<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_vip_duration_with_its_existing_long_description(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $description = str_repeat('Limpieza facial, mascarilla y masaje. ', 20).'Cortesía de la casa.';
        $service = Service::create([
            'name' => 'Servicio VIP',
            'category' => 'cuidado',
            'description' => $description,
            'price' => 110000,
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $this->actingAs($admin)->put(route('services.update', $service), [
            'name' => $service->name,
            'category' => $service->category,
            'description' => $service->description,
            'price' => $service->price,
            'duration_minutes' => '540',
            'active' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('services.index'));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'description' => $description,
            'duration_minutes' => 540,
        ]);
    }
}
