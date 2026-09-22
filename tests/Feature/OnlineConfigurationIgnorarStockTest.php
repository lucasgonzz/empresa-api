<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de ignorar_stock (mision cruzada marcas-contraste-y-sin-stock, 22/9/2026): toggle
 * nuevo en Configuracion Online que hace que la tienda entera se comporte, para todo articulo,
 * como ya se comporta hoy un articulo con stock null (ver `hasStock()` en
 * tienda-spa/src/mixins/articles.js) — ningun articulo se muestra agotado ni tiene tope de
 * cantidad al comprar. Arranca en false (columna NOT NULL default false, ver la migracion), al
 * reves que mostrar_stock_disponible: es un comportamiento nuevo y mas agresivo (vender sin
 * controlar stock), asi que tiene que nacer apagado para los clientes existentes.
 *
 * El segundo test cubre el mismo motivo que ya vale para mostrar_stock_disponible: el controller
 * usa $request->boolean() con fallback al valor actual del modelo (no un default fijo), porque el
 * formulario ABM manda el modelo entero pero una pantalla vieja que todavia no conoce esta clave
 * no tiene que poder apagar un toggle que el comercio ya habia prendido a proposito.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria.
 */
class OnlineConfigurationIgnorarStockTest extends TestCase
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
     * @group configuracion-online
     * @test
     */
    public function actualizar_la_configuracion_online_persiste_ignorar_stock_en_true()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Se crea la fila con lo minimo (user_id): mismo patron que HelperController/UserSeeder,
        // el resto de las columnas cae a su default de la migracion (ignorar_stock arranca en
        // false).
        $config = OnlineConfiguration::create(['user_id' => 500]);
        $this->assertEquals(0, (int) $config->fresh()->ignorar_stock);

        // El PUT manda el modelo entero (asi lo hace siempre el formulario ABM generico de la
        // SPA): update() reasigna cada columna con lo que venga en el request sin fallback para
        // la mayoria de los campos, asi que un payload parcial pisa esas columnas con null y
        // rompe la constraint. Se parte de los valores actuales y se agrega/pisa solo la clave
        // nueva de esta mision.
        $payload = array_merge($config->fresh()->toArray(), [
            'ignorar_stock' => true,
        ]);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        $this->assertEquals(1, (int) $config->fresh()->ignorar_stock);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function no_mandar_la_clave_mantiene_el_valor_ya_prendido()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // El comercio ya lo tenia prendido.
        $config = OnlineConfiguration::create([
            'user_id' => 500,
            'ignorar_stock' => true,
        ]);

        // Simula una pantalla vieja que todavia no conoce esta clave: el payload no la incluye.
        $payload = $config->fresh()->toArray();
        unset($payload['ignorar_stock']);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        // Si el controller defaulteara a false en vez de al valor actual del modelo, este PUT sin
        // la clave apagaria un toggle que el comercio habia prendido a proposito.
        $this->assertEquals(1, (int) $config->fresh()->ignorar_stock);
    }
}
