<?php

namespace Tests\Feature\DescripcionesIa;

use App\Events\ArticleBatchDescriptionsProcessed;
use App\Http\Controllers\Helpers\AiDescriptionReviewHelper;
use App\Jobs\ProcessArticleBatchDescriptionsJob;
use App\Models\ArticleDescriptionProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * El barrido de lo pendiente (misión alertas-descripciones, 14/10/2026, arreglo A1 del chequeo):
 * AiDescriptionReviewHelper::barrer_pendientes(), que corre al principio del resumen, de la lista y
 * del lote.
 *
 * Qué protege:
 *  - 🔴 Un lote que estaba corriendo durante el deploy termina con el código viejo y deja filas
 *    dudosas en `descriptions` DESPUÉS de la migración: el barrido las mueve a propuestas, así no
 *    quedan visibles en la tienda sin que nadie las apruebe.
 *  - Una propuesta a revisar de un artículo al que el dueño ya le escribió una descripción a mano
 *    queda descartada (sin persona): aprobarla se la duplicaría.
 *  - Es idempotente y no toca lo de otro comercio.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Barrido_de_pendientes_Test extends DescripcionesIaTestCase
{
    /**
     * Una fila dudosa sin revisar en `descriptions`, como la deja el lote viejo.
     *
     * @param  \App\Models\Article $articulo
     * @param  string $titulo
     * @param  string $creada
     * @return int
     */
    protected function fila_vieja($articulo, $titulo, $creada = '2026-10-14 20:00:00')
    {
        return (int) DB::table('descriptions')->insertGetId([
            'title'          => $titulo,
            'content'        => 'Texto de '.$titulo,
            'article_id'     => $articulo->id,
            'ai_generated'   => 1,
            'ai_confidence'  => 'low',
            'ai_sources'     => json_encode([['source' => 'google_name', 'url' => 'https://fuente.com/'.$titulo, 'title' => $titulo]]),
            'ai_reviewed_at' => null,
            'created_at'     => $creada,
            'updated_at'     => $creada,
        ]);
    }

    /**
     * (a) Lo que dejó un lote viejo pasa a propuesta al pedir el resumen (con la misma forma que la
     * migración), una sola vez; lo del vecino espera a su propio resumen.
     *
     * @test
     * @group descripciones_ia
     */
    public function el_resumen_mueve_lo_que_dejo_un_lote_viejo()
    {
        $articulo = $this->crear_articulo('Taladro del lote viejo');
        $primera  = $this->fila_vieja($articulo, 'Primera', '2026-10-14 20:05:00');
        $segunda  = $this->fila_vieja($articulo, 'Segunda', '2026-10-14 20:01:00');
        $alta     = $this->sembrar_descripcion($this->crear_articulo('Publicado'), 'Alta', 'Segura');

        $del_vecino = $this->crear_articulo('Del vecino', [], $this->vecino);
        $fila_del_vecino = $this->fila_vieja($del_vecino, 'Vecina');

        $this->getJson('api/article-description-ai/resumen')
            ->assertStatus(200)
            ->assertExactJson(['a_revisar' => 1, 'publicadas' => 1, 'badge' => 1]);

        $this->assertSame(0, DB::table('descriptions')->whereIn('id', [$primera, $segunda])->count(), 'Salieron de descriptions.');
        $this->assertSame(1, DB::table('descriptions')->where('id', $alta->id)->count(), 'Lo seguro no se toca.');

        $propuesta = ArticleDescriptionProposal::where('article_id', $articulo->id)->first();
        $this->assertSame('a_revisar', $propuesta->status);
        $this->assertSame($this->owner->id, (int) $propuesta->user_id);
        $this->assertSame([
            ['title' => 'Primera', 'content' => 'Texto de Primera'],
            ['title' => 'Segunda', 'content' => 'Texto de Segunda'],
        ], $propuesta->sections);
        $this->assertSame('https://fuente.com/Primera', $propuesta->ai_sources[0]['url']);
        $this->assertSame('2026-10-14 20:01:00', $propuesta->created_at->format('Y-m-d H:i:s'));

        // Lo del vecino sigue donde estaba.
        $this->assertSame(1, DB::table('descriptions')->where('id', $fila_del_vecino)->count());
        $this->assertSame(0, ArticleDescriptionProposal::where('article_id', $del_vecino->id)->count());

        // Idempotente.
        $this->getJson('api/article-description-ai/resumen')->assertJsonPath('a_revisar', 1);
        $this->assertSame(1, ArticleDescriptionProposal::where('article_id', $articulo->id)->count());
        $this->assertSame(['movidas' => 0, 'descartadas' => 0], AiDescriptionReviewHelper::barrer_pendientes($this->owner->id));
    }

    /**
     * (a) también al listar: la lista ya trae el artículo que dejó el lote viejo.
     *
     * @test
     * @group descripciones_ia
     */
    public function la_lista_tambien_barre()
    {
        $articulo = $this->crear_articulo('Sierra del lote viejo');
        $this->fila_vieja($articulo, 'Unica');

        $respuesta = $this->getJson('api/article-description-ai/revision');

        $respuesta->assertStatus(200);
        $this->assertSame([$articulo->id], array_column($respuesta->json('models'), 'article_id'));
        $this->assertSame([['id' => null, 'title' => 'Unica', 'content' => 'Texto de Unica']], $respuesta->json('models.0.secciones'));
    }

    /**
     * (b) Una propuesta a revisar de un artículo que ya tiene descripción escrita a mano queda
     * descartada, sin persona; las de artículos sin humana siguen a revisar.
     *
     * @test
     * @group descripciones_ia
     */
    public function descarta_la_propuesta_si_el_dueno_ya_escribio_una_a_mano()
    {
        $con_humana = $this->crear_articulo('Con descripción a mano');
        $propuesta  = $this->sembrar_propuesta($con_humana);
        $this->sembrar_descripcion_humana($con_humana, 'La escribió el dueño');

        $sin_humana = $this->crear_articulo('Sin descripción');
        $sigue      = $this->sembrar_propuesta($sin_humana);

        $this->getJson('api/article-description-ai/resumen')
            ->assertStatus(200)
            ->assertJsonPath('a_revisar', 1);

        $propuesta = $this->propuesta_fresca($propuesta);
        $this->assertSame('descartada', $propuesta->status);
        $this->assertNotNull($propuesta->resolved_at);
        $this->assertNull($propuesta->resolved_by, 'Nadie la descartó: la descartó el barrido.');

        $this->assertSame('a_revisar', $this->propuesta_fresca($sigue)->status);
    }

    /**
     * El lote barre a su dueño al arrancar, aunque no le toque ningún artículo con filas viejas.
     *
     * @test
     * @group descripciones_ia
     */
    public function el_lote_barre_a_su_dueno_al_arrancar()
    {
        Event::fake([ArticleBatchDescriptionsProcessed::class]);

        $articulo = $this->crear_articulo('Pinza del lote viejo');
        $fila     = $this->fila_vieja($articulo, 'Vieja');

        $job = new ProcessArticleBatchDescriptionsJob([], $this->owner->id, 'clave-de-prueba', 'cx-de-prueba', 10, false, 'uuid-barrido');
        $job->handle();

        $this->assertSame(0, DB::table('descriptions')->where('id', $fila)->count());
        $this->assertSame(1, ArticleDescriptionProposal::aRevisar()->where('article_id', $articulo->id)->count());
    }
}
