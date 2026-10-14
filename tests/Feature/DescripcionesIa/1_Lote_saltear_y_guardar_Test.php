<?php

namespace Tests\Feature\DescripcionesIa;

use App\Events\ArticleBatchDescriptionsProcessed;
use App\Http\Controllers\Helpers\AiDescriptionReviewHelper;
use App\Jobs\ProcessArticleBatchDescriptionsJob;
use App\Models\ArticleDescriptionProposal;
use App\Models\Description;
use Illuminate\Support\Facades\Event;

/**
 * Lo que decide el lote de descripciones automáticas (misión alertas-descripciones, 14/10/2026, plan
 * §1.3): a qué artículo no se le genera nada y cómo se guarda lo que generó la IA.
 *
 * Qué protege:
 *  - 🔴 Lo dudoso (`low`) NO crea filas en `descriptions`: queda como propuesta `a_revisar`. Ahí la
 *    tienda propia y la ficha del ERP no la ven (decisión D1).
 *  - Lo seguro se publica como siempre.
 *  - Con overwrite se borra lo de IA y la propuesta a revisar, nunca lo humano; lo resuelto queda.
 *  - En modo automático, lo descartado no se vuelve a proponer.
 *
 * Las decisiones están en AiDescriptionReviewHelper porque el job instancia la búsqueda y la IA con
 * `new`; el último test corre el job de verdad con artículos que se saltean todos (no sale a internet).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Lote_saltear_y_guardar_Test extends DescripcionesIaTestCase
{
    /**
     * Un resultado de ArticleDescriptionAiService::generate() con found=true.
     *
     * @param  string $confianza
     * @return array
     */
    protected function resultado($confianza)
    {
        return [
            'found'      => true,
            'sections'   => [
                ['title' => 'Descripción del producto', 'content' => 'Taladro percutor de 550 W con mandril de 13 mm.'],
                ['title' => 'Características', 'content' => 'Velocidad variable y reversa.'],
            ],
            'confidence' => $confianza,
            'reason'     => null,
            'sources'    => [['source' => 'google_name', 'url' => 'https://www.ferreteria.com.ar/taladro', 'title' => 'Taladro 550']],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // motivo_para_saltear
    // ---------------------------------------------------------------------------------------------

    /**
     * @test
     * @group descripciones_ia
     */
    public function con_descripcion_humana_se_saltea_en_los_dos_modos()
    {
        $articulo = $this->crear_articulo('Martillo');
        $this->sembrar_descripcion_humana($articulo);

        $this->assertSame('descripcion_humana', AiDescriptionReviewHelper::motivo_para_saltear($articulo, false));
        $this->assertSame('descripcion_humana', AiDescriptionReviewHelper::motivo_para_saltear($articulo, true));
    }

    /**
     * @test
     * @group descripciones_ia
     */
    public function con_descripcion_de_ia_se_saltea_solo_en_automatico()
    {
        $articulo = $this->crear_articulo('Pinza');
        $this->sembrar_descripcion($articulo, 'Descripción', 'Pinza universal');

        $this->assertSame('ya_tiene_descripcion', AiDescriptionReviewHelper::motivo_para_saltear($articulo, false));
        $this->assertNull(AiDescriptionReviewHelper::motivo_para_saltear($articulo, true));
    }

    /**
     * 🔴 Una propuesta a revisar o DESCARTADA frena el modo automático: lo que el dueño descartó, la IA
     * no se lo vuelve a proponer. En modo reemplazar no frena.
     *
     * @test
     * @group descripciones_ia
     */
    public function una_propuesta_a_revisar_o_descartada_frena_solo_el_automatico()
    {
        $a_revisar = $this->crear_articulo('Destornillador');
        $this->sembrar_propuesta($a_revisar);

        $descartada = $this->crear_articulo('Llave francesa');
        $this->sembrar_propuesta($descartada, [], ['status' => ArticleDescriptionProposal::STATUS_DESCARTADA]);

        $this->assertSame('propuesta_a_revisar', AiDescriptionReviewHelper::motivo_para_saltear($a_revisar, false));
        $this->assertSame('propuesta_descartada', AiDescriptionReviewHelper::motivo_para_saltear($descartada, false));

        $this->assertNull(AiDescriptionReviewHelper::motivo_para_saltear($a_revisar, true));
        $this->assertNull(AiDescriptionReviewHelper::motivo_para_saltear($descartada, true));
    }

    /**
     * 🔴 Reemplazar no pisa lo que tocó una persona: con una descripción de IA aprobada o corregida
     * (ai_reviewed_at no nulo) el artículo se saltea también con overwrite. Una de IA sin revisar no
     * frena el overwrite.
     *
     * @test
     * @group descripciones_ia
     */
    public function reemplazar_no_pisa_una_descripcion_de_ia_revisada_por_una_persona()
    {
        $revisada = $this->crear_articulo('Con revisada');
        $this->sembrar_descripcion($revisada, 'Descripción', 'Aprobada', ['ai_reviewed_at' => '2026-10-10 10:00:00']);

        $sin_revisar = $this->crear_articulo('Sin revisar');
        $this->sembrar_descripcion($sin_revisar, 'Descripción', 'Publicada sola');

        $this->assertSame('descripcion_revisada', AiDescriptionReviewHelper::motivo_para_saltear($revisada, true));
        $this->assertSame('ya_tiene_descripcion', AiDescriptionReviewHelper::motivo_para_saltear($revisada, false));
        $this->assertNull(AiDescriptionReviewHelper::motivo_para_saltear($sin_revisar, true));
    }

    /**
     * Sin nada (o con una propuesta ya aprobada y sin descripciones: alguien las borró a mano), se le
     * genera.
     *
     * @test
     * @group descripciones_ia
     */
    public function sin_nada_que_lo_frene_se_le_genera()
    {
        $limpio = $this->crear_articulo('Cinta métrica');
        $this->assertNull(AiDescriptionReviewHelper::motivo_para_saltear($limpio, false));

        $aprobada = $this->crear_articulo('Nivel de burbuja');
        $this->sembrar_propuesta($aprobada, [], ['status' => ArticleDescriptionProposal::STATUS_APROBADA]);
        $this->assertNull(AiDescriptionReviewHelper::motivo_para_saltear($aprobada, false));
    }

    // ---------------------------------------------------------------------------------------------
    // guardar_resultado
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Confianza baja: una propuesta `a_revisar` con las secciones, la confianza y las fuentes, del
     * dueño del artículo, y NINGUNA fila en `descriptions`.
     *
     * @test
     * @group descripciones_ia
     */
    public function lo_dudoso_queda_como_propuesta_y_no_crea_descripciones()
    {
        $articulo = $this->crear_articulo('Taladro percutor 550 W');

        $guardado = AiDescriptionReviewHelper::guardar_resultado($articulo, $this->resultado('low'), false);

        $this->assertSame('a_revisar', $guardado['tipo']);
        $this->assertSame([], $guardado['description_ids']);
        $this->assertSame('Taladro percutor de 550 W con mandril de 13 mm.', $guardado['primer_texto']);

        $this->assertCount(0, $this->descripciones_de($articulo), 'Nada en descriptions: no lo ve la tienda ni la ficha.');

        $propuesta = ArticleDescriptionProposal::find($guardado['proposal_id']);
        $this->assertNotNull($propuesta);
        $this->assertSame('a_revisar', $propuesta->status);
        $this->assertSame($this->owner->id, (int) $propuesta->user_id);
        $this->assertSame($articulo->id, (int) $propuesta->article_id);
        $this->assertSame('low', $propuesta->ai_confidence);
        $this->assertSame([
            ['title' => 'Descripción del producto', 'content' => 'Taladro percutor de 550 W con mandril de 13 mm.'],
            ['title' => 'Características', 'content' => 'Velocidad variable y reversa.'],
        ], $propuesta->sections);
        $this->assertSame('https://www.ferreteria.com.ar/taladro', $propuesta->ai_sources[0]['url']);
        $this->assertNull($propuesta->resolved_at);
    }

    /**
     * Confianza alta o media: descripciones publicadas como siempre (IA, sin revisar) y ninguna
     * propuesta.
     *
     * @test
     * @group descripciones_ia
     */
    public function lo_seguro_se_publica_como_descripciones()
    {
        foreach (['high', 'medium'] as $confianza) {
            $articulo = $this->crear_articulo('Amoladora '.$confianza);

            $guardado = AiDescriptionReviewHelper::guardar_resultado($articulo, $this->resultado($confianza), false);

            $this->assertSame('publicada', $guardado['tipo']);
            $this->assertNull($guardado['proposal_id']);

            $descripciones = $this->descripciones_de($articulo);
            $this->assertSame($descripciones->pluck('id')->all(), $guardado['description_ids']);
            $this->assertSame(['Descripción del producto', 'Características'], $descripciones->pluck('title')->all());

            foreach ($descripciones as $descripcion) {
                $this->assertTrue($descripcion->ai_generated);
                $this->assertSame($confianza, $descripcion->ai_confidence);
                $this->assertNull($descripcion->ai_reviewed_at);
                $this->assertSame('https://www.ferreteria.com.ar/taladro', $descripcion->ai_sources[0]['url']);
            }

            $this->assertSame(0, ArticleDescriptionProposal::where('article_id', $articulo->id)->count());
        }
    }

    /**
     * Con overwrite y un resultado seguro se borran las descripciones de IA que nadie revisó y la
     * propuesta a revisar; las humanas y las revisadas nunca, y las propuestas ya resueltas quedan
     * como historia.
     *
     * (Hasta el arreglo A4 del chequeo, este test pedía además que un resultado DUDOSO con overwrite
     * borrara lo publicado; eso cambió a propósito: ver
     * reemplazar_con_un_resultado_dudoso_no_despublica_y_aprobar_lo_reemplaza.)
     *
     * @test
     * @group descripciones_ia
     */
    public function overwrite_borra_lo_de_ia_sin_revisar_y_lo_pendiente_pero_nunca_lo_humano()
    {
        $articulo = $this->crear_articulo('Sierra circular');

        $humana     = $this->sembrar_descripcion_humana($articulo, 'La escribió el dueño');
        $de_ia      = $this->sembrar_descripcion($articulo, 'Vieja de IA', 'Texto viejo');
        $revisada   = $this->sembrar_descripcion($articulo, 'Revisada', 'La aprobó una persona', ['ai_reviewed_at' => '2026-10-10 10:00:00']);
        $pendiente  = $this->sembrar_propuesta($articulo);
        $descartada = $this->sembrar_propuesta($articulo, [], ['status' => ArticleDescriptionProposal::STATUS_DESCARTADA]);

        AiDescriptionReviewHelper::guardar_resultado($articulo, $this->resultado('high'), true);

        $this->assertNotNull(Description::find($humana->id), 'La humana no se toca.');
        $this->assertNotNull(Description::find($revisada->id), 'La revisada no se toca.');
        $this->assertNull(Description::find($de_ia->id), 'La de IA sin revisar se borró.');
        $this->assertNull($this->propuesta_fresca($pendiente), 'La pendiente quedó reemplazada.');
        $this->assertSame('descartada', $this->propuesta_fresca($descartada)->status, 'Lo resuelto es historia.');
        $this->assertSame(4, $this->descripciones_de($articulo)->count(), 'La humana + la revisada + las dos secciones nuevas.');
    }

    /**
     * 🔴 Reemplazar con un resultado DUDOSO no despublica: la descripción de IA que se ve sigue a la
     * vista y solo se reemplaza la propuesta a revisar. Al aprobar la propuesta, lo aprobado
     * reemplaza a lo que la IA había publicado sola; lo revisado por una persona queda.
     *
     * @test
     * @group descripciones_ia
     */
    public function reemplazar_con_un_resultado_dudoso_no_despublica_y_aprobar_lo_reemplaza()
    {
        $articulo = $this->crear_articulo('Amoladora angular');

        $publicada = $this->sembrar_descripcion($articulo, 'Publicada', 'La publicó la IA sola', ['ai_confidence' => 'high']);
        $revisada  = $this->sembrar_descripcion($articulo, 'Revisada', 'La corrigió una persona', ['ai_reviewed_at' => '2026-10-10 10:00:00']);
        $vieja     = $this->sembrar_propuesta($articulo);

        $guardado = AiDescriptionReviewHelper::guardar_resultado($articulo, $this->resultado('low'), true);

        $this->assertSame('a_revisar', $guardado['tipo']);
        $this->assertNotNull(Description::find($publicada->id), 'Lo publicado sigue a la vista.');
        $this->assertNotNull(Description::find($revisada->id));
        $this->assertNull($this->propuesta_fresca($vieja), 'La propuesta vieja quedó reemplazada.');
        $this->assertSame([$guardado['proposal_id']], ArticleDescriptionProposal::aRevisar()->where('article_id', $articulo->id)->pluck('id')->all());

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar')->assertStatus(200);

        $this->assertNull(Description::find($publicada->id), 'Lo aprobado reemplaza a lo publicado solo.');
        $this->assertNotNull(Description::find($revisada->id), 'Lo revisado por una persona queda.');

        $nuevas = Description::where('article_id', $articulo->id)->where('id', '<>', $revisada->id)->orderBy('id')->get();
        $this->assertSame(['Descripción del producto', 'Características'], $nuevas->pluck('title')->all());

        foreach ($nuevas as $nueva) {
            $this->assertNotNull($nueva->ai_reviewed_at);
        }
    }

    /**
     * Una propuesta nueva reemplaza a la que estaba a revisar: nunca quedan dos a revisar del mismo
     * artículo (dos lotes a la vez sobre el mismo artículo).
     *
     * @test
     * @group descripciones_ia
     */
    public function nunca_quedan_dos_propuestas_a_revisar_del_mismo_articulo()
    {
        $articulo = $this->crear_articulo('Lijadora');
        $vieja    = $this->sembrar_propuesta($articulo);

        $guardado = AiDescriptionReviewHelper::guardar_resultado($articulo, $this->resultado('low'), false);

        $this->assertNull($this->propuesta_fresca($vieja));
        $this->assertSame([$guardado['proposal_id']], ArticleDescriptionProposal::aRevisar()->where('article_id', $articulo->id)->pluck('id')->all());
    }

    // ---------------------------------------------------------------------------------------------
    // El job
    // ---------------------------------------------------------------------------------------------

    /**
     * El job usa motivo_para_saltear(): en modo automático no le genera nada a un artículo con una
     * propuesta a revisar, una descartada o una descripción humana. Como se saltean todos, no se
     * busca nada afuera y el resumen los cuenta como "ya tenían".
     *
     * @test
     * @group descripciones_ia
     */
    public function el_lote_automatico_no_vuelve_a_proponer_lo_descartado()
    {
        Event::fake([ArticleBatchDescriptionsProcessed::class]);

        $a_revisar  = $this->crear_articulo('Con propuesta a revisar');
        $descartada = $this->crear_articulo('Con propuesta descartada');
        $humana     = $this->crear_articulo('Con descripción humana');

        $this->sembrar_propuesta($a_revisar);
        $this->sembrar_propuesta($descartada, [], ['status' => ArticleDescriptionProposal::STATUS_DESCARTADA]);
        $this->sembrar_descripcion_humana($humana);

        $job = new ProcessArticleBatchDescriptionsJob(
            [$a_revisar->id, $descartada->id, $humana->id],
            $this->owner->id,
            'clave-de-prueba',
            'cx-de-prueba',
            10,
            false,
            'uuid-de-prueba'
        );

        $job->handle();

        Event::assertDispatched(ArticleBatchDescriptionsProcessed::class, function ($evento) {
            return $evento->batch_uuid === 'uuid-de-prueba'
                && $evento->skipped_existing === 3
                && $evento->processed === 0
                && $evento->needs_review === 0;
        });

        $this->assertSame(1, ArticleDescriptionProposal::aRevisar()->where('article_id', $a_revisar->id)->count());
        $this->assertSame(0, ArticleDescriptionProposal::aRevisar()->where('article_id', $descartada->id)->count());
    }
}
