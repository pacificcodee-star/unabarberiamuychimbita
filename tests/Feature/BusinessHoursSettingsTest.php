<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Configuración → Horario de atención: cada día es "Cerrado" o un rango de horas. */
class BusinessHoursSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Formulario válido; $hours reemplaza días concretos. */
    private function payload(array $hours = []): array
    {
        $week = [];
        foreach (array_keys(SiteSetting::DAYS) as $day) {
            $week[$day] = $day === 'sun' ? ['closed' => '1'] : ['closed' => '0', 'open' => '09:00', 'close' => '20:00'];
        }

        return [
            'data' => [
                'address' => 'Calle 1 # 2-3', 'city' => 'Bogotá, Colombia', 'phone' => '+57 300 000 0000',
                'email' => 'hola@ejemplo.com', 'whatsapp' => '573000000000',
                'map_url' => 'https://www.google.com/maps?q=Bogota&output=embed',
            ],
            'hours' => array_replace($week, $hours),
        ];
    }

    public function test_ranges_and_closed_days_are_saved_as_the_text_the_site_reads(): void
    {
        $this->actingAs($this->admin())
            ->put(route('site-settings.update'), $this->payload([
                'sat' => ['closed' => '0', 'open' => '08:30', 'close' => '12:00'],
                'mon' => ['closed' => '1'],
            ]))
            ->assertSessionHasNoErrors()->assertRedirect(route('site-settings.edit'));

        $data = SiteSetting::currentData();
        $this->assertSame('9:00 a. m. - 8:00 p. m.', $data['hours_tue']);
        $this->assertSame('8:30 a. m. - 12:00 p. m.', $data['hours_sat']);
        $this->assertSame('Cerrado', $data['hours_mon']);
        $this->assertSame('Cerrado', $data['hours_sun']);
        $this->assertTrue(SiteSetting::isClosedOn(now()->next('Monday'), $data));
    }

    public function test_the_form_is_prefilled_from_the_saved_hours(): void
    {
        SiteSetting::query()->create(['data' => ['hours_wed' => '10:00 a. m. - 7:30 p. m.', 'hours_sun' => 'Cerrado']]);

        $this->actingAs($this->admin())->get(route('site-settings.edit'))
            ->assertOk()
            ->assertSee('id="hours-wed-open" type="time" name="hours[wed][open]" value="10:00"', false)
            ->assertSee('id="hours-wed-close" type="time" name="hours[wed][close]" value="19:30"', false)
            ->assertSee('id="hours-sun-closed" name="hours[sun][closed]" value="1" checked', false);
    }

    public function test_open_day_needs_both_hours_and_closing_after_opening(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('site-settings.update'), $this->payload(['fri' => ['closed' => '0', 'open' => '09:00', 'close' => '']]))
            ->assertSessionHasErrors('hours.fri');

        $this->actingAs($admin)
            ->put(route('site-settings.update'), $this->payload(['fri' => ['closed' => '0', 'open' => '18:00', 'close' => '09:00']]))
            ->assertSessionHasErrors('hours.fri');
    }

    public function test_day_range_reads_the_old_free_text(): void
    {
        $this->assertSame(['closed' => false, 'open' => '09:00', 'close' => '20:00'], SiteSetting::dayRange('9:00 a. m. - 8:00 p. m.'));
        $this->assertSame(['closed' => true, 'open' => '', 'close' => ''], SiteSetting::dayRange('Cerrado'));
        $this->assertSame('12:00 p. m. - 12:30 a. m.', SiteSetting::rangeText('12:00', '00:30'));
    }
}
