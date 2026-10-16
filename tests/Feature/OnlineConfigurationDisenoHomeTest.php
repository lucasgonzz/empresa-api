<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de diseno_home (mision cruzada inicio-todas-las-categorias, 16/10/2026): el
 * comercio elige, desde Configuracion Online, como se ve la portada de su tienda. Dos valores:
 * 'ultimos_ingresos' (el inicio de siempre, default de la columna) y 'lista_categorias' (todas
 * las categorias en una lista desplegable debajo del banner). Lo lee tienda-spa (Home.vue).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria. Mismo patron que OnlineConfigurationMostrarCatalogoCategoriasHomeTest.
 */
class OnlineConfigurationDisenoHomeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama. Null si la base de testing no lo tiene
     * sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Autentica al usuario de testing y crea una configuracion online con lo minimo (user_id):
     * el resto de las columnas cae a su default de la migracion.
     *
     * @return \App\Models\OnlineConfiguration
     */
    protected function crear_configuracion_autenticado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        return OnlineConfiguration::create(['user_id' => 500]);
    }

    /**
     * Arma el PUT con el modelo entero (asi lo manda siempre el formulario ABM generico de la SPA:
     * update() reasigna la mayoria de las columnas sin fallback, y un payload parcial pisaria
     * columnas NOT NULL con null) y le pisa/agrega las claves de $cambios.
     *
     * @param  \App\Models\OnlineConfiguration  $config
     * @param  array  $cambios
     * @return array
     */
    protected function payload_completo($config, array $cambios)
    {
        return array_merge($config->fresh()->toArray(), $cambios);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function guarda_lista_categorias()
    {
        $config = $this->crear_configuracion_autenticado();

        $response = $this->putJson('api/online-configuration/' . $config->id, $this->payload_completo($config, [
            'diseno_home' => 'lista_categorias',
        ]));

        $response->assertStatus(200);
        $this->assertSame('lista_categorias', $config->fresh()->diseno_home);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function vuelve_a_ultimos_ingresos()
    {
        $config = $this->crear_configuracion_autenticado();
        $config->diseno_home = 'lista_categorias';
        $config->save();

        $response = $this->putJson('api/online-configuration/' . $config->id, $this->payload_completo($config, [
            'diseno_home' => 'ultimos_ingresos',
        ]));

        $response->assertStatus(200);
        $this->assertSame('ultimos_ingresos', $config->fresh()->diseno_home);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function un_valor_invalido_queda_en_ultimos_ingresos()
    {
        $config = $this->crear_configuracion_autenticado();
        $config->diseno_home = 'lista_categorias';
        $config->save();

        $response = $this->putJson('api/online-configuration/' . $config->id, $this->payload_completo($config, [
            'diseno_home' => 'cualquiera',
        ]));

        $response->assertStatus(200);
        $this->assertSame('ultimos_ingresos', $config->fresh()->diseno_home);
    }

    /**
     * El select de empresa-spa antepone siempre la opcion "Seleccione..." con value 0
     * (getOptions de common-vue). Con un in_array laxo, 0 == 'ultimos_ingresos' da true en
     * PHP 7.4 y se guardaria "0": este caso es el que exige la comparacion estricta.
     *
     * @test
     * @group configuracion-online
     */
    public function el_cero_de_seleccione_queda_en_ultimos_ingresos()
    {
        $config = $this->crear_configuracion_autenticado();
        $config->diseno_home = 'lista_categorias';
        $config->save();

        $response = $this->putJson('api/online-configuration/' . $config->id, $this->payload_completo($config, [
            'diseno_home' => 0,
        ]));

        $response->assertStatus(200);
        $this->assertSame('ultimos_ingresos', $config->fresh()->diseno_home);
    }

    /**
     * La clave viene pero en null (o vacia, que el middleware de Laravel convierte en null):
     * tambien vuelve al de siempre.
     *
     * @group configuracion-online
     * @test
     */
    public function un_valor_null_queda_en_ultimos_ingresos()
    {
        $config = $this->crear_configuracion_autenticado();
        $config->diseno_home = 'lista_categorias';
        $config->save();

        $response = $this->putJson('api/online-configuration/' . $config->id, $this->payload_completo($config, [
            'diseno_home' => null,
        ]));

        $response->assertStatus(200);
        $this->assertSame('ultimos_ingresos', $config->fresh()->diseno_home);
    }

    /**
     * Una pantalla vieja de empresa-spa manda el PUT sin la clave: no tiene que pisar lo guardado.
     *
     * @group configuracion-online
     * @test
     */
    public function un_put_sin_la_clave_no_pisa_lista_categorias()
    {
        $config = $this->crear_configuracion_autenticado();
        $config->diseno_home = 'lista_categorias';
        $config->save();

        $payload = $this->payload_completo($config, []);
        unset($payload['diseno_home']);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        $this->assertSame('lista_categorias', $config->fresh()->diseno_home);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function una_configuracion_nueva_arranca_en_ultimos_ingresos()
    {
        $config = $this->crear_configuracion_autenticado();

        $this->assertSame('ultimos_ingresos', $config->fresh()->diseno_home);
    }
}
