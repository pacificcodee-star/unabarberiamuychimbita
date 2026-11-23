<?php

namespace Tests\Unit;

use App\Support\TurnSchedule;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Horario de los turnos: el cierre y el límite de solicitudes salen siempre de la configuración
 * del panel, nunca de valores escritos en el código.
 */
class TurnScheduleTest extends TestCase
{
    private function settings(array $overrides = []): array
    {
        return $overrides + config('sitio');
    }

    public function test_el_limite_es_30_minutos_antes_del_cierre_configurado(): void
    {
        $wednesday = Carbon::parse('2026-11-18 10:00:00');
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $this->assertSame('09:00', TurnSchedule::openingTime($wednesday, $settings));
        $this->assertSame('20:00', TurnSchedule::closingTime($wednesday, $settings));
        $this->assertSame('19:30', TurnSchedule::deadlineAt($wednesday, $settings)->format('H:i'));
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

        $this->assertTrue(TurnSchedule::forNow(Carbon::parse('2026-11-18 19:30:00'), $settings)['open']);
        $this->assertSame('open', TurnSchedule::forNow(Carbon::parse('2026-11-18 19:30:00'), $settings)['reason']);

        $late = TurnSchedule::forNow(Carbon::parse('2026-11-18 19:30:01'), $settings);
        $this->assertFalse($late['open']);
        $this->assertSame('cutoff', $late['reason']);
        $this->assertStringContainsString('se reciben hasta las 7:30 p. m.', $late['message']);
    }

    public function test_despues_del_corte_avisa_cuando_se_vuelve_a_poder(): void
    {
        $settings = $this->settings([
            'hours_wed' => '9:00 a. m. - 8:00 p. m.',
            'hours_thu' => '9:00 a. m. - 8:00 p. m.',
        ]);

        $late = TurnSchedule::forNow(Carbon::parse('2026-11-18 19:45:00'), $settings);

        $this->assertStringContainsString('Los turnos de hoy ya cerraron', $late['message']);
        $this->assertStringContainsString('Podrás pedir turno mañana desde las 9:00 a. m.', $late['message']);
    }

    public function test_antes_de_abrir_avisa_desde_que_hora_se_puede(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $early = TurnSchedule::forNow(Carbon::parse('2026-11-18 07:30:00'), $settings);

        $this->assertSame('before_open', $early['reason']);
        $this->assertStringContainsString('Aún no abrimos', $early['message']);
        $this->assertStringContainsString('desde las 9:00 a. m. hasta las 7:30 p. m.', $early['message']);
    }

    public function test_el_sabado_despues_del_corte_salta_el_domingo_cerrado(): void
    {
        $settings = $this->settings([
            'hours_sat' => '9:00 a. m. - 8:00 p. m.',
            'hours_sun' => 'Cerrado',
            'hours_mon' => '10:00 a. m. - 8:00 p. m.',
        ]);

        $late = TurnSchedule::forNow(Carbon::parse('2026-11-21 19:40:00'), $settings); // sábado

        $this->assertStringContainsString('Podrás pedir turno el lunes desde las 10:00 a. m.', $late['message']);
    }

    public function test_antes_de_abrir_y_despues_del_cierre_no_se_aceptan_solicitudes(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 8:00 p. m.']);

        $this->assertSame('before_open', TurnSchedule::forNow(Carbon::parse('2026-11-18 08:59:00'), $settings)['reason']);
        $this->assertFalse(TurnSchedule::forNow(Carbon::parse('2026-11-18 20:30:00'), $settings)['open']);
    }

    public function test_un_dia_cerrado_no_tiene_cierre_ni_limite(): void
    {
        $sunday = Carbon::parse('2026-11-22 12:00:00');
        $settings = $this->settings(['hours_sun' => 'Cerrado']);

        $this->assertNull(TurnSchedule::closingTime($sunday, $settings));

        $state = TurnSchedule::forNow($sunday, $settings);
        $this->assertFalse($state['open']);
        $this->assertSame('closed', $state['reason']);
        $this->assertNull($state['deadline']);
        $this->assertStringContainsString('cerrada', $state['message']);
        $this->assertStringContainsString('Podrás pedir turno mañana', $state['message']);
    }

    public function test_cambiar_el_horario_del_panel_mueve_el_limite(): void
    {
        $settings = $this->settings(['hours_wed' => '9:00 a. m. - 9:00 p. m.']);

        $this->assertTrue(TurnSchedule::forNow(Carbon::parse('2026-11-18 20:30:00'), $settings)['open']);
        $this->assertFalse(TurnSchedule::forNow(Carbon::parse('2026-11-18 20:31:00'), $settings)['open']);
    }
}
