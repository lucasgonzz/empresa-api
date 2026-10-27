<?php

namespace Tests\Feature\DimensionSearch;

use App\Models\AiTokenUsage;
use App\Models\DimensionSearchItem;
use App\Models\SerperUsageEvent;

/**
 * El motor de la búsqueda masiva de peso y medidas (misión medidas-masivas-catalogo, 27/10/2026): los tres
 * planes de búsqueda, la lectura de páginas, la validación de lo que dice la IA y cada rama de la
 * clasificación (decisiones D1 a D3 del plan).
 *
 * Todo con Serper, páginas e IA falsos: nada sale a internet.
 */
class Motor_de_busqueda_Test extends DimensionSearchTestCase
{
    const URL_A = 'https://catalogo.test/cuchilla-rotary';
    const URL_B = 'https://tienda.test/producto-b';

    /**
     * Los cuatro datos de una cuchilla, en mm y g, tal como están escritos en la página de prueba.
     *
     * @param  string $url
     * @return array  peso, ancho, alto, largo => dato()
     */
    protected function datos_completos($url)
    {
        return [
            'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
            'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
            'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
            'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
        ];
    }

    /**
     * El cuerpo de una página que trae los cuatro datos.
     *
     * @param  string $codigos  Lo que se escribe antes (códigos).
     * @return string
     */
    protected function cuerpo_completo($codigos = '')
    {
        return $codigos.' Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.';
    }

