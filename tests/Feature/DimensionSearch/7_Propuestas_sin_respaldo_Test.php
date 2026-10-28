<?php

namespace Tests\Feature\DimensionSearch;

use App\Models\DimensionSearchItem;
use App\Services\DimensionSearch\DimensionValueValidator;

/**
 * Lo que la IA propone y el validador no puede respaldar con la página (misión medidas-sin-datos, 28/10/2026).
 *
 * Antes, si la IA proponía un valor y la cita no se podía comprobar (no está en la página, no coincide con el
 * valor, la fuente es desconocida, no hay cita), el valor se perdía y el ítem quedaba `sin_datos`. Ahora, si NINGÚN
 * campo valida, el ítem queda `a_revisar` / `valor_sin_respaldo` con la propuesta, la cita tal cual la dio la IA y el
 * motivo del rechazo. Nunca se escribe solo en el artículo: aprobar sigue siendo una decisión de una persona.
 *
 * Todo con Serper, páginas e IA falsos: nada sale a internet.
 */
class Propuestas_sin_respaldo_Test extends DimensionSearchTestCase
{
    const URL_A = 'https://catalogo.test/cuchilla-rotary';
    const URL_B = 'https://tienda.test/producto-b';

    /**
     * Un artículo con código de barras válido, una página que lo trae y una IA que contesta lo que se le pase.
     *
     * @param  array  $datos  peso, ancho, alto, largo => dato().
     * @param  string $match
     * @param  string $cuerpo Lo que dice la página (además del código).
     * @return array  [articulo, gtin]
     */
    protected function buscar_con_propuesta(array $datos, $match = 'exacto', $cuerpo = 'Cuchilla rotativa para bordeadora.')
    {
        $gtin     = $this->con_verificador('779123456810');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A, 'Cuchilla rotary')]],
            [self::URL_A => $this->pagina('Cuchilla rotary', 'Código '.$gtin.'. '.$cuerpo)],
            $this->ficha($match, $datos)
        );

        list($item) = $this->buscar($articulo);

        return [$item, $articulo];
    }

    /**
     * El caso del plan: la IA propuso valores, ninguno se pudo comprobar → a revisar con la propuesta.
     *
     * @group dimension-search
     * @test
     */
    public function una_propuesta_que_no_se_puede_comprobar_queda_a_revisar_con_la_cita_y_el_motivo_y_no_se_escribe()
    {
        list($item, $articulo) = $this->buscar_con_propuesta([
            'peso'  => $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A),                 // La cita no está en la página.
            'ancho' => $this->dato(70, 'cm', 'Código '.$this->con_verificador('779123456810'), self::URL_A), // La cita está pero no tiene el valor.
        ]);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertStringContainsString('no pudo comprobarlos', $item->motivo_detalle);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);

        // La propuesta lleva los valores a kg / cm con las reglas de unidad del validador.
        $this->assertEquals(0.9, (float) $item->peso_kg);
        $this->assertEquals(70, (float) $item->ancho_cm);
        $this->assertNull($item->alto_cm);
        $this->assertNull($item->profundidad_cm);

        // La evidencia marca que NADIE comprobó el valor, con la cita tal cual y el motivo del rechazo.
        $peso = $item->evidencia['peso'];
        $this->assertTrue($peso['sin_respaldo']);
        $this->assertSame('cita_no_esta_en_la_pagina', $peso['motivo_rechazo']);
        $this->assertSame('Peso neto: 900 g', $peso['cita']);
        $this->assertSame(self::URL_A, $peso['url']);
        $this->assertEquals(0.9, $peso['valor']);
        $this->assertSame('kg', $peso['unidad']);
        $this->assertEquals(900, $peso['valor_original']);
        $this->assertSame('g', $peso['unidad_original']);
        $this->assertTrue($peso['convertido']);
        $this->assertSame('producto', $peso['alcance']);

        $this->assertSame('cita_no_coincide_con_el_valor', $item->evidencia['ancho']['motivo_rechazo']);
        $this->assertFalse($item->evidencia['ancho']['convertido']);
        $this->assertArrayNotHasKey('alto', $item->evidencia);

        // Las fuentes son las páginas citadas.
        $this->assertSame(self::URL_A, $item->fuentes[0]['url']);

        // El diagnóstico sigue guardando por qué se rechazó cada campo (compatible con lo de siempre).
        $this->assertSame('cita_no_esta_en_la_pagina', $item->diagnostico[0]['ia']['campos']['peso']);
        $this->assertSame('cita_no_coincide_con_el_valor', $item->diagnostico[0]['ia']['campos']['ancho']);

        // 🔴 Nunca se escribe solo.
        $articulo = $articulo->fresh();
        $this->assertTrue(is_null($articulo->peso) || (float) $articulo->peso === 0.0);
        $this->assertTrue(is_null($articulo->ancho) || (float) $articulo->ancho === 0.0);
        $this->assertNull($item->aplicado);
    }

    /**
     * `fuente_desconocida` y `sin_cita` también conservan la propuesta.
     *
     * @group dimension-search
     * @test
     */
    public function la_fuente_desconocida_y_la_falta_de_cita_tambien_conservan_la_propuesta()
    {
        list($item) = $this->buscar_con_propuesta([
            'peso'  => $this->dato(1.5, 'kg', 'Peso: 1,5 kg', 'https://otra.test/inventada'),
            'alto'  => $this->dato(11, 'mm', '', self::URL_A),
        ]);

        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertSame('fuente_desconocida', $item->evidencia['peso']['motivo_rechazo']);
        $this->assertSame('https://otra.test/inventada', $item->evidencia['peso']['url']);
        $this->assertEquals(1.5, (float) $item->peso_kg);

        $this->assertSame('sin_cita', $item->evidencia['alto']['motivo_rechazo']);
        $this->assertEquals(1.1, (float) $item->alto_cm);
        $this->assertTrue($item->evidencia['alto']['convertido']);
    }

    /**
     * `valor_invalido`, `unidad_invalida` y `fuera_de_rango` NO se guardan: no hay un valor que mostrar.
     *
     * @group dimension-search
     * @test
     */
    public function un_valor_invalido_una_unidad_invalida_o_fuera_de_rango_no_se_guardan()
    {
        list($item) = $this->buscar_con_propuesta([
            'peso'  => $this->dato('mucho', 'kg', 'Peso: mucho kg', self::URL_A),      // valor_invalido
            'alto'  => $this->dato(11, 'kg', 'Alto: 11 kg', self::URL_A),               // unidad_invalida (un peso en un alto)
            'ancho' => $this->dato(5000, 'cm', 'Ancho: 5000 cm', self::URL_A),         // fuera_de_rango (> 1000 cm)
            'largo' => $this->dato(0, 'cm', 'Largo: 0 cm', self::URL_A),               // valor_invalido (cero)
        ]);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_medidas_en_la_pagina', $item->motivo, 'La IA reconoció el producto y no hay nada que mostrar.');
        $this->assertNull($item->peso_kg);
        $this->assertNull($item->alto_cm);
        $this->assertNull($item->ancho_cm);
        $this->assertNull($item->profundidad_cm);

        $campos = $item->diagnostico[0]['ia']['campos'];
        $this->assertSame('valor_invalido', $campos['peso']);
        $this->assertSame('unidad_invalida', $campos['alto']);
    }

    /**
     * El normalizador en sí: mismas reglas de unidad que el validador y null cuando no hay propuesta usable.
     *
     * @group dimension-search
     * @test
     */
    public function normalizar_propuesta_lleva_a_kg_y_cm_y_devuelve_null_cuando_no_sirve()
    {
        $v = new DimensionValueValidator();

        $peso = $v->normalizar_propuesta('peso', $this->dato(820, 'g', 'Peso: 820 g', self::URL_A, 'envio'));
        $this->assertEquals(0.82, $peso['valor']);
        $this->assertSame('kg', $peso['unidad']);
        $this->assertSame('g', $peso['unidad_original']);
        $this->assertTrue($peso['convertido']);
        $this->assertSame('envio', $peso['alcance']);
        $this->assertSame('Peso: 820 g', $peso['cita']);

        $medida = $v->normalizar_propuesta('ancho', $this->dato('7,5', 'cm', '', self::URL_A));
        $this->assertEquals(7.5, $medida['valor']);
        $this->assertFalse($medida['convertido']);
        $this->assertSame('', $medida['cita']);

        $pulgadas = $v->normalizar_propuesta('alto', $this->dato(2, 'inch', 'Alto: 2"', self::URL_A));
        $this->assertEquals(5.08, $pulgadas['valor']);

        $this->assertNull($v->normalizar_propuesta('peso', null));
        $this->assertNull($v->normalizar_propuesta('peso', []));
        $this->assertNull($v->normalizar_propuesta('peso', $this->dato(-3, 'kg', 'x', self::URL_A)), 'valor_invalido');
        $this->assertNull($v->normalizar_propuesta('peso', $this->dato(3, 'cm', 'x', self::URL_A)), 'unidad_invalida: un peso en cm');
        $this->assertNull($v->normalizar_propuesta('alto', $this->dato(3, 'kg', 'x', self::URL_A)), 'unidad_invalida: una medida en kg');
        $this->assertNull($v->normalizar_propuesta('alto', $this->dato(3, 'parsecs', 'x', self::URL_A)), 'unidad desconocida');
        $this->assertNull($v->normalizar_propuesta('peso', $this->dato(2500, 'kg', 'x', self::URL_A)), 'fuera_de_rango');
        $this->assertNull($v->normalizar_propuesta('largo_inexistente', $this->dato(3, 'cm', 'x', self::URL_A)));

        $this->assertTrue($v->es_motivo_de_propuesta_sin_respaldo('cita_no_esta_en_la_pagina'));
        $this->assertTrue($v->es_motivo_de_propuesta_sin_respaldo('sin_cita'));
        $this->assertFalse($v->es_motivo_de_propuesta_sin_respaldo('fuera_de_rango'));
        $this->assertFalse($v->es_motivo_de_propuesta_sin_respaldo('no_informado'));
    }

    /**
     * Si la IA dice `otro_producto`, no hay propuesta que conservar.
     *
     * @group dimension-search
     * @test
     */
    public function si_la_ia_dice_otro_producto_no_se_conserva_ninguna_propuesta()
    {
        list($item) = $this->buscar_con_propuesta([
            'peso' => $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A),
        ], 'otro_producto');

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertNull($item->peso_kg);
        $this->assertNull($item->evidencia);
    }

    /**
     * Con al menos un campo válido todo sigue como antes: parciales, sin mezclar las propuestas sin respaldo.
     *
     * @group dimension-search
     * @test
     */
    public function con_campos_validos_sigue_igual_que_antes_y_las_propuestas_sin_respaldo_no_se_mezclan()
    {
        list($item, $articulo) = $this->buscar_con_propuesta([
            'peso'  => $this->dato(820, 'g', 'Peso: 820 g', self::URL_A),               // Válido.
            'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', self::URL_A),              // La cita no está en la página.
        ], 'exacto', 'Peso: 820 g.');

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertEquals(0.82, (float) $item->peso_kg);
        $this->assertNull($item->ancho_cm, 'La propuesta sin respaldo no se mezcla con los campos válidos.');
        $this->assertArrayNotHasKey('ancho', $item->evidencia);
        $this->assertArrayNotHasKey('sin_respaldo', $item->evidencia['peso']);
        $this->assertSame('cita_no_esta_en_la_pagina', $item->diagnostico[0]['ia']['campos']['ancho']);
    }

    /**
     * La primera propuesta por campo gana: una de un plan posterior no pisa a la anterior, pero sí completa los
     * campos que faltaban.
     *
     * @group dimension-search
     * @test
     */
    public function una_propuesta_de_un_plan_posterior_no_pisa_a_la_anterior_pero_completa_los_campos_que_faltan()
    {
        $gtin     = $this->con_verificador('779123456827');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            [
                '"'.$gtin.'"'                       => [$this->resultado(self::URL_A, 'Cuchilla '.$gtin)],
                '"VR-CU-0402" CUCHILLA ROTARY' => [$this->resultado(self::URL_B, 'Cuchilla VR-CU-0402')],
            ],
            [
                self::URL_A => $this->pagina('Cuchilla', 'Código '.$gtin.'. Cuchilla rotativa.'),
                self::URL_B => $this->pagina('Cuchilla', 'Código VR-CU-0402. Cuchilla rotativa.'),
            ],
            function ($entrada, $n) {
                if ($n === 1) {
                    return $this->ficha('exacto', ['peso' => $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A)]);
                }

                return $this->ficha('exacto', [
                    'peso' => $this->dato(950, 'g', 'Peso neto: 950 g', self::URL_B),
                    'alto' => $this->dato(11, 'mm', 'Alto: 11 mm', self::URL_B),
                ]);
            }
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertEquals(0.9, (float) $item->peso_kg, 'Gana la primera propuesta del campo.');
        $this->assertSame(self::URL_A, $item->evidencia['peso']['url']);
        $this->assertEquals(1.1, (float) $item->alto_cm, 'Lo que faltaba lo completa el plan posterior.');
        $this->assertSame(self::URL_B, $item->evidencia['alto']['url']);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado, 'El criterio es el del primer plan.');
        $this->assertCount(2, $item->fuentes);
    }

    /**
     * El flujo de aprobar sigue andando sobre un ítem sin respaldo, incluso con valores editados.
     *
     * @group dimension-search
     * @test
     */
    public function aprobar_con_valores_editados_funciona_sobre_un_item_sin_respaldo_y_recien_ahi_se_escribe()
    {
        list($item, $articulo) = $this->buscar_con_propuesta([
            'peso'  => $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A),
            'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', self::URL_A),
        ]);

        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertTrue(is_null($articulo->fresh()->peso) || (float) $articulo->fresh()->peso === 0.0, 'Antes de aprobar no hay nada escrito.');

        // Se corrige el peso y se descarta el ancho.
        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar', ['valores' => ['peso' => 0.85, 'ancho' => null]]);

        $r->assertStatus(200);
        $this->assertSame('aprobada', $r->json('status'));

        $articulo = $articulo->fresh();
        $this->assertEquals(0.85, (float) $articulo->peso);
        $this->assertTrue(is_null($articulo->ancho) || (float) $articulo->ancho === 0.0, 'El campo excluido no se escribió.');

        $evidencia = $item->fresh()->evidencia;
        $this->assertTrue($evidencia['peso']['editado']);
        $this->assertArrayNotHasKey('ancho', $evidencia);
    }

    /**
     * Aprobar tal cual escribe la propuesta (decisión de una persona).
     *
     * @group dimension-search
     * @test
     */
    public function aprobar_sin_editar_escribe_la_propuesta_sin_respaldo()
    {
        list($item, $articulo) = $this->buscar_con_propuesta([
            'peso' => $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A),
        ]);

        $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar')->assertStatus(200);

        $this->assertEquals(0.9, (float) $articulo->fresh()->peso);
    }

    /**
     * El payload del ítem: cada entrada de `campos[]` trae `sin_respaldo` y `motivo_rechazo`, y el diagnóstico de
     * páginas viaja también en los `valor_sin_respaldo` (la persona que revisa ve de qué páginas salió).
     *
     * @group dimension-search
     * @test
     */
    public function el_payload_del_item_trae_sin_respaldo_y_motivo_rechazo_en_cada_campo_y_el_diagnostico()
    {
        list($item) = $this->buscar_con_propuesta([
            'peso' => $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A),
        ]);

        $payload = $this->getJson('api/dimension-search-runs/'.$item->run_id.'/items?solapa=a_revisar')->json('data.0');

        $this->assertSame('valor_sin_respaldo', $payload['motivo']);

        $campos = array_column($payload['campos'], null, 'clave');
        $this->assertTrue($campos['peso']['sin_respaldo']);
        $this->assertSame('cita_no_esta_en_la_pagina', $campos['peso']['motivo_rechazo']);
        $this->assertSame('Peso neto: 900 g', $campos['peso']['cita']);
        $this->assertSame(self::URL_A, $campos['peso']['url']);

        $this->assertNotEmpty($payload['diagnostico'], 'El diagnóstico viaja en los valor_sin_respaldo.');
        $this->assertSame(self::URL_A, $payload['diagnostico'][0]['paginas'][0]['url']);
        $this->assertFalse($payload['diagnostico'][0]['paginas'][0]['via_scrape']);

        // Un «a revisar» común: sin_respaldo en false, sin motivo y sin diagnóstico (como siempre).
        $articulo = $this->nuevo_articulo('OTRO');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $this->item_en($run, $articulo, 'a_revisar', ['diagnostico' => [['criterio' => 'nombre', 'usado' => true]]]);

        $comun = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=a_revisar')->json('data.0');

        foreach ($comun['campos'] as $campo) {
            $this->assertFalse($campo['sin_respaldo']);
            $this->assertNull($campo['motivo_rechazo']);
        }

        $this->assertSame([], $comun['diagnostico']);
    }
}
