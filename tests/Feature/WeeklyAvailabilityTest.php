<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WeeklyAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_applies_one_range_to_multiple_days_every_week(): void
    {
        $this->travelTo(now()->parse('2026-11-22 09:00:00'));
        $barber = User::factory()->create(['role' => 'barbero', 'active' => true, 'attention_mode' => 'citas']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('availability.index'))
            ->assertOk()
            ->assertSee('Días de la semana')
            ->assertDontSee('type="date"', false);

        $this->post(route('availability.store'), [
            'barber_id' => $barber->id, 'weekdays' => [1, 3, 5], 'type' => 'range',
            'start_time' => '13:00', 'end_time' => '15:00', 'note' => 'Pausa',
        ])->assertSessionHasNoErrors()->assertRedirect(route('availability.index', ['barber_id' => $barber->id]));

        $this->assertSame([1, 3, 5], $barber->unavailabilities()->orderBy('weekday')->pluck('weekday')->all());
        foreach (['2026-11-23', '2026-11-25', '2026-11-27', '2026-11-30'] as $date) {
            $this->getJson(route('public.booking.slots', ['date' => $date, 'barber_id' => $barber->id]))
                ->assertOk()->assertJsonMissing(['13:00'])->assertJsonMissing(['14:30'])->assertJsonFragment(['15:00']);
        }
        $this->assertContains('13:00', Appointment::availableTimes($barber, '2026-11-24'));

        try {
            Appointment::reserveSlot([
                'barber_id' => $barber->id, 'appointment_date' => '2026-11-30',
                'appointment_time' => '13:00', 'client_name' => 'Cliente',
            ]);
            $this->fail('El bloqueo debe impedir guardar una cita en esa franja.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment_time', $exception->errors());
        }
    }

    public function test_full_days_and_existing_dates_keep_their_own_scope(): void
    {
        $this->travelTo(now()->parse('2026-11-22 09:00:00'));
        $barber = User::factory()->create(['role' => 'barbero']);
        $legacy = $barber->unavailabilities()->create(['date' => '2026-11-24']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->post(route('availability.store'), [
            'barber_id' => $barber->id, 'weekdays' => [1, 5], 'type' => 'day',
        ])->assertSessionHasNoErrors();

        $this->get(route('availability.index', ['barber_id' => $barber->id]))
            ->assertOk()->assertSee('Cada semana')->assertSee('Una sola vez');

        $this->assertTrue($barber->isUnavailableOnDate('2026-11-30'));
        $this->assertTrue($barber->isUnavailableAt('2026-12-04', '17:30'));
        $this->assertTrue($barber->isUnavailableOnDate('2026-11-24'));
        $this->assertFalse($barber->isUnavailableOnDate('2026-12-01'));

        $monday = $barber->unavailabilities()->where('weekday', 1)->firstOrFail();
        $this->delete(route('availability.destroy', $monday))->assertRedirect();
        $this->assertFalse($barber->fresh()->isUnavailableOnDate('2026-11-30'));
        $this->assertTrue($barber->fresh()->isUnavailableOnDate('2026-12-04'));
        $this->assertModelExists($legacy);
    }

    public function test_conflicts_reject_the_whole_selection_and_adjacent_ranges_are_allowed(): void
    {
        $barber = User::factory()->create(['role' => 'barbero']);
        $barber->unavailabilities()->create(['weekday' => 1, 'start_time' => '13:00', 'end_time' => '15:00']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $url = route('availability.store');
        $selection = ['barber_id' => $barber->id, 'weekdays' => [1, 3], 'type' => 'range', 'end_time' => '16:00'];
        $this->post($url, $selection + ['start_time' => '14:00'])->assertSessionHasErrors('weekdays');
        $this->assertDatabaseCount('barber_unavailabilities', 1);

        $this->post($url, $selection + ['start_time' => '15:00'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('barber_unavailabilities', 3);
    }
}
