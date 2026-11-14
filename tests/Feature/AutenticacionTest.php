<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HU-01: Iniciar sesión con permisos por rol.
 */
class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_de_login_carga(): void
    {
        $this->get(route('login.index'))
            ->assertOk()
            ->assertViewIs('auth.login');
    }

    public function test_un_usuario_activo_inicia_sesion_y_llega_al_panel(): void
    {
        $user = User::factory()->create(['email' => 'cajero@brysa.test']);

        $this->post(route('login.login'), [
            'email' => 'cajero@brysa.test',
            'password' => 'password',
        ])->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_con_contrasena_incorrecta_no_entra(): void
    {
        User::factory()->create(['email' => 'cajero@brysa.test']);

        $this->post(route('login.login'), [
            'email' => 'cajero@brysa.test',
            'password' => 'clave-equivocada',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_un_usuario_inactivo_no_puede_entrar(): void
    {
        $user = User::factory()->create(['email' => 'inactivo@brysa.test']);
        $user->forceFill(['estado' => 0])->save();

        $this->post(route('login.login'), [
            'email' => 'inactivo@brysa.test',
            'password' => 'password',
        ])->assertRedirect(route('login.index'))
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_un_invitado_es_enviado_al_login(): void
    {
        $this->get(route('ventas.index'))
            ->assertRedirect(route('login.index'));
    }

    public function test_un_usuario_sin_permiso_recibe_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('ventas.create'))
            ->assertForbidden();
    }
}
