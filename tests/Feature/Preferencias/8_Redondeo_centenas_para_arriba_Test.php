<?php

namespace Tests\Feature\Preferencias;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Opción "Redondear centenas hacia arriba" (`users.redondear_centenas_para_arriba`).
 *
 * Es el sentido del redondeo por centenas (ceil en vez de round), no un sexto modo del select
 * `modo_redondeo`. Lo que estos tests protegen:
 *
 * 1. La columna existe y por defecto está apagada: nadie cambia de comportamiento sin pedirlo.
 * 2. Prender o apagar la opción mueve precios, así que tiene que despachar el recálculo masivo.
 * 3. Un PUT que no la trae (una SPA vieja, o un PUT parcial) NO la toca: asignar null pisaría la
 *    preferencia del cliente y la columna es NOT NULL.
 * 4. Cambiar el modo del select no la apaga ni la prende: es independiente de las cinco columnas.
 *
 * Mismo patrón que `2_Modo_redondeo_Test`: payload = el modelo entero, como lo manda el front.
 * DatabaseTransactions porque la base de testing está sembrada de antes.
 */
class Redondeo_centenas_para_arriba_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Deja al usuario con centenas prendido y la opción nueva en el valor pedido, el resto apagado.
     *
     * @param \App\Models\User $user
     * @param int $para_arriba
     * @return void
     */
    protected function preparar($user, $para_arriba)
    {
        $user->redondear_miles_en_vender = 0;
        $user->redondear_centenas_en_vender = 1;
        $user->redondear_precios_en_decenas = 0;
        $user->redondear_de_a_50 = 0;
        $user->redondear_precios_en_centavos = 0;
        $user->redondear_centenas_para_arriba = $para_arriba;
        $user->save();
    }

    /**
     * Payload del PUT: el modelo entero con lo que se quiera pisar.
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
    public function la_columna_existe_y_por_defecto_esta_apagada()
    {
        $this->assertTrue(Schema::hasColumn('users', 'redondear_centenas_para_arriba'));

        $columnas = DB::select("SHOW COLUMNS FROM users LIKE 'redondear_centenas_para_arriba'");
        $this->assertCount(1, $columnas);
        $this->assertEquals('0', (string) $columnas[0]->Default);
        $this->assertEquals('NO', $columnas[0]->Null);
    }

    /**
     * @group preferencias
     * @test
     */
    public function prenderla_se_guarda_y_despacha_el_recalculo_de_precios()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->preparar($user, 0);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['redondear_centenas_para_arriba' => true]));
        $response->assertStatus(200);

        $this->assertEquals(1, (int) $user->fresh()->redondear_centenas_para_arriba);
        $this->assertEquals(1, (int) $user->fresh()->redondear_centenas_en_vender);
        Queue::assertPushed(ProcessSetFinalPrices::class);
    }

    /**
     * @group preferencias
     * @test
     */
    public function apagarla_se_guarda_y_despacha_el_recalculo_de_precios()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->preparar($user, 1);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['redondear_centenas_para_arriba' => false]));
        $response->assertStatus(200);

        $this->assertEquals(0, (int) $user->fresh()->redondear_centenas_para_arriba);
        Queue::assertPushed(ProcessSetFinalPrices::class);
    }

    /**
     * Línea base: guardar sin cambiarla no dispara un recálculo masivo. Sin esto, los dos tests de
     * arriba pasarían igual con un controller que despachara siempre.
     *
     * @group preferencias
     * @test
     */
    public function guardar_sin_cambiarla_no_despacha_el_recalculo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->preparar($user, 1);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user));
        $response->assertStatus(200);

        $this->assertEquals(1, (int) $user->fresh()->redondear_centenas_para_arriba);
        Queue::assertNotPushed(ProcessSetFinalPrices::class);
    }

    /**
     * 🔴 Compatibilidad con la SPA vieja: el payload no trae la clave. La preferencia del cliente
     * tiene que quedar como estaba, sin error 500 por asignar null a una columna NOT NULL, y sin
     * despachar un recálculo.
     *
     * @group preferencias
     * @test
     */
    public function un_put_sin_la_clave_no_la_toca()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->preparar($user, 1);

        Queue::fake();

        $payload = $this->payload($user);
        unset($payload['redondear_centenas_para_arriba']);

        $response = $this->putJson('api/user/'.$user->id, $payload);
        $response->assertStatus(200);

        $this->assertEquals(1, (int) $user->fresh()->redondear_centenas_para_arriba);
        Queue::assertNotPushed(ProcessSetFinalPrices::class);
    }

    /**
     * Cambiar de modo en el select no toca la opción: es independiente de las cinco columnas.
     * (Sin centenas prendido no tiene efecto, y al volver a centenas el cliente la encuentra como
     * la dejó.)
     *
     * @group preferencias
     * @test
     */
    public function cambiar_el_modo_del_select_no_modifica_la_opcion()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->preparar($user, 1);

        Queue::fake();

        $response = $this->putJson('api/user/'.$user->id, $this->payload($user, ['modo_redondeo' => 'miles']));
        $response->assertStatus(200);

        $fresco = $user->fresh();
        $this->assertEquals(1, (int) $fresco->redondear_miles_en_vender);
        $this->assertEquals(0, (int) $fresco->redondear_centenas_en_vender);
        $this->assertEquals(1, (int) $fresco->redondear_centenas_para_arriba);
    }
}
