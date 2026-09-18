<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de mostrar_catalogo_categorias_home (mision cruzada catalogo-categorias-home,
 * 18/9/2026): toggle nuevo en Configuracion Online que activa, en la tienda, la tarjeta de
 * categorias debajo del banner del Inicio (logo + nombre + descripcion, ver
 * CategoryDescripcionTest). Distinto de mostrar_catalogo, que controla el link "Catalogo" del
 * navbar y ya tenia su propia columna.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria.
 */
class OnlineConfigurationMostrarCatalogoCategoriasHomeTest extends TestCase
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
    public function actualizar_la_configuracion_online_persiste_mostrar_catalogo_categorias_home()
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
            'mostrar_catalogo_categorias_home' => true,
        ]);

        $response = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $response->assertStatus(200);

        $this->assertEquals(1, (int) $config->fresh()->mostrar_catalogo_categorias_home);
    }
}
