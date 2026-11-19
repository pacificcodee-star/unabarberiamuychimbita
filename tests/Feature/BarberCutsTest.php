<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BarberCuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BarberCutsTest extends TestCase
{
    use RefreshDatabase;

    private function barberPayload(User $barber, array $extra = []): array
    {
        return array_merge([
            'name' => $barber->name,
            'attention_mode' => 'llegada',
            'active' => 1,
        ], $extra);
    }

    /** Foto real del proyecto como archivo subido (no requiere la extensión GD). */
    private function photo(string $name): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'corte');
        copy(public_path('images/'.$name), $copy);

        return new UploadedFile($copy, $name, null, null, true);
    }

    public function test_admin_uploads_and_removes_cut_photos_from_the_barber_profile(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);

        $this->actingAs($admin)->put(route('barbers.update', $barber), $this->barberPayload($barber, [
            'cuts' => [$this->photo('logo-una-barberia.png'), $this->photo('portada-barberia.jpg')],
        ]))->assertRedirect(route('barbers.index'));

        $cuts = BarberCuts::for($barber);
        $this->assertCount(2, $cuts);
        // Se guardan las medidas reales para que la galería no deforme la foto.
        $expected = collect(['logo-una-barberia.png', 'portada-barberia.jpg'])->map(fn ($name) => array_slice(getimagesize(public_path('images/'.$name)), 0, 2))->all();
        $this->assertEqualsCanonicalizing($expected, $cuts->map(fn ($cut) => [$cut['width'], $cut['height']])->all());

        // Aparecen en su perfil público y en el inicio.
        $this->get('/barberos')->assertOk()->assertSee($cuts[0]['url'], false);
        $this->get('/')->assertOk()->assertSee($cuts[0]['url'], false)->assertSee('Nuestros trabajos');

        // Quitar una foto desde el formulario.
        $this->actingAs($admin)->put(route('barbers.update', $barber), $this->barberPayload($barber, [
            'remove_cuts' => [$cuts[0]['name'], '../../otro-archivo.jpg'],
        ]))->assertRedirect(route('barbers.index'));
        $this->assertCount(1, BarberCuts::for($barber));
    }

    public function test_cut_photos_must_be_images(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);

        $this->actingAs($admin)->put(route('barbers.update', $barber), $this->barberPayload($barber, [
            'cuts' => [UploadedFile::fake()->create('virus.exe', 20, 'application/octet-stream')],
        ]))->assertSessionHasErrors('cuts.0');

        $this->assertCount(0, BarberCuts::for($barber));
    }

    public function test_the_edit_form_shows_the_cuts_section(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);

        $this->actingAs($admin)->get(route('barbers.edit', $barber))->assertOk()->assertSee('Cortes realizados');
    }
}
