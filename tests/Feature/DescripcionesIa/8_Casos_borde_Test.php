<?php

namespace Tests\Feature\DescripcionesIa;

use App\Http\Controllers\Helpers\AiDescriptionReviewHelper;
use App\Models\ArticleDescriptionProposal;
use Illuminate\Support\Facades\DB;

/**
 * Casos borde que el resto de los tests no forzaba y que darían verde con el código mal (misión
 * alertas-descripciones, 14/10/2026, arreglo A8 del chequeo):
 *
 *  - Dos propuestas a revisar del MISMO artículo (dos lotes a la vez): la lista y el conteo muestran
 *    una (la más nueva), y resolverla resuelve las dos.
 *  - Un artículo en la papelera con descripción de IA no aparece en Publicadas ni en el conteo, y no
 *    se puede editar ni quitar.
 *  - La migración de datos y el barrido recorriendo MÁS de una tanda.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Casos_borde_Test extends DescripcionesIaTestCase
{
    /**
     * Dos propuestas a revisar del mismo artículo, sembradas directo: la vieja y la nueva.
     *
     * @param  \App\Models\Article $articulo
     * @return array  [vieja, nueva]
     */
    protected function dos_a_revisar($articulo)
    {
        $vieja = $this->sembrar_propuesta($articulo, [['Vieja', 'Texto de la vieja']], ['created_at' => '2026-10-10 10:00:00']);
        $nueva = $this->sembrar_propuesta($articulo, [['Nueva', 'Texto de la nueva']], ['created_at' => '2026-10-12 10:00:00']);

        return [$vieja, $nueva];
    }

    /**
     * @test
     * @group descripciones_ia
     */
    public function dos_a_revisar_del_mismo_articulo_se_ven_como_una_y_se_resuelven_juntas()
    {
        $articulo = $this->crear_articulo('Taladro de dos lotes');
        list($vieja, $nueva) = $this->dos_a_revisar($articulo);

        $this->getJson('api/article-description-ai/resumen')->assertJsonPath('a_revisar', 1);

        $lista = $this->getJson('api/article-description-ai/revision');
        $this->assertSame(1, $lista->json('total'));
        $this->assertSame([$articulo->id], array_column($lista->json('models'), 'article_id'));
        $this->assertSame('Nueva', $lista->json('models.0.secciones.0.title'), 'La más nueva.');

        $this->postJson('api/article-description-ai/revision/'.$articulo->id.'/aprobar')
            ->assertStatus(200)
            ->assertJsonPath('conteos.a_revisar', 0);

        $this->assertSame('aprobada', $this->propuesta_fresca($nueva)->status, 'Se aprueba la más nueva.');
        $this->assertSame('descartada', $this->propuesta_fresca($vieja)->status, 'La otra no queda colgada.');
        $this->assertSame(['Nueva'], $this->descripciones_de($articulo)->pluck('title')->all(), 'Una sola vez, con lo de la nueva.');

        // Descartar también resuelve las dos.
        $otro = $this->crear_articulo('Sierra de dos lotes');
        list($otra_vieja, $otra_nueva) = $this->dos_a_revisar($otro);

        $this->postJson('api/article-description-ai/revision/'.$otro->id.'/descartar')->assertStatus(200);

        $this->assertSame('descartada', $this->propuesta_fresca($otra_vieja)->status);
        $this->assertSame('descartada', $this->propuesta_fresca($otra_nueva)->status);
        $this->getJson('api/article-description-ai/resumen')->assertJsonPath('a_revisar', 0);
    }

    /**
     * @test
     * @group descripciones_ia
     */
    public function un_articulo_en_la_papelera_no_esta_en_publicadas()
    {
        $vivo = $this->crear_articulo('Vivo');
        $this->sembrar_descripcion($vivo, 'D', 'Del vivo');

        $borrado     = $this->crear_articulo('Borrado');
        $descripcion = $this->sembrar_descripcion($borrado, 'D', 'Del borrado');
        $borrado->delete();

        $this->getJson('api/article-description-ai/resumen')->assertJsonPath('publicadas', 1);

        $lista = $this->getJson('api/article-description-ai/revision?solapa=publicadas');
        $this->assertSame(1, $lista->json('total'));
        $this->assertSame([$vivo->id], array_column($lista->json('models'), 'article_id'));

        $this->putJson('api/article-description-ai/revision/'.$borrado->id.'/publicada', [
            'secciones' => [['id' => $descripcion->id, 'title' => 'X', 'content' => 'X']],
        ])->assertStatus(404);
        $this->postJson('api/article-description-ai/revision/'.$borrado->id.'/quitar')->assertStatus(404);

        $this->assertSame('Del borrado', DB::table('descriptions')->where('id', $descripcion->id)->value('content'));
    }

    /**
     * Artículos del dueño insertados directo (rápido), cada uno con una fila dudosa sin revisar.
     *
     * @param  int $cantidad
     * @return array  ids de los artículos
     */
    protected function articulos_con_filas_dudosas($cantidad)
    {
        $ahora = '2026-10-14 12:00:00';
        $ids   = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $id = (int) DB::table('articles')->insertGetId([
                'name'       => 'Masivo '.$i,
                'user_id'    => $this->owner->id,
                'status'     => 'active',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            $ids[] = $id;
        }

        $filas = [];

        foreach ($ids as $id) {
            $filas[] = [
                'title'          => 'Dudosa',
                'content'        => 'Texto dudoso',
                'article_id'     => $id,
                'ai_generated'   => 1,
                'ai_confidence'  => 'low',
                'ai_sources'     => json_encode([]),
                'ai_reviewed_at' => null,
                'created_at'     => $ahora,
                'updated_at'     => $ahora,
            ];
        }

        foreach (array_chunk($filas, 100) as $tanda) {
            DB::table('descriptions')->insert($tanda);
        }

        return $ids;
    }

    /**
     * La migración de datos recorre más de una tanda (TANDA = 200) sin saltearse ni repetir nada.
     *
     * @test
     * @group descripciones_ia
     */
    public function la_migracion_recorre_mas_de_una_tanda()
    {
        require_once database_path('migrations/2026_10_14_180100_mover_descripciones_ia_pendientes_a_propuestas.php');

        $cantidad = \MoverDescripcionesIaPendientesAPropuestas::TANDA * 2 + 5;
        $ids      = $this->articulos_con_filas_dudosas($cantidad);

        (new \MoverDescripcionesIaPendientesAPropuestas())->up();

        $this->assertSame(0, DB::table('descriptions')->whereIn('article_id', $ids)->count());
        $this->assertSame($cantidad, ArticleDescriptionProposal::aRevisar()->whereIn('article_id', $ids)->count());
        $this->assertSame($cantidad, (int) ArticleDescriptionProposal::whereIn('article_id', $ids)->distinct()->count('article_id'), 'Una por artículo.');
    }

    /**
     * El barrido también recorre más de una tanda (TANDA_DEL_BARRIDO = 200).
     *
     * @test
     * @group descripciones_ia
     */
    public function el_barrido_recorre_mas_de_una_tanda()
    {
        $cantidad = AiDescriptionReviewHelper::TANDA_DEL_BARRIDO * 2 + 5;
        $ids      = $this->articulos_con_filas_dudosas($cantidad);

        $resultado = AiDescriptionReviewHelper::barrer_pendientes($this->owner->id);

        $this->assertSame($cantidad, $resultado['movidas']);
        $this->assertSame(0, DB::table('descriptions')->whereIn('article_id', $ids)->count());
        $this->assertSame($cantidad, ArticleDescriptionProposal::aRevisar()->whereIn('article_id', $ids)->count());
        $this->getJson('api/article-description-ai/resumen')->assertJsonPath('a_revisar', $cantidad);
    }
}
