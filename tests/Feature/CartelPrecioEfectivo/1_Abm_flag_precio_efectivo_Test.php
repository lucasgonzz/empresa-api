<?php

namespace Tests\Feature\CartelPrecioEfectivo;

use App\Models\ArticlePdf;
use Illuminate\Support\Facades\DB;

/**
 * CA1 — ABM `api/article-pdf` con el flag `mostrar_precio_efectivo` (misión soporte-t173-m44).
 *
 * @group cartel_precio_efectivo
 */
class Abm_flag_precio_efectivo_Test extends CartelPrecioEfectivoTestCase
{
    /** @test */
    public function test_crear_con_flag_lo_guarda()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/article-pdf', array(
            'nombre' => 'zz Con efectivo',
            'mostrar_precio_efectivo' => true,
        ));

        $respuesta->assertStatus(201);
        $this->assertTrue((bool) ArticlePdf::find($respuesta->json('model.id'))->mostrar_precio_efectivo);
    }

    /** @test */
    public function test_crear_sin_flag_queda_apagado()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/article-pdf', array('nombre' => 'zz Sin efectivo'));

        $respuesta->assertStatus(201);
        $this->assertFalse((bool) ArticlePdf::find($respuesta->json('model.id'))->mostrar_precio_efectivo);
    }

    /** @test */
    public function test_update_false_lo_apaga()
    {
        $dueno = $this->crear_dueno();
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));
        $this->actingAs($dueno, 'web');

        $respuesta = $this->putJson('api/article-pdf/'.$plantilla->id, array(
            'nombre' => 'zz Cartel',
            'mostrar_precio_efectivo' => false,
        ));

        $respuesta->assertStatus(200);
        $this->assertFalse((bool) ArticlePdf::find($plantilla->id)->mostrar_precio_efectivo);
    }

    /** @test */
    public function test_update_sin_el_campo_no_lo_toca()
    {
        $dueno = $this->crear_dueno();
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));
        $this->actingAs($dueno, 'web');

        /* Un SPA viejo en caché no manda el campo: no puede apagar un flag prendido. */
        $respuesta = $this->putJson('api/article-pdf/'.$plantilla->id, array('nombre' => 'zz Renombrada'));

        $respuesta->assertStatus(200);
        $this->assertTrue((bool) ArticlePdf::find($plantilla->id)->mostrar_precio_efectivo);
    }

    /** @test */
    public function test_plantilla_vieja_queda_apagada()
    {
        $dueno = $this->crear_dueno();

        /* Plantilla creada "antes de la migración": insert sin mencionar la columna. */
        $id = DB::table('article_pdfs')->insertGetId(array(
            'user_id' => $dueno->id,
            'nombre'  => 'zz Vieja',
        ));

        $this->assertFalse((bool) ArticlePdf::find($id)->mostrar_precio_efectivo);
    }
}
