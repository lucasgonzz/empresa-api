<?php

namespace Tests\Feature\DescripcionesIa;

use App\Http\Controllers\Helpers\AiDescriptionReviewHelper;
use App\Jobs\ProcessSyncArticleDescriptionTiendaNube;
use App\Models\ArticleDescriptionProposal;
use App\Models\Description;
use Illuminate\Support\Facades\Queue;

/**
 * Resolver la revisión (misión alertas-descripciones, 14/10/2026, contrato §1.4): aprobar (tal cual o
 * editada), descartar, en lote, editar una publicada y quitarla.
 *
 * Qué protege:
 *  - 🔴 Aprobar es lo único que convierte una propuesta en descripciones del artículo, y una sola
 *    vez (un segundo aprobar es 404, no un duplicado).
 *  - Descartar no publica nada, y el lote automático no lo vuelve a proponer.
 *  - Editar una publicada solo toca descripciones de IA de ESE artículo.
 *  - Quitar borra solo lo de IA y deja una propuesta descartada (el lote no la regenera).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Resolver_la_revision_Test extends DescripcionesIaTestCase
{
    // ---------------------------------------------------------------------------------------------
    // Aprobar y descartar
    // ---------------------------------------------------------------------------------------------

    /**
     * Aprobar tal cual: las secciones de la propuesta pasan a ser descripciones de IA revisadas (con
     * su confianza y sus fuentes), la propuesta queda `aprobada` con quién y cuándo, y la respuesta
     * trae los conteos.
     *
     * @test
     * @group descripciones_ia
     */
    public function aprobar_tal_cual_publica_las_secciones_de_la_propuesta()
    {
        $articulo  = $this->crear_articulo('Taladro');
        $propuesta = $this->sembrar_propuesta($articulo, [['Descripción', 'Taladro de 550 W'], ['Uso', 'Madera y hormigón']]);

        $respuesta = $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar');

        $respuesta->assertStatus(200)->assertExactJson(['conteos' => ['a_revisar' => 0, 'publicadas' => 1]]);

        $descripciones = $this->descripciones_de($articulo);
        $this->assertSame(['Descripción', 'Uso'], $descripciones->pluck('title')->all());
        $this->assertSame(['Taladro de 550 W', 'Madera y hormigón'], $descripciones->pluck('content')->all());

        foreach ($descripciones as $descripcion) {
            $this->assertTrue($descripcion->ai_generated);
            $this->assertSame('low', $descripcion->ai_confidence);
            $this->assertNotNull($descripcion->ai_reviewed_at, 'Ya la revisó una persona.');
            $this->assertSame('https://www.ejemplo.com.ar/producto', $descripcion->ai_sources[0]['url']);
        }

        $propuesta = $this->propuesta_fresca($propuesta);
        $this->assertSame('aprobada', $propuesta->status);
        $this->assertNotNull($propuesta->resolved_at);
        $this->assertSame($this->owner->id, (int) $propuesta->resolved_by);

        // Un segundo aprobar (doble clic) no duplica: ya no hay nada a revisar.
        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar')
            ->assertStatus(404)
            ->assertJsonStructure(['message']);
        $this->assertCount(2, $this->descripciones_de($articulo));
    }

    /**
     * Aprobar con las secciones editadas publica lo que escribió la persona, no lo de la IA.
     *
     * @test
     * @group descripciones_ia
     */
    public function aprobar_editada_publica_lo_que_escribio_la_persona()
    {
        $articulo = $this->crear_articulo('Sierra');
        $this->sembrar_propuesta($articulo, [['Descripción', 'Lo que dijo la IA'], ['Otra', 'Otra cosa']]);

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar', [
            'secciones' => [
                ['id' => null, 'title' => 'Descripción corregida', 'content' => 'Sierra circular de 185 mm'],
            ],
        ])->assertStatus(200);

        $descripciones = $this->descripciones_de($articulo);
        $this->assertSame(['Descripción corregida'], $descripciones->pluck('title')->all());
        $this->assertSame(['Sierra circular de 185 mm'], $descripciones->pluck('content')->all());
    }

    /**
     * Las secciones editadas se validan: de 1 a 3, cada una con título (hasta 255) y texto. 422
     * estándar y nada escrito.
     *
     * @test
     * @group descripciones_ia
     */
    public function las_secciones_editadas_se_validan()
    {
        $articulo  = $this->crear_articulo('Lijadora');
        $propuesta = $this->sembrar_propuesta($articulo);

        // caso => [cuerpo, el campo que tiene que traer el error]
        $invalidos = [
            'vacías'           => [['secciones' => []], 'secciones'],
            'cuatro'           => [['secciones' => array_fill(0, 4, ['title' => 'T', 'content' => 'c'])], 'secciones'],
            'sin título'       => [['secciones' => [['content' => 'c']]], 'secciones.0.title'],
            'sin texto'        => [['secciones' => [['title' => 'T', 'content' => '']]], 'secciones.0.content'],
            'título muy largo' => [['secciones' => [['title' => str_repeat('a', 256), 'content' => 'c']]], 'secciones.0.title'],
        ];

        foreach ($invalidos as $caso => $par) {
            $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar', $par[0])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$par[1]]);
        }

        $this->assertSame('a_revisar', $this->propuesta_fresca($propuesta)->status);
        $this->assertCount(0, $this->descripciones_de($articulo));
    }

    /**
     * Descartar: la propuesta queda `descartada` con quién y cuándo, no se publica nada, y el lote
     * automático ya no se la propone.
     *
     * @test
     * @group descripciones_ia
     */
    public function descartar_no_publica_nada_y_el_lote_no_la_vuelve_a_proponer()
    {
        $articulo  = $this->crear_articulo('Pinza');
        $propuesta = $this->sembrar_propuesta($articulo);

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/descartar')
            ->assertStatus(200)
            ->assertExactJson(['conteos' => ['a_revisar' => 0, 'publicadas' => 0]]);

        $propuesta = $this->propuesta_fresca($propuesta);
        $this->assertSame('descartada', $propuesta->status);
        $this->assertNotNull($propuesta->resolved_at);
        $this->assertSame($this->owner->id, (int) $propuesta->resolved_by);
        $this->assertCount(0, $this->descripciones_de($articulo));

        $this->assertSame('propuesta_descartada', AiDescriptionReviewHelper::motivo_para_saltear($articulo->fresh(), false));

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/descartar')->assertStatus(404);
    }

    /**
     * Sin propuesta a revisar (nunca tuvo, ya resuelta, o el artículo está en la papelera): 404 con
     * `message`, en aprobar y en descartar.
     *
     * @test
     * @group descripciones_ia
     */
    public function sin_propuesta_a_revisar_da_404()
    {
        $sin_nada = $this->crear_articulo('Sin nada');

        $resuelta = $this->crear_articulo('Ya resuelta');
        $this->sembrar_propuesta($resuelta, [], ['status' => ArticleDescriptionProposal::STATUS_DESCARTADA]);

        $borrado = $this->crear_articulo('Borrado');
        $this->sembrar_propuesta($borrado);
        $borrado->delete();

        foreach ([$sin_nada->id, $resuelta->id, $borrado->id, 2000000000] as $id) {
            foreach (['aprobar', 'descartar'] as $accion) {
                $respuesta = $this->postJson('api/article-description-ai/revision/'.$id.'/'.$accion);
                $respuesta->assertStatus(404);
                $this->assertNotEmpty($respuesta->json('message'));
            }
        }

        $this->assertSame(0, Description::where('article_id', $borrado->id)->count());
    }

    /**
     * 🔴 Si al aprobar el artículo ya tiene una descripción escrita a mano (alguien la cargó mientras
     * la sugerencia esperaba), no se duplica: la propuesta queda descartada (con quien la quiso
     * aprobar) y la respuesta es 409 con el mensaje y los conteos.
     *
     * @test
     * @group descripciones_ia
     */
    public function aprobar_un_articulo_con_descripcion_a_mano_la_descarta_con_409()
    {
        $articulo  = $this->crear_articulo('Taladro');
        $propuesta = $this->sembrar_propuesta($articulo);
        $humana    = $this->sembrar_descripcion_humana($articulo, 'La escribió el dueño');

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar')
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Este artículo ya tiene una descripción escrita a mano: la sugerencia se descartó para no duplicarla.',
                'conteos' => ['a_revisar' => 0, 'publicadas' => 0],
            ]);

        $propuesta = $this->propuesta_fresca($propuesta);
        $this->assertSame('descartada', $propuesta->status);
        $this->assertNotNull($propuesta->resolved_at);
        $this->assertSame($this->owner->id, (int) $propuesta->resolved_by);
        $this->assertSame([$humana->id], $this->descripciones_de($articulo)->pluck('id')->all(), 'Solo la humana.');

        // Lo mismo con las secciones editadas.
        $otro = $this->crear_articulo('Sierra');
        $this->sembrar_propuesta($otro);
        $this->sembrar_descripcion_humana($otro);

        $this->postJson('api/article-description-ai/revision/'.$otro->id.'/aprobar', [
            'secciones' => [['title' => 'Editada', 'content' => 'Editada']],
        ])->assertStatus(409);
        $this->assertCount(1, $this->descripciones_de($otro));
    }

    /**
     * En lote, los que ya tienen descripción a mano se descartan igual y no cuentan como resueltos.
     *
     * @test
     * @group descripciones_ia
     */
    public function aprobar_varios_descarta_los_que_tienen_descripcion_a_mano_sin_contarlos()
    {
        $normal     = $this->crear_articulo('Normal');
        $con_humana = $this->crear_articulo('Con humana');
        $p_normal   = $this->sembrar_propuesta($normal);
        $p_humana   = $this->sembrar_propuesta($con_humana);
        $this->sembrar_descripcion_humana($con_humana);

        $this->postJson('api/article-description-ai/revision/aprobar-varios', [
            'article_ids' => [$normal->id, $con_humana->id],
        ])->assertStatus(200)->assertExactJson([
            'resueltos' => 1,
            'conteos'   => ['a_revisar' => 0, 'publicadas' => 1],
        ]);

        $this->assertSame('aprobada', $this->propuesta_fresca($p_normal)->status);
        $this->assertSame('descartada', $this->propuesta_fresca($p_humana)->status);
        $this->assertSame(0, Description::where('article_id', $con_humana->id)->where('ai_generated', true)->count());
    }

    // ---------------------------------------------------------------------------------------------
    // En lote
    // ---------------------------------------------------------------------------------------------

    /**
     * Aprobar y descartar en lote: cuentan los resueltos e ignoran los que no tienen propuesta (sin
     * propuesta, ajenos, inexistentes, repetidos).
     *
     * @test
     * @group descripciones_ia
     */
    public function en_lote_resuelve_los_que_tienen_propuesta_e_ignora_el_resto()
    {
        $a = $this->crear_articulo('A');
        $b = $this->crear_articulo('B');
        $c = $this->crear_articulo('C');
        $d = $this->crear_articulo('D');
        $sin_propuesta = $this->crear_articulo('Sin propuesta');
        $ajeno = $this->crear_articulo('Ajeno', [], $this->vecino);

        foreach ([$a, $b, $c, $d, $ajeno] as $articulo) {
            $this->sembrar_propuesta($articulo);
        }

        $aprobar = $this->postJson('api/article-description-ai/revision/aprobar-varios', [
            'article_ids' => [$a->id, $b->id, (string) $b->id, $sin_propuesta->id, $ajeno->id, 2000000000],
        ]);

        $aprobar->assertStatus(200)->assertExactJson([
            'resueltos' => 2,
            'conteos'   => ['a_revisar' => 2, 'publicadas' => 2],
        ]);
        $this->assertCount(1, $this->descripciones_de($a));
        $this->assertCount(1, $this->descripciones_de($b), 'El repetido no duplica.');
        $this->assertSame('a_revisar', ArticleDescriptionProposal::where('article_id', $ajeno->id)->value('status'), 'Lo ajeno no se toca.');

        $descartar = $this->postJson('api/article-description-ai/revision/descartar-varios', [
            'article_ids' => [$c->id, $d->id, $a->id],
        ]);

        $descartar->assertStatus(200)->assertExactJson([
            'resueltos' => 2,
            'conteos'   => ['a_revisar' => 0, 'publicadas' => 2],
        ]);
        $this->assertSame('descartada', ArticleDescriptionProposal::where('article_id', $c->id)->value('status'));
        $this->assertSame('aprobada', ArticleDescriptionProposal::where('article_id', $a->id)->value('status'), 'El ya aprobado no se descarta.');
    }

    /**
     * La forma del lote: `article_ids` es un arreglo de 1 a 100 enteros. Todo lo demás, 422 estándar.
     *
     * @test
     * @group descripciones_ia
     */
    public function el_lote_valida_los_ids()
    {
        $invalidos = [
            'sin ids'     => [],
            'vacío'       => ['article_ids' => []],
            'no es lista' => ['article_ids' => 'abc'],
            'con letras'  => ['article_ids' => [1, 'abc']],
            'ciento uno'  => ['article_ids' => range(1, 101)],
        ];

        foreach ($invalidos as $caso => $cuerpo) {
            foreach (['aprobar-varios', 'descartar-varios'] as $ruta) {
                $this->postJson('api/article-description-ai/revision/'.$ruta, $cuerpo)
                    ->assertStatus(422)
                    ->assertJsonStructure(['message', 'errors']);
            }
        }

        $this->postJson('api/article-description-ai/revision/aprobar-varios', ['article_ids' => range(1, 100)])
            ->assertStatus(200)
            ->assertJsonPath('resueltos', 0);
    }

    // ---------------------------------------------------------------------------------------------
    // Publicadas: editar y quitar
    // ---------------------------------------------------------------------------------------------

    /**
     * Editar una publicada cambia título y texto de esas filas, las marca revisadas y devuelve el
     * Item de "Publicadas" con los conteos.
     *
     * @test
     * @group descripciones_ia
     */
    public function editar_una_publicada_cambia_el_texto_y_la_marca_revisada()
    {
        $articulo = $this->crear_articulo('Martillo', ['bar_code' => '7790000000001']);
        $uno = $this->sembrar_descripcion($articulo, 'Descripción', 'Martillo de carpintero');
        $dos = $this->sembrar_descripcion($articulo, 'Medidas', '500 g');

        $respuesta = $this->putJson('api/article-description-ai/revision/'.$articulo->id.'/publicada', [
            'secciones' => [
                ['id' => $dos->id, 'title' => 'Peso', 'content' => '500 gramos'],
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(['model', 'conteos'], array_keys($respuesta->json()));
        $this->assertSame(['a_revisar' => 0, 'publicadas' => 1], $respuesta->json('conteos'));
        $this->assertSame($articulo->id, $respuesta->json('model.article_id'));
        $this->assertSame([
            ['id' => $uno->id, 'title' => 'Descripción', 'content' => 'Martillo de carpintero'],
            ['id' => $dos->id, 'title' => 'Peso', 'content' => '500 gramos'],
        ], $respuesta->json('model.secciones'));

        $dos = Description::find($dos->id);
        $this->assertSame('Peso', $dos->title);
        $this->assertSame('500 gramos', $dos->content);
        $this->assertNotNull($dos->ai_reviewed_at);
        $this->assertNull(Description::find($uno->id)->ai_reviewed_at, 'La que no se mandó no se toca.');
    }

    /**
     * 🔴 Solo descripciones de IA de ESE artículo: una humana o una de otro artículo es 422 y no se
     * escribe nada (tampoco la sección válida que venía al lado). Validación de la forma, 422
     * estándar.
     *
     * @test
     * @group descripciones_ia
     */
    public function editar_solo_toca_descripciones_de_ia_del_articulo()
    {
        $articulo = $this->crear_articulo('Pinza');
        $de_ia    = $this->sembrar_descripcion($articulo, 'Descripción', 'Pinza universal');
        $humana   = $this->sembrar_descripcion_humana($articulo, 'Escrita a mano');
        $de_otro  = $this->sembrar_descripcion($this->crear_articulo('Otro'), 'Descripción', 'De otro artículo');

        foreach ([$humana->id, $de_otro->id, 2000000000] as $id_ajeno) {
            $respuesta = $this->putJson('api/article-description-ai/revision/'.$articulo->id.'/publicada', [
                'secciones' => [
                    ['id' => $de_ia->id, 'title' => 'Cambiada', 'content' => 'Cambiada'],
                    ['id' => $id_ajeno, 'title' => 'Pisada', 'content' => 'Pisada'],
                ],
            ]);

            $respuesta->assertStatus(422)->assertJsonValidationErrors(['secciones']);
        }

        $this->assertSame('Pinza universal', Description::find($de_ia->id)->content);
        $this->assertSame('Escrita a mano', Description::find($humana->id)->content);
        $this->assertSame('De otro artículo', Description::find($de_otro->id)->content);

        // La forma.
        $invalidos = [
            'sin secciones' => [],
            'sin id'        => ['secciones' => [['title' => 'T', 'content' => 'c']]],
            'cuatro'        => ['secciones' => array_fill(0, 4, ['id' => $de_ia->id, 'title' => 'T', 'content' => 'c'])],
            'sin texto'     => ['secciones' => [['id' => $de_ia->id, 'title' => 'T', 'content' => '']]],
        ];

        foreach ($invalidos as $caso => $cuerpo) {
            $this->putJson('api/article-description-ai/revision/'.$articulo->id.'/publicada', $cuerpo)
                ->assertStatus(422)
                ->assertJsonStructure(['message', 'errors']);
        }
    }

    /**
     * Quitar borra las descripciones de IA (las humanas nunca), deja una propuesta descartada con esas
     * secciones (así el lote automático no la regenera) y devuelve los conteos. Sin descripciones de
     * IA, 404.
     *
     * @test
     * @group descripciones_ia
     */
    public function quitar_borra_lo_de_ia_y_deja_una_descartada()
    {
        $articulo = $this->crear_articulo('Serrucho');
        $this->sembrar_descripcion($articulo, 'Descripción', 'Serrucho de costilla', ['ai_confidence' => 'medium']);
        $this->sembrar_descripcion($articulo, 'Dientes', '7 TPI', ['ai_confidence' => 'medium']);
        $humana = $this->sembrar_descripcion_humana($articulo, 'Nota del dueño');

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/quitar')
            ->assertStatus(200)
            ->assertExactJson(['conteos' => ['a_revisar' => 0, 'publicadas' => 0]]);

        $this->assertSame([$humana->id], $this->descripciones_de($articulo)->pluck('id')->all());

        $descartada = ArticleDescriptionProposal::where('article_id', $articulo->id)->first();
        $this->assertNotNull($descartada);
        $this->assertSame('descartada', $descartada->status);
        $this->assertSame([
            ['title' => 'Descripción', 'content' => 'Serrucho de costilla'],
            ['title' => 'Dientes', 'content' => '7 TPI'],
        ], $descartada->sections);
        $this->assertSame('medium', $descartada->ai_confidence);
        $this->assertSame($this->owner->id, (int) $descartada->resolved_by);
        $this->assertNotNull($descartada->resolved_at);

        // Ya no tiene de IA.
        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/quitar')->assertStatus(404);

        // Y con overwrite=false el lote no se la vuelve a generar (además tiene la humana, pero la
        // descartada frena por sí sola en un artículo sin descripciones).
        $solo_ia = $this->crear_articulo('Solo IA');
        $this->sembrar_descripcion($solo_ia, 'Descripción', 'Algo');
        $this->postJson('api/article-description-ai/revision/'.$solo_ia->id.'/quitar')->assertStatus(200);
        $this->assertSame('propuesta_descartada', AiDescriptionReviewHelper::motivo_para_saltear($solo_ia->fresh(), false));
    }

    /**
     * 🔴 El título entra en `descriptions.title`, que es varchar(191): 192 caracteres es un 422 que
     * nombra el campo (antes pasaba la validación de 255 y reventaba en el INSERT), en aprobar
     * editada y en editar una publicada. 191 justos entran.
     *
     * @test
     * @group descripciones_ia
     */
    public function un_titulo_de_mas_de_191_caracteres_da_422()
    {
        $articulo  = $this->crear_articulo('Taladro');
        $propuesta = $this->sembrar_propuesta($articulo);
        $publicado = $this->crear_articulo('Martillo');
        $de_ia     = $this->sembrar_descripcion($publicado, 'Descripción', 'Martillo de carpintero');

        $largo = str_repeat('a', 192);

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar', [
            'secciones' => [['title' => $largo, 'content' => 'Texto']],
        ])->assertStatus(422)->assertJsonValidationErrors(['secciones.0.title']);

        $this->putJson('api/article-description-ai/revision/'.$publicado->id.'/publicada', [
            'secciones' => [['id' => $de_ia->id, 'title' => $largo, 'content' => 'Texto']],
        ])->assertStatus(422)->assertJsonValidationErrors(['secciones.0.title']);

        $this->assertSame('a_revisar', $this->propuesta_fresca($propuesta)->status);
        $this->assertSame('Descripción', Description::find($de_ia->id)->title);

        // 191 justos entran.
        $justo = str_repeat('b', 191);

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar', [
            'secciones' => [['title' => $justo, 'content' => 'Texto']],
        ])->assertStatus(200);
        $this->assertSame([$justo], $this->descripciones_de($articulo)->pluck('title')->all());

        $this->putJson('api/article-description-ai/revision/'.$publicado->id.'/publicada', [
            'secciones' => [['id' => $de_ia->id, 'title' => $justo, 'content' => 'Texto']],
        ])->assertStatus(200);
        $this->assertSame($justo, Description::find($de_ia->id)->title);
    }

    /**
     * Con Tienda Nube prendida, aprobar, editar una publicada y quitar le avisan; descartar no (no se
     * publicó nada).
     *
     * @test
     * @group descripciones_ia
     */
    public function tienda_nube_se_entera_de_lo_que_se_publica()
    {
        $originales = [
            'server' => array_key_exists('USA_TIENDA_NUBE', $_SERVER) ? $_SERVER['USA_TIENDA_NUBE'] : null,
            'env'    => array_key_exists('USA_TIENDA_NUBE', $_ENV) ? $_ENV['USA_TIENDA_NUBE'] : null,
        ];

        $_SERVER['USA_TIENDA_NUBE'] = 'true';
        $_ENV['USA_TIENDA_NUBE']    = 'true';

        try {
            Queue::fake();

            $aprobado = $this->crear_articulo('Aprobado');
            $this->sembrar_propuesta($aprobado);
            $descartado = $this->crear_articulo('Descartado');
            $this->sembrar_propuesta($descartado);
            $editado = $this->crear_articulo('Editado');
            $descripcion = $this->sembrar_descripcion($editado, 'D', 'd');
            $quitado = $this->crear_articulo('Quitado');
            $this->sembrar_descripcion($quitado, 'D', 'd');

            $this->postJson('api/article-description-ai/revision/'.$aprobado->id.'/aprobar')->assertStatus(200);
            $this->postJson('api/article-description-ai/revision/'.$descartado->id.'/descartar')->assertStatus(200);
            $this->putJson('api/article-description-ai/revision/'.$editado->id.'/publicada', [
                'secciones' => [['id' => $descripcion->id, 'title' => 'D2', 'content' => 'd2']],
            ])->assertStatus(200);
            $this->postJson('api/article-description-ai/revision/'.$quitado->id.'/quitar')->assertStatus(200);

            $sincronizados = [];

            Queue::assertPushed(ProcessSyncArticleDescriptionTiendaNube::class, function ($job) use (&$sincronizados) {
                // `article` es protected en el job: se lee desde adentro.
                $articulo = (function () {
                    return $this->article;
                })->call($job);

                $sincronizados[] = (int) $articulo->id;

                return true;
            });

            sort($sincronizados);
            $esperados = [$aprobado->id, $editado->id, $quitado->id];
            sort($esperados);

            $this->assertSame($esperados, $sincronizados);
        } finally {
            foreach (['server' => '_SERVER', 'env' => '_ENV'] as $clave => $global) {
                if (is_null($originales[$clave])) {
                    if ($global === '_SERVER') {
                        unset($_SERVER['USA_TIENDA_NUBE']);
                    } else {
                        unset($_ENV['USA_TIENDA_NUBE']);
                    }
                } elseif ($global === '_SERVER') {
                    $_SERVER['USA_TIENDA_NUBE'] = $originales[$clave];
                } else {
                    $_ENV['USA_TIENDA_NUBE'] = $originales[$clave];
                }
            }
        }
    }
}