    /**
     * @group dimension-search
     * @test
     */
    public function con_el_codigo_en_la_pagina_y_los_cuatro_datos_exactos_se_asigna_sola_y_convierte_las_unidades()
    {
        $gtin = $this->con_verificador('779123456789');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20" AC 21MM', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A, 'Cuchilla Rotary')]],
            [self::URL_A => $this->pagina('Cuchilla Rotary 20"', $this->cuerpo_completo('Código de barras '.$gtin.'.'))],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('codigo_exacto', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);

        // Convertido por el validador: 820 g → 0,82 kg; 70 / 11 / 100 mm → 7 / 1,1 / 10 cm.
        $articulo = $articulo->fresh();
        $this->assertEquals(0.82, (float) $articulo->peso);
        $this->assertEquals(7, (float) $articulo->ancho);
        $this->assertEquals(1.1, (float) $articulo->alto);
        $this->assertEquals(10, (float) $articulo->profundidad);
        $this->assertEquals(1, (int) $articulo->needs_sync_with_tn, 'Marca el artículo para Tienda Nube como lo hace el formulario.');

        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], array_keys($item->aplicado));
        $this->assertEquals(0.82, (float) $item->peso_kg);
        $this->assertTrue($item->evidencia['ancho']['convertido']);
        $this->assertSame('mm', $item->evidencia['ancho']['unidad_original']);
        $this->assertSame('Ancho: 70 mm', $item->evidencia['ancho']['cita']);

        // Una sola búsqueda, la del GTIN, y una sola llamada a la IA.
        $this->assertSame(['"'.$gtin.'"'], $this->consultas_serper);
        $this->assertCount(1, $this->pedidos_ia);

        // El consumo queda registrado donde corresponde, y los contadores de la corrida.
        $this->assertSame(1, SerperUsageEvent::where('user_id', $this->owner->id)->where('process', 'medidas_busqueda')->count());
        $this->assertSame(1, AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'busqueda_medidas')->count());

        $run = $item->run;
        $this->assertSame(1, (int) $run->procesados);
        $this->assertSame(1, (int) $run->busquedas);
        $this->assertSame(1, (int) $run->validaciones_ia);
        $this->assertSame(1800, (int) $run->tokens_entrada);
        $this->assertSame(120, (int) $run->tokens_salida);

        $this->assertSame(1, $resultado['busquedas_intentadas']);
        $this->assertTrue($resultado['ia_respondio']);
        $this->assertFalse($resultado['ia_sin_respuesta']);
    }

    /**
     * El pedido a la IA: la regla de que los documentos son datos no confiables, el match y los cuatro datos.
     *
     * @group dimension-search
     * @test
     */
    public function le_pasa_a_la_ia_el_articulo_y_el_extracto_de_la_pagina_con_las_reglas_del_prompt()
    {
        $gtin = $this->con_verificador('779123456781');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20" AC 21MM', $gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        $this->buscar($articulo);

        $pedido = $this->pedidos_ia[0];

        $this->assertSame('deepseek-flash', $pedido['payload']['model']);
        $this->assertSame(['type' => 'disabled'], $pedido['payload']['thinking'], 'DeepSeek Flash va sin pensamiento.');
        $this->assertStringContainsString('datos no confiables', $pedido['payload']['system']);
        $this->assertStringContainsString('variante_distinta', $pedido['payload']['system']);
        $this->assertStringContainsString('No inventes', $pedido['payload']['system']);

        $this->assertSame('CUCHILLA ROTARY 20" AC 21MM', $pedido['entrada']['articulo']['name']);
        $this->assertSame($gtin, $pedido['entrada']['articulo']['codigo_barras']);
        $this->assertSame('VR-CU-0402', $pedido['entrada']['articulo']['codigo_proveedor']);
        $this->assertSame(self::URL_A, $pedido['entrada']['documents'][0]['url']);
        $this->assertStringContainsString('Peso: 820 g', $pedido['entrada']['documents'][0]['text']);
        $this->assertStringNotContainsString('Menú', $pedido['entrada']['documents'][0]['text'], 'El menú y el pie no se mandan.');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_modelo_que_dice_setenta_centimetros_con_una_cita_de_setenta_milimetros_no_vale()
    {
        $gtin = $this->con_verificador('779123456792');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $datos = $this->datos_completos(self::URL_A);
        $datos['ancho'] = $this->dato(70, 'cm', 'Ancho: 70 mm', self::URL_A);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $datos)
        );

        list($item) = $this->buscar($articulo);

        // Faltó un campo (el ancho no pasó): ya no son cuatro, va a revisar y no se escribe nada.
        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertStringContainsString('ancho', $item->motivo_detalle);
        $this->assertNull($item->ancho_cm);
        $this->assertEquals(0.82, (float) $item->peso_kg);

        $articulo = $articulo->fresh();
        $this->assertTrue(is_null($articulo->peso) || (float) $articulo->peso === 0.0, 'No se escribió nada en el artículo.');

        // El diagnóstico dice por qué se descartó el ancho.
        $this->assertSame('cita_no_coincide_con_el_valor', $item->diagnostico[0]['ia']['campos']['ancho']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_cita_que_no_esta_en_la_pagina_no_vale()
    {
        $gtin = $this->con_verificador('779123456800');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $datos = $this->datos_completos(self::URL_A);
        $datos['peso'] = $this->dato(900, 'g', 'Peso neto: 900 g', self::URL_A); // Inventada: la página dice 820 g.

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $datos)
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertNull($item->peso_kg);
        $this->assertSame('cita_no_esta_en_la_pagina', $item->diagnostico[0]['ia']['campos']['peso']);
    }

    /**
     * D2: medidas parciales.
     *
     * @group dimension-search
     * @test
     */
    public function con_medidas_parciales_va_a_revisar_y_no_escribe_nada()
    {
        $gtin = $this->con_verificador('779123456818');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $gtin.' Ancho: 5,5 cm. Largo total: 50.5 cm.')],
            $this->ficha('exacto', [
                'ancho' => $this->dato(5.5, 'cm', 'Ancho: 5,5 cm', self::URL_A),
                'largo' => $this->dato(50.5, 'cm', 'Largo total: 50.5 cm', self::URL_A),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertStringContainsString('faltan: peso, alto', $item->motivo_detalle);
        $this->assertEquals(5.5, (float) $item->ancho_cm);
        $this->assertEquals(50.5, (float) $item->profundidad_cm);
        $this->assertNull($item->peso_kg);
        $this->assertNull($item->aplicado);

        $articulo = $articulo->fresh();
        $this->assertTrue(is_null($articulo->ancho) || (float) $articulo->ancho === 0.0);
        $this->assertTrue(is_null($articulo->profundidad) || (float) $articulo->profundidad === 0.0);
    }

    /**
     * D2: la variante dudosa (el caso 35 cm / 40 cm de Lucas).
     *
     * @group dimension-search
     * @test
     */
    public function una_variante_distinta_va_a_revisar_con_la_nota_de_la_ia_aunque_traiga_los_cuatro_datos()
    {
        $gtin = $this->con_verificador('779123456826');
        $articulo = $this->nuevo_articulo('MOTOSIERRA 40CM', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Motosierra', $this->cuerpo_completo($gtin))],
            $this->ficha('variante_distinta', $this->datos_completos(self::URL_A), 'La página es de la versión con espada de 35 cm.')
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('variante_dudosa', $item->motivo);
        $this->assertSame('La página es de la versión con espada de 35 cm.', $item->nota_ia);
        $this->assertStringContainsString('espada de 35 cm', $item->motivo_detalle);
        $this->assertEquals(0.82, (float) $item->peso_kg, 'La propuesta queda cargada para que la persona decida.');
        $this->assertNull($item->aplicado);
        $this->assertTrue(is_null($articulo->fresh()->peso) || (float) $articulo->fresh()->peso === 0.0);
    }

    /**
     * D2: coincidencia solo por nombre.
     *
     * @group dimension-search
     * @test
     */
    public function una_coincidencia_por_nombre_va_a_revisar_aunque_la_ia_diga_exacto_y_traiga_los_cuatro_datos()
    {
        // Sin código de barras ni de proveedor: solo se puede buscar por nombre.
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20 PULGADAS AC 21MM CENTRO');

        $this->falsear(
            ['CUCHILLA ROTARY 20 PULGADAS AC 21MM ficha técnica peso medidas' => [$this->resultado(self::URL_B)]],
            [self::URL_B => $this->pagina('Cuchilla Rotary 20', $this->cuerpo_completo())],
            $this->ficha('exacto', $this->datos_completos(self::URL_B))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('coincidencia_por_nombre', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_NOMBRE, $item->criterio_usado);
        $this->assertNull($item->aplicado);
        $this->assertTrue(is_null($articulo->fresh()->ancho) || (float) $articulo->fresh()->ancho === 0.0);
    }

    /**
     * D2: coincidencia probable, aunque la página tenga el código.
     *
     * @group dimension-search
     * @test
     */
    public function una_coincidencia_probable_con_el_codigo_y_los_cuatro_datos_va_a_revisar()
    {
        $gtin = $this->con_verificador('779123456834');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('probable', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('coincidencia_probable', $item->motivo);
        $this->assertNull($item->aplicado);
    }

    /**
     * Sin `match` en la respuesta se lo trata como probable (revisión), nunca como exacto.
     *
     * @group dimension-search
     * @test
     */
    public function una_respuesta_sin_match_se_trata_como_probable()
    {
        $gtin = $this->con_verificador('779123456842');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $ficha = $this->ficha('exacto', $this->datos_completos(self::URL_A));
        unset($ficha['match']);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $ficha
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('coincidencia_probable', $item->motivo);
    }

    /**
     * El código de proveedor en la página también alcanza para asignar sola.
     *
     * @group dimension-search
     * @test
     */
    public function el_codigo_de_proveedor_en_la_pagina_alcanza_para_asignar_sola()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20 PULGADAS', null, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            ['"VR-CU-0402" CUCHILLA ROTARY 20 PULGADAS' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo('Código VR-CU-0402.'))],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertEquals(7, (float) $articulo->fresh()->ancho);
    }

    /**
     * Un plan de código solo cuenta las páginas que CONTIENEN el código.
     *
     * @group dimension-search
     * @test
     */
    public function en_un_plan_de_codigo_una_pagina_sin_el_codigo_no_se_le_pasa_a_la_ia()
    {
        $gtin = $this->con_verificador('779123456859');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_B, 'Otra cosa')]],
            [self::URL_B => $this->pagina('Otra cosa', $this->cuerpo_completo('Código 7790000000000.'))],
            $this->ficha('exacto', $this->datos_completos(self::URL_B))
        );

        list($item) = $this->buscar($articulo);

        // Ninguna página trajo el código: la IA ni se llamó y el diagnóstico lo dice. (Después probó el plan del nombre.)
        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertFalse($item->diagnostico[0]['paginas'][0]['leida']);
        $this->assertStringContainsString('no contiene el código', $item->diagnostico[0]['paginas'][0]['motivo']);
        $this->assertSame('sin_paginas', $item->diagnostico[0]['resultado']);
    }

    /**
     * El orden de los planes: barras, proveedor (con las primeras seis palabras) y nombre.
     *
     * @group dimension-search
     * @test
     */
    public function prueba_los_tres_planes_en_orden_y_para_en_tres_busquedas()
    {
        $gtin = $this->con_verificador('779123456867');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20 PULGADAS AC 21MM CENTRO REDONDO', $gtin, ['provider_code' => 'VR-CU-0402']);

        // Ninguna búsqueda devuelve nada.
        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame([
            '"'.$gtin.'"',
            '"VR-CU-0402" CUCHILLA ROTARY 20 PULGADAS AC 21MM',
            'CUCHILLA ROTARY 20 PULGADAS AC 21MM ficha técnica peso medidas',
        ], $this->consultas_serper);

        $this->assertSame(3, $resultado['busquedas_intentadas']);
        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_resultados', $item->motivo);
        $this->assertSame(3, (int) $item->run->busquedas);

        $criterios = array_column($item->diagnostico, 'criterio');
        $this->assertSame(['codigo_de_barras', 'codigo_proveedor', 'nombre'], $criterios);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function saltea_los_planes_que_no_aplican_y_los_repetidos()
    {
        // El código de proveedor es el mismo que el de barras: es una sola búsqueda por código.
        $gtin = $this->con_verificador('779123456875');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20 PULGADAS', $gtin, ['provider_code' => $gtin]);

        $this->falsear([], [], $this->ficha('otro_producto'));
        list($item) = $this->buscar($articulo);

        $this->assertSame(['"'.$gtin.'"', 'CUCHILLA ROTARY 20 PULGADAS ficha técnica peso medidas'], $this->consultas_serper);

        $salteado = null;

        foreach ($item->diagnostico as $entrada) {
            if ($entrada['criterio'] === 'codigo_proveedor') {
                $salteado = $entrada;
            }
        }

        $this->assertFalse($salteado['usado']);
        $this->assertStringContainsString('mismo código', $salteado['motivo']);

        // Un código interno de barras (no GTIN) y un código de proveedor que es una palabra: solo el nombre.
        $otro = $this->nuevo_articulo('TRANSPORTE DE MERCADERIA', '12345', ['provider_code' => 'TRANSPORTE']);

        $this->falsear([], [], $this->ficha('otro_producto'));
        list($item_otro) = $this->buscar($otro);

        $this->assertSame(['TRANSPORTE DE MERCADERIA ficha técnica peso medidas'], $this->consultas_serper);

        $usados = array_column(array_filter($item_otro->diagnostico, function ($e) {
            return $e['usado'];
        }), 'criterio');

        $this->assertSame(['nombre'], array_values($usados));
    }

    /**
     * Si el primer plan da datos, no se gastan más búsquedas.
     *
     * @group dimension-search
     * @test
     */
    public function con_datos_en_el_primer_plan_no_busca_mas()
    {
        $gtin = $this->con_verificador('779123456883');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        $this->buscar($articulo);

        $this->assertCount(1, $this->consultas_serper);
    }

    /**
     * Si el plan 1 es otro producto, sigue con el plan 2 y ahí encuentra.
     *
     * @group dimension-search
     * @test
     */
    public function si_la_ia_dice_otro_producto_sigue_con_el_plan_siguiente()
    {
        $gtin = $this->con_verificador('779123456891');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20 PULGADAS', $gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(
            [
                '"'.$gtin.'"'                              => [$this->resultado(self::URL_B, 'Otra cosa')],
                '"VR-CU-0402" CUCHILLA ROTARY 20 PULGADAS' => [$this->resultado(self::URL_A)],
            ],
            [
                self::URL_B => $this->pagina('Otra cosa', $this->cuerpo_completo($gtin)),
                self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo('VR-CU-0402')),
            ],
            function ($entrada) {
                $url = $entrada['documents'][0]['url'];

                return $url === self::URL_B
                    ? $this->ficha('otro_producto')
                    : $this->ficha('exacto', $this->datos_completos(self::URL_A));
            }
        );

        list($item) = $this->buscar($articulo);

        $this->assertCount(2, $this->consultas_serper);
        $this->assertCount(2, $this->pedidos_ia);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertSame('otro_producto', $item->diagnostico[0]['resultado']);
        $this->assertSame('datos', $item->diagnostico[1]['resultado']);
    }

    /**
     * Un servicio (sin cuerpo físico): la IA no encuentra nada y queda sin datos, sin lista de excepciones por nombre.
     *
     * @group dimension-search
     * @test
     */
    public function un_servicio_sin_cuerpo_fisico_termina_sin_datos_porque_ninguna_pagina_es_el_mismo_producto()
    {
        $articulo = $this->nuevo_articulo('TRANSPORTE DE MERCADERIA');

        $this->falsear(
            ['TRANSPORTE DE MERCADERIA ficha técnica peso medidas' => [$this->resultado(self::URL_B, 'Transporte de mercadería')]],
            [self::URL_B => $this->pagina('Transporte de mercadería', 'Servicio de transporte de carga a todo el país.')],
            $this->ficha('otro_producto')
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('no_coincide', $item->motivo);
        $this->assertNotEmpty($item->diagnostico, 'Se muestra qué se probó.');
    }

    /**
     * D3: la página es el producto pero no trae medidas.
     *
     * @group dimension-search
     * @test
     */
    public function si_la_pagina_es_el_producto_pero_no_trae_medidas_comprobables_queda_sin_datos()
    {
        $gtin = $this->con_verificador('779123456909');
        $articulo = $this->nuevo_articulo('RODAMIENTO 6202', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Rodamiento', $gtin.' Rodamiento rígido de bolas.')],
            $this->ficha('exacto', ['peso' => $this->dato(0.05, 'kg', 'Pesa 50 gramos', self::URL_A)])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_medidas_en_la_pagina', $item->motivo);
    }

    /**
     * D4: cualquier alcance sirve.
     *
     * @group dimension-search
     * @test
     */
    public function guarda_el_alcance_de_cada_dato_envio_o_producto()
    {
        $gtin = $this->con_verificador('779123456917');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $datos = $this->datos_completos(self::URL_A);
        $datos['peso'] = $this->dato(820, 'g', 'Peso: 820 g', self::URL_A, 'envio');

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $datos)
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame('envio', $item->evidencia['peso']['alcance']);
        $this->assertSame('producto', $item->evidencia['ancho']['alcance']);
    }

    /**
     * D5: nunca pisa lo ya cargado.
     *
     * @group dimension-search
     * @test
     */
    public function nunca_pisa_un_campo_que_ya_estaba_cargado()
    {
        $gtin = $this->con_verificador('779123456925');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin, ['peso' => 0.9]);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $articulo = $articulo->fresh();

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertEquals(0.9, (float) $articulo->peso, 'El peso que ya tenía no se toca.');
        $this->assertEquals(7, (float) $articulo->ancho);
        $this->assertEqualsCanonicalizing(['alto', 'ancho', 'profundidad'], array_keys($item->aplicado), 'Solo se registra lo que se escribió.');
        $this->assertStringContainsString('No se pisó lo ya cargado: el peso', $item->motivo_detalle);
    }

    /**
     * Un artículo con las cuatro medidas cargadas no se busca ni gasta nada.
     *
     * @group dimension-search
     * @test
     */
    public function un_articulo_que_ya_tiene_todo_cargado_no_gasta_busquedas()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', null, ['peso' => 1, 'alto' => 2, 'ancho' => 3, 'profundidad' => 4]);

        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('ya_tenia_medidas', $item->motivo);
        $this->assertSame([], $this->consultas_serper);
        $this->assertSame(0, $resultado['busquedas_intentadas']);
    }

    /**
     * Las páginas internas las frena la guarda SSRF.
     *
     * @group dimension-search
     * @test
     */
    public function la_guarda_ssrf_frena_las_paginas_de_la_red_interna_y_no_las_lee()
    {
        $gtin = $this->con_verificador('779123456933');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $interna = 'https://interno.test/admin';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($interna, 'Interna')]],
            [$interna => $this->pagina('Interna', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $this->datos_completos($interna))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame([], $this->paginas_pedidas, 'Nunca se pidió la página interna.');
        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertFalse($item->diagnostico[0]['paginas'][0]['leida']);
    }

    /**
     * Los PDF no se leen.
     *
     * @group dimension-search
     * @test
     */
    public function no_lee_pdf()
    {
        $gtin = $this->con_verificador('779123456941');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $pdf = 'https://catalogo.test/ficha.pdf';

        $this->falsear(['"'.$gtin.'"' => [$this->resultado($pdf)]], [$pdf => $this->pagina('Ficha', $gtin)], $this->ficha('otro_producto'));

        list($item) = $this->buscar($articulo);

        $this->assertSame([], $this->paginas_pedidas);
        $this->assertStringContainsString('PDF', $item->diagnostico[0]['paginas'][0]['motivo']);
    }

    /**
     * Máximo tres páginas leídas por búsqueda.
     *
     * @group dimension-search
     * @test
     */
    public function lee_como_mucho_tres_paginas_por_busqueda()
    {
        $gtin = $this->con_verificador('779123456958');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $urls = [];
        $paginas = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $letra) {
            $urls[] = 'https://sitio-'.$letra.'.test/p';
            $paginas['https://sitio-'.$letra.'.test/p'] = $this->pagina('Producto '.$letra, $gtin.' sin medidas');
        }

        $this->falsear(
            ['"'.$gtin.'"' => array_map(function ($u) {
                return $this->resultado($u);
            }, $urls)],
            $paginas,
            $this->ficha('otro_producto')
        );

        $this->buscar($articulo);

        $this->assertCount(3, $this->pedidos_ia[0]['entrada']['documents']);
    }

    /**
     * Sin respuesta de la IA el artículo no se da por buscado sin éxito.
     *
     * @group dimension-search
     * @test
     */
    public function si_la_ia_no_responde_el_articulo_queda_sin_procesar_y_el_resultado_lo_avisa()
    {
        $gtin = $this->con_verificador('779123456966');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            'caida'
        );

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_PROCESAR, $item->status);
        $this->assertSame('ia_sin_respuesta', $item->motivo);
        $this->assertTrue($resultado['ia_sin_respuesta']);
        $this->assertFalse($resultado['ia_respondio']);
        $this->assertNotSame('', $resultado['ia_error']);

        // Una respuesta que no es JSON es lo mismo.
        $otro = $this->nuevo_articulo('OTRA CUCHILLA', $gtin);
        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            'ilegible'
        );

        list($item_otro, $resultado_otro) = $this->buscar($otro);
        $this->assertSame('ia_sin_respuesta', $item_otro->motivo);
        $this->assertTrue($resultado_otro['ia_sin_respuesta']);
    }

    /**
     * Si el buscador falla en todo, no es «sin datos»: se puede reintentar.
     *
     * @group dimension-search
     * @test
     */
    public function si_el_buscador_falla_el_articulo_queda_sin_procesar_y_marca_error_de_proveedor()
    {
        $gtin = $this->con_verificador('779123456974');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20 PULGADAS', $gtin, ['provider_code' => 'VR-CU-0402']);

        $this->falsear(['error' => 'Not enough credits'], [], $this->ficha('otro_producto'));

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_PROCESAR, $item->status);
        $this->assertSame('error_de_busqueda', $item->motivo);
        $this->assertTrue($resultado['error_de_proveedor']);
        $this->assertStringContainsString('Not enough credits', $item->motivo_detalle);
        $this->assertSame(0, (int) $item->run->busquedas, 'Una búsqueda que falló no cuenta como búsqueda.');
        $this->assertSame(3, $resultado['busquedas_intentadas']);
    }

    /**
     * Un artículo borrado entre que se armó la corrida y ahora.
     *
     * @group dimension-search
     * @test
     */
    public function un_articulo_borrado_se_cierra_sin_buscar()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY');
        $run      = $this->corrida([$articulo]);

        $articulo->delete();

        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item) = $this->procesar($run, $articulo);

        $this->assertSame('articulo_borrado', $item->motivo);
        $this->assertSame([], $this->consultas_serper);
    }

    /**
     * Si otro tramo reclamó el artículo, este no lo pisa ni escribe en el artículo.
     *
     * @group dimension-search
     * @test
     */
    public function si_otro_tramo_reclamo_el_articulo_no_se_cierra_ni_se_escribe()
    {
        $gtin = $this->con_verificador('779123456982');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);
        $run      = $this->corrida([$articulo]);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $this->cuerpo_completo($gtin))],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $item->update(['status' => DimensionSearchItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => 'tramo-viejo']);

        // El motor corre con la ficha vieja pero la fila ya es de otro tramo.
        $item_viejo = $item->fresh();
        $item->update(['tramo' => 'tramo-nuevo']);

        (new \App\Services\DimensionSearch\ArticleDimensionSearchEngine($run->fresh(), $this->owner))->procesar($item_viejo);

        $this->assertSame(DimensionSearchItem::STATUS_PROCESANDO, $item->fresh()->status);
        $this->assertTrue(is_null($articulo->fresh()->peso) || (float) $articulo->fresh()->peso === 0.0, 'No escribió en el artículo.');
        $this->assertSame(0, (int) $run->fresh()->procesados);
    }

    /**
     * En una demo, con las búsquedas agotadas no sale a la red.
     *
     * @group dimension-search
     * @test
     */
    public function en_una_demo_sin_busquedas_no_sale_a_serper_y_el_articulo_queda_sin_procesar()
    {
        $anterior = [config('app.FOR_USER'), config('app.USER_ID')];
        config(['app.FOR_USER' => 'demo', 'app.USER_ID' => $this->owner->id]);

        try {
            $ahora  = \Carbon\Carbon::now();
            $filas  = [];

            for ($i = 0; $i < 25; $i++) {
                $filas[] = [
                    'event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id, 'process' => 'imagenes_seleccion',
                    'endpoint' => 'images', 'query' => 'previa '.$i, 'ok' => true, 'occurred_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }

            SerperUsageEvent::insert($filas);

            $gtin = $this->con_verificador('779123456990');
            $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

            $this->falsear([], [], $this->ficha('otro_producto'));

            list($item, $resultado) = $this->buscar($articulo);

            $this->assertSame([], $this->consultas_serper, 'No se llamó a Serper.');
            $this->assertSame(DimensionSearchItem::STATUS_SIN_PROCESAR, $item->status);
            $this->assertSame('sin_cupo', $item->motivo);
            $this->assertTrue($resultado['cupo_agotado']);
            $this->assertTrue($resultado['tope_demo']);
        } finally {
            config(['app.FOR_USER' => $anterior[0], 'app.USER_ID' => $anterior[1]]);
        }
    }

    /**
     * Un buscador y una página con los cuatro datos, parametrizables, para los tests de las compuertas.
     *
     * @param  string $gtin
     * @param  string $cuerpo  Lo que dice la página.
     * @param  array  $datos   peso, ancho, alto, largo => dato().
     * @param  string $match
     * @return void
     */
    protected function falsear_pagina_con($gtin, $cuerpo, array $datos, $match = 'exacto')
    {
        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', 'Código '.$gtin.'. '.$cuerpo)],
            $this->ficha($match, $datos)
        );
    }

    /**
     * B1: un valor sobre «1.200 g» NUNCA queda asignado: va a revisar con la frase.
     *
     * @group dimension-search
     * @test
     */
    public function un_valor_con_punto_de_miles_ambiguo_nunca_queda_asignado_va_a_revisar_con_la_frase()
    {
        $gtin = $this->con_verificador('779123457008');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $datos = $this->datos_completos(self::URL_A);
        // El modelo leyó 1,2 g (la lectura decimal) de «1.200 g».
        $datos['peso'] = $this->dato(1.2, 'g', 'Peso: 1.200 g', self::URL_A);

        $this->falsear_pagina_con($gtin, 'Peso: 1.200 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.', $datos);

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('valor_ambiguo', $item->motivo);
        $this->assertSame('La página escribe «1.200 g»: puede ser 1,2 o 1.200', $item->motivo_detalle);
        $this->assertStringContainsString('La página escribe «1.200 g»', $item->nota_ia);
        $this->assertEquals(0.0012, (float) $item->peso_kg, 'Se guarda la lectura que dijo el modelo.');
        $this->assertTrue($item->evidencia['peso']['ambiguo']);
        $this->assertNull($item->aplicado);
        $this->assertTrue(is_null($articulo->fresh()->peso) || (float) $articulo->fresh()->peso === 0.0, 'No se escribió nada.');
    }

    /**
     * M2: un rango nunca queda asignado.
     *
     * @group dimension-search
     * @test
     */
    public function un_rango_nunca_queda_asignado_va_a_revisar_con_la_frase()
    {
        $gtin = $this->con_verificador('779123457015');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $datos = $this->datos_completos(self::URL_A);
        $datos['alto'] = $this->dato(20, 'cm', 'Alto: 10-20 cm', self::URL_A);

        $this->falsear_pagina_con($gtin, 'Peso: 820 g. Ancho: 70 mm. Alto: 10-20 cm. Largo: 100 mm.', $datos);

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('valor_en_rango', $item->motivo);
        $this->assertSame('La página da un rango: 10-20 cm', $item->motivo_detalle);
        $this->assertTrue($item->evidencia['alto']['rango']);
        $this->assertNull($item->aplicado);
        $this->assertTrue(is_null($articulo->fresh()->alto) || (float) $articulo->fresh()->alto === 0.0);
    }

    /**
     * M1(c): una cita pelada («Mide 25 cm») no dice qué medida es.
     *
     * @group dimension-search
     * @test
     */
    public function una_cita_sin_etiqueta_nunca_queda_asignada()
    {
        $gtin = $this->con_verificador('779123457022');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $datos = $this->datos_completos(self::URL_A);
        $datos['alto'] = $this->dato(25, 'cm', 'Mide 25 cm', self::URL_A);

        $this->falsear_pagina_con($gtin, 'Peso: 820 g. Ancho: 70 mm. Mide 25 cm. Largo: 100 mm.', $datos);

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('cita_sin_etiqueta', $item->motivo);
        $this->assertStringContainsString('La cita de el alto no dice a qué medida corresponde', $item->motivo_detalle);
        $this->assertFalse($item->evidencia['alto']['etiquetada']);
        $this->assertNull($item->aplicado);

        // Una agrupación «A x B x C unidad» alcanza como etiqueta.
        $otro = $this->nuevo_articulo('OTRA CUCHILLA', $this->con_verificador('779123457039'));
        $gtin2 = $otro->bar_code;
        $url = self::URL_B;

        $this->falsear(
            ['"'.$gtin2.'"' => [$this->resultado($url)]],
            [$url => $this->pagina('Cuchilla', 'Código '.$gtin2.'. Peso: 820 g. Medidas 70 x 11 x 100 mm.')],
            $this->ficha('exacto', [
                'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
                'ancho' => $this->dato(70, 'mm', '70 x 11 x 100 mm', $url),
                'alto'  => $this->dato(11, 'mm', '70 x 11 x 100 mm', $url),
                'largo' => $this->dato(100, 'mm', '70 x 11 x 100 mm', $url),
            ])
        );

        list($item_grupo) = $this->buscar($otro);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item_grupo->status);
    }

    /**
     * Menor 3: lo propuesto difiere más de 50 % de lo que una persona ya cargó.
     *
     * @group dimension-search
     * @test
     */
    public function si_difiere_mas_de_la_mitad_de_lo_ya_cargado_no_se_asigna_sola()
    {
        $gtin = $this->con_verificador('779123457046');
        // Cargó 2,5 kg a mano; la página dice 0,82 kg.
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin, ['peso' => 2.5]);

        $this->falsear_pagina_con($gtin, $this->cuerpo_completo(), $this->datos_completos(self::URL_A));

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('difiere_de_lo_cargado', $item->motivo);
        $this->assertStringContainsString('el peso (2,5 contra 0,82 de la página)', $item->motivo_detalle);
        $this->assertEquals(2.5, (float) $articulo->fresh()->peso, 'No se tocó nada.');
        $this->assertTrue(is_null($articulo->fresh()->ancho) || (float) $articulo->fresh()->ancho === 0.0);

        // Justo por debajo del 50 %: sigue siendo una asignación (que no pisa el peso).
        $parecido = $this->nuevo_articulo('CUCHILLA PARECIDA', $this->con_verificador('779123457053'), ['peso' => 1.2]);

        $url = self::URL_B;
        $this->falsear(
            ['"'.$parecido->bar_code.'"' => [$this->resultado($url)]],
            [$url => $this->pagina('Cuchilla', 'Código '.$parecido->bar_code.'. '.$this->cuerpo_completo())],
            $this->ficha('exacto', $this->datos_completos($url))
        );

        list($item_parecido) = $this->buscar($parecido);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item_parecido->status, '1,2 contra 0,82 difiere 32 %.');
        $this->assertEquals(1.2, (float) $parecido->fresh()->peso);
    }

    /**
     * M1(a): el código de proveedor tiene que parecer un código.
     *
     * @group dimension-search
     * @test
     */
    public function solo_un_codigo_que_parece_un_codigo_genera_el_plan_de_proveedor()
    {
        $casos = [
            // [código, ¿genera plan?]
            ['VR-CU-0402', true],
            ['AJM2032', true],
            ['6202 2RST', true],
            ['55.90-144', true],
            ['2141058', true],
            ['AB-12', true],
            ['12345', false],      // 5 dígitos
            ['1234', false],       // corto
            ['ABCDEFG', false],    // solo letras
            ['TRANSPORTE', false], // una palabra
            ['AB-1', false],       // 4 caracteres
        ];

        foreach ($casos as $caso) {
            $articulo = $this->nuevo_articulo('PRODUCTO DE PRUEBA LARGO', null, ['provider_code' => $caso[0]]);

            $this->falsear([], [], $this->ficha('otro_producto'));
            $this->buscar($articulo);

            $buscadas_por_codigo = array_filter($this->consultas_serper, function ($consulta) use ($caso) {
                return strpos($consulta, '"'.$caso[0].'"') === 0;
            });

            $this->assertSame($caso[1], count($buscadas_por_codigo) === 1, 'Código de proveedor «'.$caso[0].'»');

            // Siempre queda el plan de nombre.
            $this->assertContains('PRODUCTO DE PRUEBA LARGO ficha técnica peso medidas', $this->consultas_serper);
        }
    }

    /**
     * M1(b): el código en un script, un comentario o un estilo no prueba que la página sea del artículo.
     *
     * @group dimension-search
     * @test
     */
    public function un_codigo_que_solo_esta_en_un_script_o_un_comentario_no_cuenta()
    {
        $gtin = $this->con_verificador('779123457060');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $html = '<html><head><title>Otra cosa</title><script>var producto_relacionado = "'.$gtin.'";</script><style>/* '.$gtin.' */</style></head>'
            .'<body><!-- '.$gtin.' --><p>'.$this->cuerpo_completo().'</p></body></html>';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $html],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame([], $this->pedidos_ia, 'La IA ni se llamó: ninguna página tenía el código.');
        $this->assertFalse($item->diagnostico[0]['paginas'][0]['leida']);
        $this->assertStringContainsString('no contiene el código', $item->diagnostico[0]['paginas'][0]['motivo']);
        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
    }

    /**
     * M1(b): el código en un JSON-LD de producto sí cuenta (es la descripción del producto).
     *
     * @group dimension-search
     * @test
     */
    public function el_codigo_en_el_json_ld_del_producto_si_cuenta()
    {
        $gtin = $this->con_verificador('779123457077');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        $html = '<html><head><title>Cuchilla</title><script type="application/ld+json">{"@type":"Product","gtin13":"'.$gtin.'"}</script></head>'
            .'<body><p>'.$this->cuerpo_completo().'</p></body></html>';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $html],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertStringContainsString('JSON-LD', $this->pedidos_ia[0]['entrada']['documents'][0]['text']);
    }

    /**
     * M1(b): la ventana del código entra al extracto aunque la página sea larga y tenga mucho texto antes.
     *
     * @group dimension-search
     * @test
     */
    public function la_ventana_del_codigo_entra_al_extracto_aunque_la_pagina_sea_larga()
    {
        $gtin = $this->con_verificador('779123457084');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);

        // Mucho texto con las palabras de ficha técnica ANTES de la parte que tiene el código.
        $relleno = str_repeat('Características del producto: peso y medidas generales de la línea completa. ', 150);
        $cuerpo  = $relleno.' Código '.$gtin.'. '.$this->cuerpo_completo();

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', $cuerpo)],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $texto = $this->pedidos_ia[0]['entrada']['documents'][0]['text'];

        $this->assertStringContainsString($gtin, $texto, 'La ventana alrededor del código no se la comió el tope.');
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
    }

    /**
     * Menor 1: una página en ISO-8859-1 / Windows-1252 se convierte a UTF-8 antes de recortarla.
     *
     * @group dimension-search
     * @test
     */
    public function una_pagina_en_iso_8859_1_se_lee_en_utf8_y_no_queda_vacia()
    {
        $gtin = $this->con_verificador('779123457091');
        $articulo = $this->nuevo_articulo('CUCHILLA RÍGIDA', $gtin);

        $utf8 = '<html><head><title>Cuchilla rígida</title></head><body><p>Código '.$gtin.'. Descripción: cuchilla rígida de acero. '.$this->cuerpo_completo().'</p></body></html>';
        $latin1 = iconv('UTF-8', 'Windows-1252', $utf8);

        $this->assertFalse(mb_check_encoding($latin1, 'UTF-8'), 'El fixture es de verdad Latin-1.');

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado(self::URL_A)]],
            [self::URL_A => ['html' => $latin1, 'tipo' => 'text/html; charset=iso-8859-1']],
            $this->ficha('exacto', $this->datos_completos(self::URL_A))
        );

        list($item) = $this->buscar($articulo);

        $texto = $this->pedidos_ia[0]['entrada']['documents'][0]['text'];

        $this->assertStringContainsString('Descripción: cuchilla rígida de acero', $texto);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);

        // Sin charset declarado y con bytes que no son UTF-8: se asume Windows-1252.
        $otro = $this->nuevo_articulo('OTRA CUCHILLA RÍGIDA', $this->con_verificador('779123457107'));

        $this->falsear(
            ['"'.$otro->bar_code.'"' => [$this->resultado(self::URL_B)]],
            [self::URL_B => ['html' => iconv('UTF-8', 'Windows-1252', '<html><head><title>Cuchilla</title></head><body><p>Código '.$otro->bar_code.'. Ñandú rígido. '.$this->cuerpo_completo().'</p></body></html>'), 'tipo' => 'text/html']],
            $this->ficha('otro_producto')
        );

        $this->buscar($otro);

        $this->assertStringContainsString('Ñandú rígido', $this->pedidos_ia[0]['entrada']['documents'][0]['text']);
    }
}
