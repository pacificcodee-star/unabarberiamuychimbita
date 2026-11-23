<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Turn;
use App\Models\User;
use App\Services\TurnService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Turnos por orden de llegada: numeración diaria que reinicia a #1, cierre de la jornada anterior,
 * historial intacto y límite de solicitudes 30 minutos antes del cierre configurado.
 */
class TurnDailyResetTest extends TestCase
{
    use RefreshDatabase;

    private function barber(): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function takeTurn(User $barber, string $name = 'Cliente Turno'): Turn
    {
        return app(TurnService::class)->createFor($barber, [
            'client_name' => $name,
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
        ], notify: false);
    }

    private function payload(User $barber, string $name = 'Cliente Turno'): array
    {
        return [
            'barber_id' => $barber->id,
            'service_id' => $this->service()->id,
            'client_name' => $name,
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
        ];
    }

    private function service(): Service
    {
        return Service::create([
            'name' => 'Corte clásico',
            'price' => 18000,
            'duration_minutes' => 30,
            'active' => true,
        ]);
    }

    private function setHours(string $day, string $value): void
    {
        $setting = SiteSetting::query()->firstOrNew([]);
        $setting->data = array_merge((array) ($setting->data ?? []), ['hours_'.$day => $value]);
        $setting->save();
    }

    // --- Numeración diaria -------------------------------------------------------------------

    public function test_la_numeracion_reinicia_cada_dia_y_conserva_el_historial(): void
    {
        $ana = $this->barber();
        $beto = $this->barber();

        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles
        $first = $this->takeTurn($ana, 'Primero');
        $this->takeTurn($beto, 'Segundo');
        $this->takeTurn($ana, 'Tercero');

        $this->assertSame([1, 2, 3], Turn::orderBy('turn_number')->pluck('turn_number')->all());

        $this->travelTo(Carbon::parse('2026-11-19 10:00:00')); // jueves: la numeración no continúa
        $nextDayFirst = $this->takeTurn($beto, 'Día siguiente');
        $this->takeTurn($ana, 'Día siguiente 2');

        $this->assertSame(1, $nextDayFirst->turn_number);
        $this->assertSame([1, 2], Turn::whereDate('turn_date', '2026-11-19')->orderBy('turn_number')->pluck('turn_number')->all());

        // Nada se borró: el historial del día anterior sigue con su fecha y su número.
        $this->assertSame(5, Turn::count());
        $this->assertSame(1, $first->fresh()->turn_number);
        $this->assertSame('2026-11-18', $first->fresh()->turn_date->toDateString());
        $this->assertSame(3, Turn::whereDate('turn_date', '2026-11-18')->count());
    }

