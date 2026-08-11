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
     * Igual que `payload()`, pero con las cinco columnas crudas en 0 — o sea CONTRADICIENDO el
     * estado de la base.
     *
     * 🔴 Por qué existe, y es una corrección a la primera versión de esta suite. Los tres tests de
     * no-op armaban el request con `$user->fresh()->toArray()`, así que las columnas crudas del
     * payload ya venían **iguales** a lo que había en la base. Con eso el aserto "las cinco quedaron
     * idénticas" se cumplía por dos motivos distintos e indistinguibles: porque la fachada no tocó
     * nada (lo que se quiere probar), o porque alguien escribió las columnas crudas del request
     * encima con esos mismos valores (lo que se quiere prohibir). Los tres pasaban tal cual contra
     * `origin/develop`, donde el controller hacía `$model->redondear_x = $request->redondear_x`.
     *
     * Un test que pasa igual con el código viejo no está midiendo el cambio. Y peor: seguiría
     * pasando el día que alguien reintrodujera las asignaciones directas al lado de la fachada, que
     * es justamente el escenario contra el que estos tests dicen proteger.
     *
     * Con las crudas en 0 y la base en 1, las dos explicaciones se separan: si el endpoint honrara
     * las columnas del request, la base terminaría en 0 y el test se cae.
     *
     * @param \App\Models\User $user
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function payload_que_contradice($user, array $overrides = [])
    {
        $crudas = [];

        foreach (self::COLUMNAS as $columna) {
            $crudas[$columna] = 0;
        }

        return $this->payload($user, array_merge($crudas, $overrides));
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
     * 🔴 El agujero exacto por el que se coló la regresión: la suite probaba `apagado → cincuenta`
     * pero nunca `prendido → apagado`. El único assert de `'sin_redondeo'` era del accessor de
     * LECTURA, y la lectura andaba bien; lo que no andaba era el PUT.
     *
     * `sin_redondeo` no le corresponde a ninguna columna, así que no está en
     * `COLUMNAS_MODO_REDONDEO` y caía en el early-return de "modo desconocido": el cliente elegía
     * la primera opción del select, recibía 200 OK y el select volvía solo a la opción anterior.
     * Sin este test, ese camino no lo mira nadie — y el gate del carril tampoco, porque una
     * funcionalidad que nunca funcionó no regresiona.
     *
     * Va con el `assertPushed` adentro a propósito: apagar el redondeo cambia el precio final de
     * todo el catálogo tanto como prenderlo. Si el recálculo no se despachara, el cliente vería los
     * precios redondeados igual que antes y creería que la opción no hizo nada.
     *
     * @group preferencias
     * @test
     */
    public function apagar_el_redondeo_pone_las_cinco_columnas_en_cero_y_despacha_el_recalculo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_de_a_50']);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'sin_redondeo']));
        $response->assertStatus(200);

        $this->assertEquals([
            'redondear_miles_en_vender'      => 0,
            'redondear_centenas_en_vender'   => 0,
            'redondear_precios_en_decenas'   => 0,
            'redondear_de_a_50'              => 0,
            'redondear_precios_en_centavos'  => 0,
        ], $this->leer_columnas($user));

        Queue::assertPushed(ProcessSetFinalPrices::class);
    }

    /**
     * Contracara de lectura del anterior: después de apagar, el accessor tiene que devolver
     * `sin_redondeo`, que es lo que hace que el select muestre "Sin redondeo" al recargar en vez de
     * la opción vieja. Sin esta vuelta completa, la escritura podría ser correcta y la pantalla
     * seguir mintiendo.
     *
     * @group preferencias
     * @test
     */
    public function despues_de_apagar_el_usuario_lee_sin_redondeo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_centenas_en_vender']);

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'sin_redondeo']));
        $response->assertStatus(200);

        $this->assertEquals('sin_redondeo', $user->fresh()->modo_redondeo);
    }

    /**
     * Apagar desde una combinación de dos o más también tiene que funcionar: es el único camino que
     * tiene un cliente con `personalizado` para salir de ese estado, porque el select le ofrece esa
     * opción deshabilitada.
     *
     * @group preferencias
     * @test
     */
    public function apagar_el_redondeo_desde_una_combinacion_personalizada_apaga_las_cinco()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->poner_columnas($user, ['redondear_centenas_en_vender', 'redondear_de_a_50']);

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'sin_redondeo']));
        $response->assertStatus(200);

        $this->assertEquals([
            'redondear_miles_en_vender'      => 0,
            'redondear_centenas_en_vender'   => 0,
            'redondear_precios_en_decenas'   => 0,
            'redondear_de_a_50'              => 0,
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
        // devuelve sin haber tocado el select. Y las cinco crudas van en 0 para que el aserto
        // discrimine: contra el código viejo, que escribía `$model->redondear_x = $request->x`,
        // esto dejaría la combinación apagada y el test se caería. Ver `payload_que_contradice()`.
        $response = $this->putJson('api/user/'.$user->id, $this->payload_que_contradice($user, ['modo_redondeo' => 'personalizado']));
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

        // Las cinco crudas en 0 y sin `modo_redondeo`: el request no trae NADA que autorice a
        // apagar, pero sí trae los valores que el controller viejo habría escrito. Ver
        // `payload_que_contradice()`.
        $payload = $this->payload_que_contradice($user);
        unset($payload['modo_redondeo']);

        $response = $this->putJson('api/user/'.$user->id, $payload);
        $response->assertStatus(200);

        $this->assertEquals($antes, $this->leer_columnas($user));
        $this->assertEquals(1, $this->leer_columnas($user)['redondear_centenas_en_vender']);
        $this->assertEquals(1, $this->leer_columnas($user)['redondear_de_a_50']);
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

        // Crudas en 0 + un modo que no existe. Que la columna siga en 1 es lo único que prueba que
        // el endpoint dejó de mirar las columnas del request. Ver `payload_que_contradice()`.
        $response = $this->putJson('api/user/'.$user->id, $this->payload_que_contradice($user, ['modo_redondeo' => 'de_a_7']));
        $response->assertStatus(200);

        $this->assertEquals($antes, $this->leer_columnas($user));
        $this->assertEquals(1, $this->leer_columnas($user)['redondear_precios_en_decenas']);
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
