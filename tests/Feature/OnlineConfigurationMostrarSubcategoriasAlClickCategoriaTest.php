<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de mostrar_subcategorias_al_click_categoria (mision categoria-sidebar-desplegable,
 * 22/9/2026): toggle nuevo en Configuracion Online que hace que, en el sidebar de la tienda,
 * tocar el NOMBRE de una categoria despliegue sus subcategorias (igual que tocar la flecha) en
 * vez de navegar directo a todos sus articulos. El consumo real de esta config vive en
 * tienda-spa, en otro slot; esta mision solo expone el lado de empresa.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria.
 */
class OnlineConfigurationMostrarSubcategoriasAlClickCategoriaTest extends TestCase
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
    public function actualizar_la_configuracion_online_persiste_mostrar_subcategorias_al_click_categoria()
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
        // la mayoria de los campos (ej. mail_enabled, boolean NOT NULL), asi que un payload
        // parcial pisa esas columnas con null y rompe la constraint. Se parte de los valores
        // actuales y se agrega/pisa solo la clave nueva de este prompt.
        $payload = array_merge($config->fresh()->toArray(), [
            'mostrar_subcategorias_al_click_categoria' => true,
        ]);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);

        $this->assertEquals(1, (int) $config->fresh()->mostrar_subcategorias_al_click_categoria);
    }
}