    public function test_la_numeracion_es_consecutiva_y_sin_duplicados(): void
    {
        $barber = $this->barber();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));

        foreach (range(1, 8) as $i) {
            $this->takeTurn($barber, 'Cliente '.$i);
        }

        $numbers = Turn::whereDate('turn_date', '2026-11-18')->orderBy('turn_number')->pluck('turn_number')->all();
        $this->assertSame(range(1, 8), $numbers);
        $this->assertCount(8, array_unique($numbers));
    }

    public function test_la_base_de_datos_impide_repetir_un_numero_el_mismo_dia(): void
    {
        $barber = $this->barber();
        $attributes = ['barber_id' => $barber->id, 'client_name' => 'A', 'turn_date' => '2026-11-18', 'turn_number' => 1, 'status' => 'esperando'];

        Turn::create($attributes);

        $this->expectException(QueryException::class);
        Turn::create(['client_name' => 'B'] + $attributes);
    }

    public function test_el_reinicio_funciona_aunque_el_servidor_no_estuviera_activo_a_medianoche(): void
    {
        $barber = $this->barber();
        // Turno abierto del día anterior, sin que nada lo procese durante la noche.
        $yesterday = Turn::create([
            'barber_id' => $barber->id,
            'client_name' => 'Cliente De Ayer',
            'turn_date' => '2026-11-17',
            'turn_number' => 7,
            'status' => 'esperando',
        ]);

        // Primer turno del nuevo día: la fecha manda, no una tarea de medianoche.
        $this->travelTo(Carbon::parse('2026-11-18 09:05:00'));
        $today = $this->takeTurn($barber, 'Cliente Hoy');

        $this->assertSame(1, $today->turn_number);
        $this->assertSame('2026-11-18', $today->turn_date->toDateString());

        $yesterday->refresh();
        $this->assertSame('cancelado', $yesterday->status);
        $this->assertSame('2026-11-17 23:59:59', $yesterday->finished_at->toDateTimeString());
        $this->assertSame(2, Turn::count()); // el de ayer no se borra
    }

    // --- Límite de solicitudes ---------------------------------------------------------------

    public function test_el_limite_es_exactamente_30_minutos_antes_del_cierre(): void
    {
        $barber = $this->barber();

        $this->travelTo(Carbon::parse('2026-11-18 19:30:00'));
        $this->post(route('public.booking.turn'), $this->payload($barber, 'Justo A Tiempo'))->assertSessionHasNoErrors();
        $this->assertSame(1, Turn::count());

        $this->travelTo(Carbon::parse('2026-11-18 19:30:01'));
        $this->post(route('public.booking.turn'), $this->payload($barber, 'Tarde'))->assertSessionHasErrors('barber_id');
        $this->assertSame(1, Turn::count());
    }

    public function test_un_turno_pedido_despues_del_limite_es_rechazado_en_la_web_y_en_el_servicio(): void
    {
        $barber = $this->barber();
        $this->travelTo(Carbon::parse('2026-11-18 19:45:00'));

        // Envío directo a la ruta: el servidor no confía en el navegador.
        $this->post(route('public.booking.turn'), $this->payload($barber))
            ->assertSessionHasErrors('barber_id');
        $this->assertSame(0, Turn::count());

        // El bot de WhatsApp y el panel pasan por el mismo servicio: también queda bloqueado.
        try {
            $this->takeTurn($barber);
            $this->fail('Se permitió crear un turno después del límite.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('barber_id', $e->errors());
        }
        $this->assertSame(0, Turn::count());
    }

    public function test_un_dia_cerrado_no_permite_turnos(): void
    {
        $this->setHours('wed', 'Cerrado');
        $barber = $this->barber();

        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
        $this->post(route('public.booking.turn'), $this->payload($barber))->assertSessionHasErrors('barber_id');
        $this->assertSame(0, Turn::count());
    }

    public function test_los_cambios_de_horario_del_administrador_mueven_el_limite(): void
    {
        $barber = $this->barber();
        $this->setHours('wed', '9:00 a. m. - 9:00 p. m.'); // cierre 9:00 p. m. → límite 8:30 p. m.

        $this->travelTo(Carbon::parse('2026-11-18 20:30:00'));
        $this->post(route('public.booking.turn'), $this->payload($barber, 'Con Horario Nuevo'))->assertSessionHasNoErrors();
        $this->assertSame(1, Turn::count());

        $this->travelTo(Carbon::parse('2026-11-18 20:31:00'));
        $this->post(route('public.booking.turn'), $this->payload($barber, 'Tarde'))->assertSessionHasErrors('barber_id');
        $this->assertSame(1, Turn::count());
    }

    public function test_la_pagina_de_reservas_avisa_hasta_que_hora_se_puede_pedir_turno(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));

        $this->get(route('public.booking', ['tipo' => 'turno']))
            ->assertOk()
            ->assertSee('hasta las 7:30 p. m.', false);
    }

    // --- Sincronización cliente / panel -------------------------------------------------------

    public function test_la_cola_del_cliente_y_la_del_panel_muestran_la_jornada_vigente(): void
    {
        $barber = $this->barber();
        $admin = $this->admin();

        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
        $yesterdayTurn = $this->takeTurn($barber, 'Cliente Ayer');

        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
        $this->takeTurn($barber, 'Cliente Hoy');

        // El panel muestra solo la jornada vigente; el turno de ayer ya quedó cerrado.
        $this->actingAs($admin)->get(route('turns.index'))
            ->assertOk()
            ->assertSee('Cliente Hoy')
            ->assertDontSee('Cliente Ayer');

        // La página del cliente consulta el mismo estado: la jornada anterior no sigue activa.
        $this->getJson(route('track.status', ['type' => 'turno', 'token' => $yesterdayTurn->public_token]))
            ->assertOk()
            ->assertJsonPath('status', 'cancelado')
            ->assertJsonPath('open', false);
    }
}
