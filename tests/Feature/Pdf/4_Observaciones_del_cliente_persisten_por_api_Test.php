<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\PdfColumnProfileController;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El commit 1e5e145d (8/9/2026, informe observaciones-cliente-opcional-pdf) agrego el flag
 * show_client_description a la migracion, al cast del modelo y al render de NewSalePdf (cubierto
 * por 3_Observaciones_del_cliente_opcionales_Test.php), pero nunca lo sumo a
 * PdfColumnProfileController::store()/update(): ni al create(), ni al $request->only() del
 * update, ni a las reglas de validacion. El checkbox "Mostrar observaciones del cliente" del
 * formulario de Diseños de PDF viaja en el JSON del guardado pero el controller lo descartaba en
 * silencio -- nunca persistia via la API real, que es el unico camino que usa la SPA (el test
 * anterior probaba la persistencia con PdfColumnProfile::create() directo, sin pasar por acá).
 * Mismo patron de bug que show_subtotal_in_footer y que use_current_date-en-el-store (esos dos
 * siguen sin arreglar, fuera de alcance de esta mision: ver INFORME).
 *
 * @group pdf-observaciones-cliente
 */
class Observaciones_del_cliente_persisten_por_api_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta suite (mismo patron que Preferencias).
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Perfil de venta minimo, creado directo por Eloquent (no via store(): ver nota en
     * el_update_persiste_el_cambio_de_show_client_description sobre por que el update es el
     * camino real que se prueba con HTTP).
     *
     * @param int $owner_id
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil(int $owner_id, array $overrides = [])
    {
        return PdfColumnProfile::create(array_merge([
            'user_id' => $owner_id,
            'model_name' => 'sale',
            'name' => 'zz Perfil de test observaciones API',
            'paper_width_mm' => 210,
            'printable_width_mm' => 200,
            /**
             * NOT NULL sin default en la tabla (columna JSON, ver migracion original): store()
             * tampoco la setea, por eso acá se la pasa a mano, igual que hace el test hermano
             * (3_Observaciones_del_cliente_opcionales_Test::crear_perfil()).
             */
            'columns' => [],
        ], $overrides));
    }

    /** @test */
    public function el_update_persiste_el_cambio_de_show_client_description()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['show_client_description' => true]);

        $response = $this->putJson(
            'api/pdf-column-profiles/'.$perfil->id,
            ['show_client_description' => false]
        );

        $response->assertStatus(200);

        $this->assertFalse(
            (bool) $perfil->fresh()->show_client_description,
            'El update tiene que persistir show_client_description; si no esta en el $request->only() '
                .'de update(), el PUT devuelve 200 pero el valor queda intacto en la base.'
        );

        // Vuelve a true para confirmar que el camino anda en los dos sentidos, no solo apagando.
        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['show_client_description' => true])
            ->assertStatus(200);
        $this->assertTrue((bool) $perfil->fresh()->show_client_description);
    }

    /**
     * $request->only([...]) es "sometimes": un update que no menciona show_client_description
     * no lo tiene que tocar. Si algun dia se lo reescribe como "siempre false si no viene",
     * cualquier edicion de OTRO campo (por ejemplo el nombre) apagaria las observaciones del
     * cliente sin que nadie lo haya pedido.
     *
     * @test
     */
    public function un_update_que_no_menciona_el_flag_no_lo_toca()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['show_client_description' => false]);

        $response = $this->putJson(
            'api/pdf-column-profiles/'.$perfil->id,
            ['name' => 'zz Perfil renombrado']
        );

        $response->assertStatus(200);
        $this->assertFalse(
            (bool) $perfil->fresh()->show_client_description,
            'Un update parcial (sin show_client_description en el body) no puede cambiar su valor.'
        );
    }

    /**
     * store() no se puede ejercitar via HTTP hoy: PdfColumnProfile::create() dentro de store()
     * nunca setea `columns` (json NOT NULL sin default, ver migracion 2026_03_25_101000) y
     * cualquier POST a /pdf-column-profiles revienta con "Field 'columns' doesn't have a
     * default value" (`'strict' => true` fijo en config/database.php, sin excepcion por
     * entorno) -- confirmado corriendo el POST real contra empresa_testing_s9 el 16/9/2026, un
     * bug previo y ajeno a este cambio (ver INFORME, no se arregla acá). Por eso el store()
     * se verifica leyendo el codigo fuente, mismo criterio que ya usa el test hermano para
     * NewSalePdf (que tampoco se puede instanciar desde PHPUnit).
     *
     * @test
     */
    public function el_store_declara_show_client_description_con_default_true()
    {
        $codigo = file_get_contents(app_path('Http/Controllers/PdfColumnProfileController.php'));

        $this->assertStringContainsString(
            "'show_client_description' => (bool) \$request->input('show_client_description', true),",
            $codigo,
            'store() tiene que persistir show_client_description con default true (compatibilidad '
                .'con perfiles existentes), igual que show_total_in_footer.'
        );
    }

    /** @test */
    public function el_update_declara_show_client_description_en_su_whitelist()
    {
        $codigo = file_get_contents(app_path('Http/Controllers/PdfColumnProfileController.php'));

        // Bareword sin "=>": distingue el item del $request->only([...]) de la regla de
        // validacion, que es 'show_client_description' => [...] (con flecha).
        $this->assertStringContainsString(
            "'show_client_description',",
            $codigo,
            'update() tiene que sumar show_client_description a su $request->only([...]); si no '.
                'esta, el PUT devuelve 200 pero descarta el campo en silencio.'
        );
    }

    /** @test */
    public function las_reglas_de_validacion_declaran_show_client_description_como_boolean()
    {
        $controller = new PdfColumnProfileController();

        $store_rules = $this->invocar_reglas($controller, 'store_validation_rules', [new Request()]);
        $this->assertArrayHasKey('show_client_description', $store_rules);
        $this->assertEquals(['sometimes', 'boolean'], $store_rules['show_client_description']);

        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);
        $update_rules = $this->invocar_reglas($controller, 'update_validation_rules', [new Request(), $perfil]);
        $this->assertArrayHasKey('show_client_description', $update_rules);
        $this->assertEquals(['sometimes', 'boolean'], $update_rules['show_client_description']);
    }

    /**
     * Invoca un metodo protected del controller por reflection, sin necesidad de exponerlo.
     *
     * @param object $objeto
     * @param string $metodo
     * @param array $argumentos
     * @return mixed
     */
    protected function invocar_reglas($objeto, string $metodo, array $argumentos)
    {
        $reflexion = new ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }
}
