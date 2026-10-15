<?php

namespace Tests\Feature\DescripcionesIa;

use App\Models\ArticleDescriptionProposal;

/**
 * La lectura de Alertas → Catálogo → Descripciones (misión alertas-descripciones, 14/10/2026,
 * contrato §1.4): el resumen del número rojo y el listado de las dos solapas, con buscar y paginar.
 *
 * 🔴 La forma de la respuesta y del Item es el CONTRATO con la SPA (que se construyó en paralelo):
 * acá se miran las claves exactas.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Lectura_de_la_revision_Test extends DescripcionesIaTestCase
{
    /**
     * El resumen cuenta ARTÍCULOS: a revisar (propuestas con el artículo vivo) y publicadas
     * (artículos vivos con alguna descripción de IA, aunque tengan varias secciones). `badge` es
     * a_revisar.
     *
     * @test
     * @group descripciones_ia
     */
    public function el_resumen_cuenta_articulos_de_cada_solapa()
    {
        $this->getJson('api/article-description-ai/resumen')
            ->assertStatus(200)
            ->assertExactJson(['a_revisar' => 0, 'publicadas' => 0, 'badge' => 0]);

        // Dos a revisar (una con dos secciones), una descartada y una de un artículo en la papelera.
        $this->sembrar_propuesta($this->crear_articulo('A'), [['T1', 'c1'], ['T2', 'c2']]);
        $this->sembrar_propuesta($this->crear_articulo('B'));
        $this->sembrar_propuesta($this->crear_articulo('C'), [], ['status' => ArticleDescriptionProposal::STATUS_DESCARTADA]);
        $borrado = $this->crear_articulo('D');
        $this->sembrar_propuesta($borrado);
        $borrado->delete();

        // Publicadas: un artículo con dos secciones de IA, uno con una, uno solo con humana.
        $publicado = $this->crear_articulo('E');
        $this->sembrar_descripcion($publicado, 'Uno', 'uno');
        $this->sembrar_descripcion($publicado, 'Dos', 'dos');
        $this->sembrar_descripcion($this->crear_articulo('F'), 'Uno', 'uno');
        $this->sembrar_descripcion_humana($this->crear_articulo('G'));

        $this->getJson('api/article-description-ai/resumen')
            ->assertStatus(200)
            ->assertExactJson(['a_revisar' => 2, 'publicadas' => 2, 'badge' => 2]);
    }

    /**
     * "A revisar": la forma exacta de la respuesta y del Item, las secciones de la propuesta con `id`
     * null, las fuentes, la confianza y la fecha de la propuesta; los códigos vacíos en null. La más
     * nueva primero.
     *
     * @test
     * @group descripciones_ia
     */
    public function a_revisar_devuelve_el_item_del_contrato()
    {
        $taladro = $this->crear_articulo('Taladro percutor 550 W', ['bar_code' => '7798000000011', 'provider_code' => 'TP-550']);
        $sierra  = $this->crear_articulo('Sierra circular', ['bar_code' => '', 'provider_code' => null]);

        $this->sembrar_propuesta($taladro, [['Descripción del producto', 'Taladro de 550 W'], ['Uso', 'Hormigón y madera']], [
            'created_at' => '2026-10-14 18:00:00',
        ]);
        $this->sembrar_propuesta($sierra, [], ['created_at' => '2026-10-13 09:00:00', 'ai_sources' => null]);

        $respuesta = $this->getJson('api/article-description-ai/revision');

        $respuesta->assertStatus(200);
        $this->assertSame(['models', 'total', 'pagina', 'por_pagina', 'conteos'], array_keys($respuesta->json()));
        $this->assertSame(2, $respuesta->json('total'));
        $this->assertSame(1, $respuesta->json('pagina'));
        $this->assertSame(25, $respuesta->json('por_pagina'));
        $this->assertSame(['a_revisar' => 2, 'publicadas' => 0], $respuesta->json('conteos'));

        $models = $respuesta->json('models');
        $this->assertSame([$taladro->id, $sierra->id], array_column($models, 'article_id'), 'La propuesta más nueva primero.');

        $item = $models[0];
        $this->assertSame(['article_id', 'articulo', 'secciones', 'fuentes', 'confianza', 'fecha'], array_keys($item));
        $this->assertSame([
            'id'                  => $taladro->id,
            'nombre'              => 'Taladro percutor 550 W',
            'codigo_de_barras'    => '7798000000011',
            'codigo_de_proveedor' => 'TP-550',
        ], $item['articulo']);
        $this->assertSame([
            ['id' => null, 'title' => 'Descripción del producto', 'content' => 'Taladro de 550 W'],
            ['id' => null, 'title' => 'Uso', 'content' => 'Hormigón y madera'],
        ], $item['secciones']);
        $this->assertSame([['source' => 'google_name', 'url' => 'https://www.ejemplo.com.ar/producto', 'title' => 'Ficha de ejemplo']], $item['fuentes']);
        $this->assertSame('low', $item['confianza']);
        $this->assertSame('2026-10-14 18:00:00', $item['fecha']);

        // Sin códigos y sin fuentes.
        $this->assertNull($models[1]['articulo']['codigo_de_barras']);
        $this->assertNull($models[1]['articulo']['codigo_de_proveedor']);
        $this->assertSame([], $models[1]['fuentes'], 'Siempre un arreglo.');

        // `solapa=a_revisar` explícita es lo mismo.
        $this->assertSame($models, $this->getJson('api/article-description-ai/revision?solapa=a_revisar')->json('models'));
    }

    /**
     * "Publicadas": las filas de IA del artículo como secciones (con su `id`, por id), fuentes y
     * confianza de la primera, la fecha de la más nueva; sin las humanas. Primero el artículo con la
     * descripción de IA más nueva.
     *
     * @test
     * @group descripciones_ia
     */
    public function publicadas_devuelve_las_descripciones_de_ia_del_articulo()
    {
        $viejo = $this->crear_articulo('Martillo');
        $nuevo = $this->crear_articulo('Pinza', ['provider_code' => 'PZ-1']);

        $uno = $this->sembrar_descripcion($nuevo, 'Descripción', 'Pinza universal', [
            'ai_confidence' => 'medium',
            'created_at'    => '2026-10-10 10:00:00',
        ]);
        $dos = $this->sembrar_descripcion($nuevo, 'Medidas', '8 pulgadas', [
            'ai_confidence' => 'high',
            'ai_sources'    => [],
            'created_at'    => '2026-10-12 11:00:00',
        ]);
        $this->sembrar_descripcion_humana($nuevo, 'Escrita a mano');
        $this->sembrar_descripcion($viejo, 'Descripción', 'Martillo de carpintero', ['created_at' => '2026-10-11 10:00:00']);

        // Uno solo con humana no aparece.
        $this->sembrar_descripcion_humana($this->crear_articulo('Serrucho'));

        $respuesta = $this->getJson('api/article-description-ai/revision?solapa=publicadas');

        $respuesta->assertStatus(200);
        $this->assertSame(2, $respuesta->json('total'));
        $this->assertSame(['a_revisar' => 0, 'publicadas' => 2], $respuesta->json('conteos'));

        $models = $respuesta->json('models');
        $this->assertSame([$nuevo->id, $viejo->id], array_column($models, 'article_id'), 'La descripción de IA más nueva primero.');

        $item = $models[0];
        $this->assertSame(['article_id', 'articulo', 'secciones', 'fuentes', 'confianza', 'fecha'], array_keys($item));
        $this->assertSame(['id' => $nuevo->id, 'nombre' => 'Pinza', 'codigo_de_barras' => null, 'codigo_de_proveedor' => 'PZ-1'], $item['articulo']);
        $this->assertSame([
            ['id' => $uno->id, 'title' => 'Descripción', 'content' => 'Pinza universal'],
            ['id' => $dos->id, 'title' => 'Medidas', 'content' => '8 pulgadas'],
        ], $item['secciones'], 'Sin la humana.');
        $this->assertSame('medium', $item['confianza'], 'La de la primera.');
        $this->assertSame([['source' => 'barcode_api', 'url' => 'https://upcitemdb.com/x', 'title' => 'UPC']], $item['fuentes']);
        $this->assertSame('2026-10-12 11:00:00', $item['fecha'], 'La de la más nueva.');
    }

    /**
     * Buscar por nombre, código de barras o código de proveedor, en las dos solapas; `%` y `_` se
     * buscan como texto, no como comodines.
     *
     * @test
     * @group descripciones_ia
     */
    public function buscar_por_nombre_o_codigos()
    {
        $taladro = $this->crear_articulo('Taladro percutor', ['bar_code' => '7791111111111', 'provider_code' => 'TP-550']);
        $sierra  = $this->crear_articulo('Sierra 50% off', ['bar_code' => '7792222222222', 'provider_code' => 'SC_185']);
        $lija    = $this->crear_articulo('Lija al agua', ['bar_code' => '7793333333333', 'provider_code' => 'LJ-100']);

        foreach ([$taladro, $sierra, $lija] as $articulo) {
            $this->sembrar_propuesta($articulo);
            $this->sembrar_descripcion($this->crear_articulo('Publicado '.$articulo->name, ['provider_code' => $articulo->provider_code.'-P']), 'D', 'd');
        }

        $buscar = function ($texto, $solapa = 'a_revisar') {
            $respuesta = $this->getJson('api/article-description-ai/revision?solapa='.$solapa.'&buscar='.urlencode($texto));
            $respuesta->assertStatus(200);

            return array_map(function ($item) {
                return $item['articulo']['nombre'];
            }, $respuesta->json('models'));
        };

        $this->assertSame(['Taladro percutor'], $buscar('percutor'));
        $this->assertSame(['Lija al agua'], $buscar('7793333'));
        $this->assertSame(['Taladro percutor'], $buscar('TP-55'));
        $this->assertSame(['Sierra 50% off'], $buscar('50%'), 'El % es texto.');
        $this->assertSame(['Sierra 50% off'], $buscar('SC_'), 'El _ es texto.');
        $this->assertSame([], $buscar('TP_550'), 'Un _ no reemplaza al guion.');
        $this->assertSame(['Publicado Lija al agua'], $buscar('LJ-100-P', 'publicadas'));

        // El total sigue al filtro; los conteos, no.
        $respuesta = $this->getJson('api/article-description-ai/revision?buscar=percutor');
        $this->assertSame(1, $respuesta->json('total'));
        $this->assertSame(['a_revisar' => 3, 'publicadas' => 3], $respuesta->json('conteos'));
    }

    /**
     * Paginar: `por_pagina` se acota a 10..100 (25 por defecto) y `pagina` arranca en 1.
     *
     * @test
     * @group descripciones_ia
     */
    public function paginar_en_el_servidor()
    {
        $ids = [];

        for ($i = 1; $i <= 12; $i++) {
            $articulo = $this->crear_articulo('Artículo '.$i);
            $this->sembrar_propuesta($articulo, [], ['created_at' => '2026-10-01 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00']);
            $ids[] = $articulo->id;
        }

        $mas_nuevos_primero = array_reverse($ids);

        $primera = $this->getJson('api/article-description-ai/revision?por_pagina=10&pagina=1');
        $primera->assertStatus(200);
        $this->assertSame(12, $primera->json('total'));
        $this->assertSame(10, $primera->json('por_pagina'));
        $this->assertSame(array_slice($mas_nuevos_primero, 0, 10), array_column($primera->json('models'), 'article_id'));

        $segunda = $this->getJson('api/article-description-ai/revision?por_pagina=10&pagina=2');
        $this->assertSame(2, $segunda->json('pagina'));
        $this->assertSame(array_slice($mas_nuevos_primero, 10), array_column($segunda->json('models'), 'article_id'));

        // Acotados.
        $this->assertSame(10, $this->getJson('api/article-description-ai/revision?por_pagina=3')->json('por_pagina'));
        $this->assertSame(100, $this->getJson('api/article-description-ai/revision?por_pagina=500')->json('por_pagina'));
        $this->assertSame(25, $this->getJson('api/article-description-ai/revision?por_pagina=0')->json('por_pagina'));
        $this->assertSame(1, $this->getJson('api/article-description-ai/revision?pagina=0')->json('pagina'));
        $this->assertSame(1, $this->getJson('api/article-description-ai/revision?pagina=abc')->json('pagina'));

        // Una página de más viene vacía, con el total.
        $vacia = $this->getJson('api/article-description-ai/revision?por_pagina=10&pagina=3');
        $this->assertSame([], $vacia->json('models'));
        $this->assertSame(12, $vacia->json('total'));
    }

    /**
     * Una solapa que no existe es un 422 estándar de Laravel.
     *
     * @test
     * @group descripciones_ia
     */
    public function una_solapa_que_no_existe_da_422()
    {
        $respuesta = $this->getJson('api/article-description-ai/revision?solapa=todas');

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['solapa']);
        $this->assertSame('Esa solapa no existe: elegí «A revisar» o «Publicadas».', $respuesta->json('errors.solapa.0'));
        $this->assertSame('Esa solapa no existe: elegí «A revisar» o «Publicadas».', $respuesta->json('message'));
    }
}
