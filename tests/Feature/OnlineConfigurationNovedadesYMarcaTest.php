<?php

namespace Tests\Feature;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test de mostrar_novedades_en_home y mostrar_marca_en_nav (mision cruzada
 * tienda-novedades-y-marca-configurables, 24/9/2026): dos toggles nuevos en Configuracion Online
 * que deciden si la tienda muestra la seccion "Novedades" del home y el item "Marca" de la barra
 * de navegacion. Los dos arrancan en true (columna boolean default 1, ver la migracion) porque hoy
 * las dos cosas siempre se ven: ningun comercio existente tiene que cambiar al actualizarse.
 *
 * Los tests de "no mandar la clave" cubren el mismo motivo que ya vale para
 * mostrar_stock_disponible: el controller usa $request->boolean() con fallback al valor actual del
 * modelo, porque una pantalla vieja que todavia no conoce estas claves no tiene que poder
 * apagarlas sola.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria.
 */
class OnlineConfigurationNovedadesYMarcaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests. Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Autentica al usuario de testing o saltea el test si no esta sembrado.
     *
     * @return void
     */
    protected function autenticar_o_saltear()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function las_dos_columnas_nacen_prendidas()
    {
        $this->autenticar_o_saltear();

        // Solo user_id: el resto cae al default de la migracion.
        $config = OnlineConfiguration::create(['user_id' => 500]);

        $this->assertTrue($config->fresh()->mostrar_novedades_en_home);
        $this->assertTrue($config->fresh()->mostrar_marca_en_nav);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function actualizar_la_configuracion_online_persiste_las_dos_claves_en_false_y_en_true()
    {
        $this->autenticar_o_saltear();

        $config = OnlineConfiguration::create(['user_id' => 500]);

        // El PUT manda el modelo entero (asi lo hace siempre el formulario ABM generico de la
        // SPA): se parte de los valores actuales y se pisan solo las claves de esta mision.
        $payload = array_merge($config->fresh()->toArray(), [
            'mostrar_novedades_en_home' => false,
            'mostrar_marca_en_nav' => false,
        ]);
        $this->putJson('api/online-configuration/' . $config->id, $payload)->assertStatus(200);

        $this->assertEquals(0, (int) $config->fresh()->mostrar_novedades_en_home);
        $this->assertEquals(0, (int) $config->fresh()->mostrar_marca_en_nav);

        // Cada flag es independiente: se prende uno y el otro sigue apagado.
        $payload = array_merge($config->fresh()->toArray(), [
            'mostrar_novedades_en_home' => true,
            'mostrar_marca_en_nav' => false,
        ]);
        $this->putJson('api/online-configuration/' . $config->id, $payload)->assertStatus(200);

        $this->assertEquals(1, (int) $config->fresh()->mostrar_novedades_en_home);
        $this->assertEquals(0, (int) $config->fresh()->mostrar_marca_en_nav);

        $payload = array_merge($config->fresh()->toArray(), [
            'mostrar_novedades_en_home' => true,
            'mostrar_marca_en_nav' => true,
        ]);
        $this->putJson('api/online-configuration/' . $config->id, $payload)->assertStatus(200);

        $this->assertEquals(1, (int) $config->fresh()->mostrar_novedades_en_home);
        $this->assertEquals(1, (int) $config->fresh()->mostrar_marca_en_nav);
    }

    /**
     * @group configuracion-online
     * @test
     */
    public function no_mandar_las_claves_mantiene_el_valor_ya_guardado()
    {
        $this->autenticar_o_saltear();

        // Una tienda con novedades apagadas y marca prendida.
        $config = OnlineConfiguration::create([
            'user_id' => 500,
            'mostrar_novedades_en_home' => false,
            'mostrar_marca_en_nav' => true,
        ]);

        // Simula una pantalla vieja que todavia no conoce estas claves: el payload no las incluye.
        $payload = $config->fresh()->toArray();
        unset($payload['mostrar_novedades_en_home'], $payload['mostrar_marca_en_nav']);

        $this->putJson('api/online-configuration/' . $config->id, $payload)->assertStatus(200);

        // Si el controller defaulteara a false, apagaria la marca; si defaulteara a true, prenderia
        // las novedades que el comercio habia apagado a proposito.
        $this->assertEquals(0, (int) $config->fresh()->mostrar_novedades_en_home);
        $this->assertEquals(1, (int) $config->fresh()->mostrar_marca_en_nav);
    }
}
