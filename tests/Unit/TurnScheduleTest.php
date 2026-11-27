<?php

namespace Tests\Unit;

use App\Support\TurnSchedule;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Horario de los turnos: el cierre sale siempre de la configuración del panel, nunca de valores
 * escritos en el código. El límite de solicitudes es el cierre menos el tiempo que necesita el
 * servicio (margen de llegada + duración), que aquí se fija en 30 minutos.
 */
class TurnScheduleTest extends TestCase
{
    private const LEAD = 30;

    private function settings(array $overrides = []): array
    {
        return $overrides + config('sitio');
    }

    public function test_el_limite_es_el_cierre_configurado_menos_lo_que_necesita_el_servicio(): void
    {
        $wednesday = Carbon::parse('2026-11-18 10:00:00');
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $this->assertSame('09:00', TurnSchedule::openingTime($wednesday, $settings));
        $this->assertSame('20:00', TurnSchedule::closingTime($wednesday, $settings));
        $this->assertSame('19:30', TurnSchedule::deadlineAt($wednesday, $settings, self::LEAD)->format('H:i'));
    }

    public function test_el_horario_se_interpreta_en_sus_formatos_habituales(): void
    {
        $day = Carbon::parse('2026-11-18 10:00:00');

        foreach ([
            '9:00 a. m. - 8:00 p. m.' => '20:00',
            '9:00 am - 8:00 pm' => '20:00',
            '09:00 - 20:00' => '20:00',
            '10:00 a. m. – 9:00 p. m.' => '21:00',
            '8 a. m. - 6 p. m.' => '18:00',
        ] as $text => $closing) {
            $this->assertSame($closing, TurnSchedule::closingTime($day, $this->settings(['hours_wed' => $text])), $text);
        }
    }

    public function test_si_el_texto_es_ambiguo_se_usa_la_hora_final_de_reservas(): void
    {
        $day = Carbon::parse('2026-11-18 10:00:00');

        $this->assertSame('20:00', TurnSchedule::closingTime($day, $this->settings([
            'hours_wed' => '9:00 a 8:00',
            'booking_end' => '20:00',
        ])));
    }

    public function test_la_solicitud_esta_abierta_justo_en_el_limite_y_cerrada_un_segundo_despues(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $this->assertTrue(TurnSchedule::forNow(Carbon::parse('2026-11-18 19:30:00'), $settings, self::LEAD)['open']);
        $this->assertSame('open', TurnSchedule::forNow(Carbon::parse('2026-11-18 19:30:00'), $settings, self::LEAD)['reason']);

        $late = TurnSchedule::forNow(Carbon::parse('2026-11-18 19:30:01'), $settings, self::LEAD);
        $this->assertFalse($late['open']);
        $this->assertSame('cutoff', $late['reason']);
        $this->assertStringContainsString('se reciben hasta las 7:30 p. m., para que el servicio alcance a terminar antes del cierre', $late['message']);
    }

    public function test_despues_del_corte_avisa_cuando_se_vuelve_a_poder(): void
    {
        $settings = $this->settings([
            'hours_wed' => '9:00 a. m. - 8:00 p. m.',
            'hours_thu' => '9:00 a. m. - 8:00 p. m.',
        ]);

        $late = TurnSchedule::forNow(Carbon::parse('2026-11-18 19:45:00'), $settings, self::LEAD);

        $this->assertStringContainsString('la recepción de turnos para hoy ha finalizado', $late['message']);
        $this->assertStringContainsString('Podrá solicitar su turno mañana a partir de las 9:00 a. m. Si lo prefiere', $late['message']);
        $this->assertStringNotContainsString('..', $late['message']);
    }

    public function test_antes_de_abrir_avisa_desde_que_hora_se_puede(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $early = TurnSchedule::forNow(Carbon::parse('2026-11-18 07:30:00'), $settings, self::LEAD);

        $this->assertSame('before_open', $early['reason']);
        $this->assertStringContainsString('Aún no hemos abierto', $early['message']);
        $this->assertStringContainsString('a partir de las 9:00 a. m. y hasta las 7:30 p. m.', $early['message']);
    }

    public function test_el_sabado_despues_del_corte_salta_el_domingo_cerrado(): void
    {
        $settings = $this->settings([
            'hours_sat' => '9:00 a. m. - 8:00 p. m.',
            'hours_sun' => 'Cerrado',
            'hours_mon' => '10:00 a. m. - 8:00 p. m.',
        ]);

        $late = TurnSchedule::forNow(Carbon::parse('2026-11-21 19:40:00'), $settings, self::LEAD); // sábado

        $this->assertStringContainsString('Podrá solicitar su turno el lunes a partir de las 10:00 a. m.', $late['message']);
    }

    public function test_antes_de_abrir_y_despues_del_cierre_no_se_aceptan_solicitudes(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $this->assertSame('before_open', TurnSchedule::forNow(Carbon::parse('2026-11-18 08:59:00'), $settings, self::LEAD)['reason']);
        $this->assertFalse(TurnSchedule::forNow(Carbon::parse('2026-11-18 20:30:00'), $settings, self::LEAD)['open']);
    }

    public function test_un_dia_cerrado_no_tiene_cierre_ni_limite(): void
    {
        $sunday = Carbon::parse('2026-11-22 12:00:00');
        $settings = $this->settings(['hours_sun' => 'Cerrado']);

        $this->assertNull(TurnSchedule::closingTime($sunday, $settings));

        $state = TurnSchedule::forNow($sunday, $settings, self::LEAD);
        $this->assertFalse($state['open']);
        $this->assertSame('closed', $state['reason']);
        $this->assertNull($state['deadline']);
        $this->assertStringContainsString('no presta servicio', $state['message']);
        $this->assertStringContainsString('Podrá solicitar su turno mañana', $state['message']);
    }

    public function test_cambiar_el_horario_del_panel_mueve_el_limite(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 9:00 p. m.']);

        $this->assertTrue(TurnSchedule::forNow(Carbon::parse('2026-11-18 20:30:00'), $settings, self::LEAD)['open']);
        $this->assertFalse(TurnSchedule::forNow(Carbon::parse('2026-11-18 20:31:00'), $settings, self::LEAD)['open']);
    }
}
