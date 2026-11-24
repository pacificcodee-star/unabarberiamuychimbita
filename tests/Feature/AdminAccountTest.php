<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/** Configuración → Cuenta del administrador: correo y contraseña de ingreso al panel. */
class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'name' => 'Dueño', 'email' => 'admin@barberia.com', 'password' => 'password',
            'role' => 'admin', 'active' => true,
        ]);
    }

    public function test_settings_page_shows_the_account_form_with_password_toggles(): void
    {
        $this->actingAs($this->admin())->get(route('site-settings.edit'))
            ->assertOk()
            ->assertSee('Cuenta del administrador')
            // El correo sale de la base de datos; el campo Nombre ya no está.
            ->assertSee('value="admin@barberia.com"', false)
            ->assertDontSee('id="account-name"', false)
            ->assertSee('data-password-toggle="account-current_password"', false)
            ->assertSee('data-password-toggle="account-password"', false)
            ->assertSee('data-password-toggle="account-password_confirmation"', false);
    }

    public function test_admin_changes_email_and_password_and_can_log_in_with_them(): void
    {
        $admin = $this->admin();
        $audit = new TestHandler(bubble: false);
        Log::channel('audit')->getLogger()->pushHandler($audit);

        $this->actingAs($admin)->put(route('site-settings.account'), [
            'email' => 'Dueno@Ejemplo.com',
            'current_password' => 'password',
            'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026',
        ])->assertRedirect()->assertSessionHas('success', 'Cuenta actualizada y contraseña cambiada.');

        $records = $audit->getRecords();
        $this->assertSame(['auth.correo_cambiado', 'auth.contrasena_cambiada'], array_column($records, 'message'));
        foreach ($records as $record) {
            $this->assertSame($admin->id, $record->context['usuario_id']);
            $this->assertSame([], array_diff(array_keys($record->context), ['usuario_id', 'rol', 'registro', 'ip', 'request_id']));
        }

        $admin->refresh();
        $this->assertSame('dueno@ejemplo.com', $admin->email);
        $this->assertSame('Dueño', $admin->name, 'El nombre no se toca desde este formulario.');
        $this->assertTrue(Hash::check('NuevaClave2026', $admin->password));

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'dueno@ejemplo.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'dueno@ejemplo.com', 'password' => 'NuevaClave2026'])->assertRedirect(route('dashboard'));
    }

    public function test_wrong_current_password_changes_nothing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('site-settings.account'), [
            'email' => 'otro@ejemplo.com',
            'current_password' => 'equivocada',
            'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026',
        ])->assertSessionHasErrorsIn('account', ['current_password']);

        $admin->refresh();
        $this->assertSame('admin@barberia.com', $admin->email);
        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_new_password_must_be_strong_and_confirmed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('site-settings.account'), [
            'email' => 'admin@barberia.com', 'current_password' => 'password',
            'password' => 'corta', 'password_confirmation' => 'corta',
        ])->assertSessionHasErrorsIn('account', ['password']);

        $this->actingAs($admin)->put(route('site-settings.account'), [
            'email' => 'admin@barberia.com', 'current_password' => 'password',
            'password' => 'NuevaClave2026', 'password_confirmation' => 'OtraClave2026',
        ])->assertSessionHasErrorsIn('account', ['password']);

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_leaving_new_password_empty_keeps_the_current_one(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('site-settings.account'), [
            'email' => 'nuevo@ejemplo.com', 'current_password' => 'password',
            'password' => '', 'password_confirmation' => '',
        ])->assertSessionHas('success', 'Cuenta actualizada.');

        $this->assertSame('nuevo@ejemplo.com', $admin->fresh()->email);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_email_cannot_be_taken_from_another_user(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'carlos@barberia.com', 'role' => 'barbero']);

        $this->actingAs($admin)->put(route('site-settings.account'), [
            'email' => 'carlos@barberia.com', 'current_password' => 'password',
        ])->assertSessionHasErrorsIn('account', ['email']);
    }

    public function test_barbers_cannot_reach_the_account_form(): void
    {
        $barber = User::factory()->create(['role' => 'barbero', 'active' => true]);

        $this->actingAs($barber)->put(route('site-settings.account'), [
            'email' => 'x@ejemplo.com', 'current_password' => 'password',
        ])->assertForbidden();
    }
}
