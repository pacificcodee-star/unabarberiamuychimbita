<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Al sacar la cita se pide confirmarla; al confirmar llega el mensaje completo con servicio y tipo. */
class AppointmentMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_primero_pide_confirmar_y_al_confirmar_envia_todos_los_detalles(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));
        $barber = User::factory()->create(['name' => 'Carlos Mendoza', 'role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
        $service = Service::create(['name' => 'Corte degradado', 'category' => 'corte', 'price' => 25000, 'duration_minutes' => 30, 'active' => true]);
        $cita = Appointment::create([
            'public_token' => str_repeat('a', 40), 'barber_id' => $barber->id, 'service_id' => $service->id,
            'client_name' => 'Ana María Gómez', 'client_phone' => '3001234567', 'whatsapp_consent' => true,
            'appointment_date' => '2026-11-18', 'appointment_time' => '15:30', 'status' => 'pendiente',
        ]);

        app(ClientNotifier::class)->appointmentBooked($cita);
        $pedido = ClientNotification::where('type', 'appt_booked')->where('channel', 'whatsapp')->firstOrFail()->body;

        $this->assertStringContainsString('¡Hola, Ana!', $pedido);
        $this->assertStringContainsString('Por favor confirma tu cita', $pedido);
        $this->assertStringContainsString('✂️ *Servicio:* Corte degradado', $pedido);
        $this->assertStringContainsString('🏷️ *Tipo de servicio:* Cortes', $pedido);
        $this->assertStringContainsString('🕒 *Hora:* 3:30 p. m.', $pedido);
        $this->assertStringNotContainsString('Te esperamos', $pedido);

        $before = ['status' => $cita->status, 'starts_at' => $cita->startsAt()];
        $cita->update(['status' => 'confirmada']);
        app(ClientNotifier::class)->appointmentChanged($cita, $before);
        $confirmada = ClientNotification::where('type', 'appt_confirmed')->where('channel', 'whatsapp')->firstOrFail()->body;

        $this->assertStringContainsString('¡Hola, Ana!', $confirmada);
        $this->assertStringContainsString('quedó *confirmada*', $confirmada);
        $this->assertStringContainsString('✂️ *Servicio:* Corte degradado', $confirmada);
        $this->assertStringContainsString('🏷️ *Tipo de servicio:* Cortes', $confirmada);
        $this->assertStringContainsString('💈 *Barbero:* Carlos Mendoza', $confirmada);
        $this->assertStringContainsString('💰 *Valor:* $25.000', $confirmada);

        fwrite(STDERR, "\n----- AL SACAR LA CITA -----\n".$pedido."\n\n----- AL CONFIRMAR -----\n".$confirmada."\n");
    }
}
