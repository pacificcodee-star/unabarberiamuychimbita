<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Turn;
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

    public function test_no_cambia_la_duracion_con_una_cita_vigente(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $service = Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
        $appointment = Appointment::create([
            'barber_id' => User::factory()->create(['role' => 'barbero', 'active' => true])->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente',
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00:00',
            'status' => 'confirmada',
        ]);

        $this->actingAs($admin)->put(route('services.update', $service), [
            'name' => $service->name,
            'category' => $service->category,
            'price' => $service->price,
            'duration_minutes' => 60,
            'active' => 1,
        ])->assertSessionHasErrors('duration_minutes');

        $this->assertSame(30, $service->fresh()->duration_minutes);
        $appointment->update(['status' => 'cancelada']);
        $this->put(route('services.update', $service), [
            'name' => $service->name,
            'category' => $service->category,
            'price' => $service->price,
            'duration_minutes' => 60,
            'active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(60, $service->fresh()->duration_minutes);
    }

    public function test_no_cambia_la_duracion_con_un_turno_activo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $service = Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
        Turn::create([
            'barber_id' => User::factory()->create(['role' => 'barbero', 'active' => true])->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente',
            'turn_date' => now()->toDateString(),
            'turn_number' => 1,
            'status' => 'esperando',
        ]);

        $this->actingAs($admin)->put(route('services.update', $service), [
            'name' => $service->name,
            'category' => $service->category,
            'price' => $service->price,
            'duration_minutes' => 60,
            'active' => 1,
        ])->assertSessionHasErrors('duration_minutes');

        $this->assertSame(30, $service->fresh()->duration_minutes);
    }
}
