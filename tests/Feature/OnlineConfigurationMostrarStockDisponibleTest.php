<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de mostrar_stock_disponible (mision cruzada stock-visible-y-nombre-negrita,
 * 21/9/2026): toggle nuevo en Configuracion Online que decide si la ficha del articulo de la
 * tienda muestra el texto "Stock disponible" y la cantidad de unidades disponibles. Arranca en
 * true (columna NOT NULL default true, ver la migracion) porque hoy ese texto siempre se
 * muestra y ningun comercio existente tiene que cambiar de comportamiento al actualizarse.
 *
 * El segundo test cubre el motivo real por el que el controller usa $request->boolean() con
 * fallback al valor actual del modelo (y no un default fijo en true): el formulario ABM manda
 * el modelo entero, pero una pantalla vieja que todavia no conoce esta clave no tiene que poder
 * reactivar un toggle que el comercio ya habia apagado.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria.
 */
class OnlineConfigurationMostrarStockDisponibleTest extends TestCase
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
    public function actualizar_la_configuracion_online_persiste_mostrar_stock_disponible_en_false()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Se crea la fila con lo minimo (user_id): mismo patron que HelperController/UserSeeder,
        // el resto de las columnas cae a su default de la migracion (mostrar_stock_disponible
        // arranca en true).
        $config = OnlineConfiguration::create(['user_id' => 500]);
        $this->assertEquals(1, (int) $config->fresh()->mostrar_stock_disponible);

        // El PUT manda el modelo entero (asi lo hace siempre el formulario ABM generico de la
        // SPA): update() reasigna cada columna con lo que venga en el request sin fallback para
        // la mayoria de los campos, asi que un payload parcial pisa esas columnas con null y
        // rompe la constraint. Se parte de los valores actuales y se agrega/pisa solo la clave
        // nueva de esta mision.
        $payload = array_merge($config->fresh()->toArray(), [
            'mostrar_stock_disponible' => false,
        ]);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        $this->assertEquals(0, (int) $config->fresh()->mostrar_stock_disponible);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function no_mandar_la_clave_mantiene_el_valor_ya_apagado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // El comercio ya lo tenia apagado.
        $config = OnlineConfiguration::create([
            'user_id' => 500,
            'mostrar_stock_disponible' => false,
        ]);

        // Simula una pantalla vieja que todavia no conoce esta clave: el payload no la incluye.
        $payload = $config->fresh()->toArray();
        unset($payload['mostrar_stock_disponible']);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        // Si el controller defaulteara a true en vez de al valor actual del modelo, este PUT
        // sin la clave reactivaria un toggle que el comercio habia apagado a proposito.
        $this->assertEquals(0, (int) $config->fresh()->mostrar_stock_disponible);
    }
}
