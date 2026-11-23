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

    }

    public function test_cada_cambio_de_estado_avisa_por_whatsapp_y_al_navegador(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));
        $cita = $this->cita(['status' => 'pendiente']);
        $notifier = app(ClientNotifier::class);
        $cambiar = function (array $changes, string $by = 'staff') use ($cita, $notifier) {
            $before = ['status' => $cita->status, 'starts_at' => $cita->startsAt()];
            $cita->update($changes);
            $notifier->appointmentChanged($cita->fresh(), $before, $by);
        };
        $canales = fn (string $type) => ClientNotification::where('type', $type)->pluck('channel')->sort()->values()->all();

        $cambiar(['status' => 'confirmada']);                       // la barbería la confirma
        $this->assertSame(['push', 'whatsapp'], $canales('appt_confirmed'));
        $this->assertStringNotContainsString('gracias por confirmar', ClientNotification::where('type', 'appt_confirmed')->first()->body);

        $cambiar(['status' => 'pendiente']);                        // vuelve a pendiente
        $this->assertSame(['push', 'whatsapp'], $canales('appt_pending'));

        $cambiar(['appointment_time' => '17:00']);                  // reprogramada
        $this->assertSame(['push', 'whatsapp'], $canales('appt_rescheduled'));
        $this->assertStringContainsString('🕒 *Hora:* 5:00 p. m.', ClientNotification::where('type', 'appt_rescheduled')->first()->body);

        $cambiar(['status' => 'cancelada']);                        // cancelada
        $this->assertSame(['push', 'whatsapp'], $canales('appt_cancelled'));

        $cambiar(['status' => 'confirmada']);                       // reactivada
        $this->assertSame(['push', 'whatsapp'], $canales('appt_reactivated'));
        $this->assertStringContainsString('fue *reactivada*', ClientNotification::where('type', 'appt_reactivated')->first()->body);

        $cambiar(['status' => 'atendida']);                         // atendida
        $this->assertSame(['push', 'whatsapp'], $canales('service_thanks'));
    }

    public function test_si_confirma_por_el_bot_solo_llega_el_push_porque_el_bot_ya_respondio(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));
        $cita = $this->cita(['status' => 'pendiente']);
        $before = ['status' => 'pendiente', 'starts_at' => $cita->startsAt()];
        $cita->update(['status' => 'confirmada']);

        app(ClientNotifier::class)->appointmentChanged($cita, $before, 'bot');

        $this->assertSame(['push'], ClientNotification::where('type', 'appt_confirmed')->pluck('channel')->all());
        $this->assertStringContainsString('gracias por confirmar', ClientNotification::where('type', 'appt_confirmed')->first()->body);
    }

    private function cita(array $attributes = []): Appointment
    {
        $barber = User::factory()->create(['name' => 'Carlos Mendoza', 'role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
        $service = Service::create(['name' => 'Corte degradado', 'category' => 'corte', 'price' => 25000, 'duration_minutes' => 30, 'active' => true]);

        return Appointment::create($attributes + [
            'public_token' => str_repeat('b', 40), 'barber_id' => $barber->id, 'service_id' => $service->id,
            'client_name' => 'Ana María Gómez', 'client_phone' => '3001234567', 'whatsapp_consent' => true,
            'appointment_date' => '2026-11-18', 'appointment_time' => '15:30',
        ]);
    }
}
