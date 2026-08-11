<?php

namespace Tests\Feature\Preferencias;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tarea 4 — feature tests del select "Opciones de redondeo" (`modo_redondeo`), que es una FACHADA
 * sobre las cinco columnas booleanas de redondeo de `users`.
 *
 * Lo que estos tests protegen no es que el select funcione: es que **no le mueva los precios a
 * nadie que no haya elegido moverlos**. Hay clientes reales con dos flags de redondeo prendidos, y
 * como `ArticleHelper::redondear()` los encadena, esa combinación da un resultado propio que ningún
 * valor único del select representa. Si el endpoint colapsara ese estado, el próximo recálculo les
 * cambiaría el precio de todo el catálogo sin que hayan pedido nada.
 *
 * El escenario que lo hace peligroso es cotidiano: `ModelForm` postea el modelo ENTERO, así que un
 * cliente que entra a cambiarse el teléfono manda `modo_redondeo: 'personalizado'` de vuelta sin
 * haber tocado el select. Por eso los tests arman el payload con `$user->toArray()` y pisan sólo lo
 * que corresponda — es exactamente lo que manda el frontend, no un request mínimo de laboratorio.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un refresh
 * la vaciaría, rompiendo el resto de las suites.
 */
class Modo_redondeo_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Las cinco columnas booleanas sobre las que el select es fachada.
     *
     * @var array<int, string>
     */
    const COLUMNAS = [
        'redondear_miles_en_vender',
        'redondear_centenas_en_vender',
        'redondear_precios_en_decenas',
        'redondear_de_a_50',
        'redondear_precios_en_centavos',
    ];

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que Preferencias/Sales/Stock).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Deja al usuario con las columnas de redondeo que se le pasen en 1 y el resto en 0.
     *
     * @param \App\Models\User $user
     * @param array<int, string> $columnas_prendidas
     * @return void
     */
    protected function poner_columnas($user, array $columnas_prendidas)
    {
        foreach (self::COLUMNAS as $columna) {
            $user->$columna = in_array($columna, $columnas_prendidas, true) ? 1 : 0;
        }

        $user->save();
    }

    /**
     * Devuelve las cinco columnas del usuario, leídas de la base.
     *
     * @param \App\Models\User $user
     * @return array<string, int>
     */
    protected function leer_columnas($user)
    {
        $fresco = $user->fresh();
        $valores = [];

        foreach (self::COLUMNAS as $columna) {
            $valores[$columna] = (int) $fresco->$columna;
        }

        return $valores;
    }

    /**
     * Payload del PUT tal como lo manda el frontend: el modelo entero, con lo que se quiera pisar.
     *
     * @param \App\Models\User $user
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function payload($user, array $overrides = [])
    {
        return array_merge($user->fresh()->toArray(), $overrides);
    }

    /**
     * @group preferencias
     * @test
     */
    public function elegir_un_modo_deja_exactamente_una_columna_prendida()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, []);

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'cincuenta']));
        $response->assertStatus(200);

        $this->assertEquals([
            'redondear_miles_en_vender'      => 0,
            'redondear_centenas_en_vender'   => 0,
            'redondear_precios_en_decenas'   => 0,
            'redondear_de_a_50'              => 1,
            'redondear_precios_en_centavos'  => 0,
        ], $this->leer_columnas($user));
    }

    /**
     * 🔴 El test que protege a los clientes reales. Si este se cae, la funcionalidad no sale.
     *
     * @group preferencias
     * @test
     */
    public function guardar_con_personalizado_no_toca_ninguna_de_las_cinco_columnas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_centenas_en_vender', 'redondear_de_a_50']);

        $antes = $this->leer_columnas($user);

        // El valor 'personalizado' es el que el propio accessor le mandó en el GET: el cliente lo
        // devuelve sin haber tocado el select.
        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'personalizado']));
        $response->assertStatus(200);

        $this->assertEquals($antes, $this->leer_columnas($user));
        // Y explícitamente: la combinación que tenía sigue siendo la que tiene.
        $this->assertEquals(1, $this->leer_columnas($user)['redondear_centenas_en_vender']);
        $this->assertEquals(1, $this->leer_columnas($user)['redondear_de_a_50']);
    }

    /**
     * @group preferencias
     * @test
     */
    public function guardar_sin_mandar_modo_redondeo_no_toca_ninguna_de_las_cinco_columnas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_centenas_en_vender', 'redondear_de_a_50']);

        $antes = $this->leer_columnas($user);

        $payload = $this->payload($user);
        unset($payload['modo_redondeo']);

        $response = $this->putJson('api/user/'.$user->id, $payload);
        $response->assertStatus(200);

        $this->assertEquals($antes, $this->leer_columnas($user));
    }

    /**
     * Un valor que no está en la tabla de modos cae en la misma rama que 'personalizado': no se
     * adivina nada. Sin esto, un typo del frontend le colapsaría la configuración a un cliente.
     *
     * @group preferencias
     * @test
     */
    public function un_modo_desconocido_no_toca_ninguna_de_las_cinco_columnas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_precios_en_decenas']);

        $antes = $this->leer_columnas($user);

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'de_a_7']));
        $response->assertStatus(200);

        $this->assertEquals($antes, $this->leer_columnas($user));
    }

    /**
     * Este es el caso que ANTES fallaba: check_actualizar_articulos() no comparaba
     * redondear_centenas_en_vender, así que elegir "de a 100" no despachaba el recálculo y los
     * precios quedaban con el redondeo viejo.
     *
     * @group preferencias
     * @test
     */
    public function pasar_a_centenas_despacha_el_recalculo_de_precios()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, []);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'centenas']));
        $response->assertStatus(200);

        Queue::assertPushed(ProcessSetFinalPrices::class);
    }

    /**
     * Contracara del anterior: guardar sin cambiar el modo NO tiene que disparar un recálculo
     * masivo. Sin esta línea base, el test de arriba pasaría igual con un helper que despachara
     * siempre.
     *
     * @group preferencias
     * @test
     */
    public function guardar_sin_cambiar_el_modo_no_despacha_el_recalculo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_centenas_en_vender']);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'centenas']));
        $response->assertStatus(200);

        Queue::assertNotPushed(ProcessSetFinalPrices::class);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_get_devuelve_el_modo_derivado_de_las_columnas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Ninguna prendida.
        $this->poner_columnas($user, []);
        $this->assertEquals('sin_redondeo', $user->fresh()->modo_redondeo);

        // Exactamente una.
        $this->poner_columnas($user, ['redondear_de_a_50']);
        $this->assertEquals('cincuenta', $user->fresh()->modo_redondeo);

        $this->poner_columnas($user, ['redondear_miles_en_vender']);
        $this->assertEquals('miles', $user->fresh()->modo_redondeo);

        // Dos o más.
        $this->poner_columnas($user, ['redondear_centenas_en_vender', 'redondear_de_a_50']);
        $this->assertEquals('personalizado', $user->fresh()->modo_redondeo);
    }

    /**
     * El atributo tiene que viajar en la serialización, que es de donde lo lee el formulario.
     *
     * @group preferencias
     * @test
     */
    public function el_modo_viaja_en_la_serializacion_del_usuario()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->poner_columnas($user, ['redondear_precios_en_centavos']);

        $serializado = $user->fresh()->toArray();

        $this->assertArrayHasKey('modo_redondeo', $serializado);
        $this->assertEquals('centavos', $serializado['modo_redondeo']);
    }
}
