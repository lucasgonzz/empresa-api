<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de add_to_cart_button_color (mision cruzada color-boton-agregar-carrito,
 * 21/9/2026): color propio y opcional para el boton "Agregar al carrito" de tienda-spa.
 * NULL significa "todavia hereda el color secundario en vivo" -no una copia congelada-,
 * asi que ademas de persistir un hex se verifica que mandarlo vacio lo deja en NULL.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria.
 */
class OnlineConfigurationAddToCartButtonColorTest extends TestCase
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
    public function actualizar_la_configuracion_online_persiste_add_to_cart_button_color()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Se crea la fila con lo minimo (user_id): mismo patron que HelperController/UserSeeder,
        // el resto de las columnas cae a su default de la migracion.
        $config = OnlineConfiguration::create(['user_id' => 500]);

        // El PUT manda el modelo entero (asi lo hace siempre el formulario ABM generico de la
        // SPA): update() reasigna cada columna con lo que venga en el request sin fallback para
        // la mayoria de los campos, asi que un payload parcial pisa esas columnas con null y
        // rompe la constraint. Se parte de los valores actuales y se agrega/pisa solo la clave
        // nueva de este prompt.
        $payload = array_merge($config->fresh()->toArray(), [
            'add_to_cart_button_color' => '#1a2b3c',
        ]);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        $this->assertEquals('#1a2b3c', $config->fresh()->add_to_cart_button_color);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function mandar_add_to_cart_button_color_vacio_lo_deja_en_null()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $config = OnlineConfiguration::create([
            'user_id' => 500,
            'add_to_cart_button_color' => '#1a2b3c',
        ]);

        $payload = array_merge($config->fresh()->toArray(), [
            'add_to_cart_button_color' => null,
        ]);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);
        $this->assertNull($config->fresh()->add_to_cart_button_color);
    }
}
