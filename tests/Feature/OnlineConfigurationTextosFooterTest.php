<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OnlineConfigurationTextosFooterTest extends TestCase
{
    use DatabaseTransactions;

    private function configurar()
    {
        Auth::forgetGuards();
        $this->actingAs(User::findOrFail(500), 'web');
        return OnlineConfiguration::create(['user_id' => 500])->fresh();
    }

    private function guardar($config, array $cambios, array $omitidos = [])
    {
        $payload = array_merge($config->fresh()->toArray(), $cambios);
        foreach ($omitidos as $campo) {
            unset($payload[$campo]);
        }
        return $this->putJson('api/online-configuration/'.$config->id, $payload);
    }

    public function test_persiste_texto_plano_unicode_limites_y_se_puede_volver_al_default()
    {
        $config = $this->configurar();
        $datos = [
            'footer_contacto_titulo' => str_repeat('á', 80),
            'footer_contacto_email' => str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 18).'.ar',
            'footer_contacto_texto' => str_repeat('ñ', 1998)."\nX",
        ];
        $this->assertSame(150, mb_strlen($datos['footer_contacto_email']));
        $this->guardar($config, $datos)->assertOk();
        foreach ($datos as $campo => $valor) {
            $this->assertSame($valor, $config->fresh()->{$campo});
        }
        $this->guardar($config, [], array_keys($datos))->assertOk();
        foreach ($datos as $campo => $valor) {
            $this->assertSame($valor, $config->fresh()->{$campo});
        }
        $this->guardar($config, [
            'footer_contacto_titulo' => '   ',
            'footer_contacto_email' => null,
            'footer_contacto_texto' => '',
        ])->assertOk();
        foreach (array_keys($datos) as $campo) {
            $this->assertNull($config->fresh()->{$campo});
        }
    }

    public function invalidos()
    {
        $casos = [];
        foreach (['footer_contacto_titulo' => 80, 'footer_contacto_email' => 150, 'footer_contacto_texto' => 2000] as $campo => $maximo) {
            foreach ([[], ['x'], (object) ['x' => 'y'], 42, true, str_repeat('á', $maximo + 1), '<script>alert(1)</script>'] as $i => $valor) {
                $casos[$campo.'-'.$i] = [$campo, $valor];
            }
        }
        $casos['email inválido'] = ['footer_contacto_email', 'no es correo'];
        return $casos;
    }

    /** @dataProvider invalidos */
    public function test_rechaza_sin_persistir_ningun_campo($campo, $valor)
    {
        $config = $this->configurar();
        $foto = $config->toArray();
        $this->guardar($config, ['instagram' => 'no debe guardarse', 'footer_contacto_titulo' => 'Nuevo', $campo => $valor])
            ->assertStatus(422)->assertJsonValidationErrors($campo);
        $this->assertEquals($foto, $config->fresh()->toArray());
    }

    public function test_ajeno_y_empleado_sin_administracion_no_modifican_footer()
    {
        $config = $this->configurar();
        $ajeno = OnlineConfiguration::create(['user_id' => 501])->fresh();
        $this->guardar($ajeno, ['footer_contacto_titulo' => 'Intruso'])->assertNotFound();
        $this->assertNull($ajeno->fresh()->footer_contacto_titulo);

        $empleado = User::create(['owner_id' => 500, 'name' => 'Empleado U6', 'email' => 'u6-empleado@example.test', 'password' => 'sintetica']);
        Auth::forgetGuards();
        $this->actingAs($empleado, 'web');
        $this->guardar($config, ['footer_contacto_titulo' => 'Sin permiso'])->assertForbidden();
        $this->assertNull($config->fresh()->footer_contacto_titulo);
        $empleado->admin_access = 1;
        $empleado->save();
        Auth::forgetGuards();
        $this->actingAs($empleado->fresh(), 'web');
        $this->guardar($config, ['footer_contacto_titulo' => 'Administrador'])->assertOk();
        $this->assertSame('Administrador', $config->fresh()->footer_contacto_titulo);
    }
}
