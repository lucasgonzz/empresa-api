<?php

namespace Tests\Feature\DescripcionesIa;

use App\Models\ArticleDescriptionProposal;
use Illuminate\Support\Facades\DB;

/**
 * La migración de datos que saca de `descriptions` lo dudoso que nadie revisó (misión
 * alertas-descripciones, 14/10/2026, plan §1.2). Corre sola con el deploy en toda la flota: lo que
 * hoy se ve en la tienda de cada cliente sin que nadie lo haya aprobado deja de verse y le aparece al
 * dueño "a revisar".
 *
 * Se corre su up()/down() a mano (require + new) sobre filas sembradas en la base del test; la
 * transacción del test lo revierte todo.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Migracion_de_pendientes_Test extends DescripcionesIaTestCase
{
    /**
     * La migración, instanciada.
     *
     * @return object
     */
    protected function migracion()
    {
        require_once database_path('migrations/2026_10_14_180100_mover_descripciones_ia_pendientes_a_propuestas.php');

        return new \MoverDescripcionesIaPendientesAPropuestas();
    }

    /**
     * Una fila de `descriptions` sembrada directo (como las dejaba el lote viejo).
     *
     * @param  int    $article_id
     * @param  string $titulo
     * @param  string $confianza
     * @param  array  $extra
     * @return int
     */
    protected function fila($article_id, $titulo, $confianza = 'low', array $extra = [])
    {
        return (int) DB::table('descriptions')->insertGetId(array_merge([
            'title'          => $titulo,
            'content'        => 'Texto de '.$titulo,
            'article_id'     => $article_id,
            'ai_generated'   => 1,
            'ai_confidence'  => $confianza,
            'ai_sources'     => json_encode([['source' => 'google_name', 'url' => 'https://fuente.com/'.$titulo, 'title' => $titulo]]),
            'ai_reviewed_at' => null,
            'created_at'     => '2026-10-01 10:00:00',
            'updated_at'     => '2026-10-01 10:00:00',
        ], $extra));
    }

    /**
     * 🔴 Lo dudoso sin revisar pasa a UNA propuesta por artículo (secciones por id, fuentes y
     * confianza de la primera, fecha de la más vieja) y sale de `descriptions`. Lo seguro, lo
     * revisado, lo humano y lo de un artículo que ya no existe no se tocan; lo de un artículo en la
     * papelera también se mueve.
     *
     * @test
     * @group descripciones_ia
     */
    public function mueve_lo_dudoso_sin_revisar_y_no_toca_nada_mas()
    {
        $con_dudas   = $this->crear_articulo('Taladro con dudas');
        $revisado    = $this->crear_articulo('Taladro revisado');
        $seguro      = $this->crear_articulo('Taladro seguro');
        $en_papelera = $this->crear_articulo('Taladro en la papelera', [], $this->vecino);

        // Del artículo con dudas: dos secciones dudosas (la segunda más vieja) y una humana.
        $primera = $this->fila($con_dudas->id, 'Primera', 'low', ['created_at' => '2026-10-02 12:00:00']);
        $segunda = $this->fila($con_dudas->id, 'Segunda', 'low', ['created_at' => '2026-10-01 08:30:00']);
        $humana  = $this->fila($con_dudas->id, 'Humana', null, ['ai_generated' => 0, 'ai_sources' => null]);

        $ya_revisada = $this->fila($revisado->id, 'Revisada', 'low', ['ai_reviewed_at' => '2026-10-03 09:00:00']);
        $alta        = $this->fila($seguro->id, 'Alta', 'high');
        $media       = $this->fila($seguro->id, 'Media', 'medium');

        $de_papelera = $this->fila($en_papelera->id, 'Papelera', 'low');
        $en_papelera->delete();

        $huerfana = $this->fila(2000000000, 'Huerfana', 'low');

        $this->migracion()->up();

        // El artículo con dudas: una propuesta con sus dos secciones en orden de id.
        $propuestas = ArticleDescriptionProposal::where('article_id', $con_dudas->id)->get();
        $this->assertCount(1, $propuestas);

        $propuesta = $propuestas->first();
        $this->assertSame('a_revisar', $propuesta->status);
        $this->assertSame($this->owner->id, (int) $propuesta->user_id);
        $this->assertSame([
            ['title' => 'Primera', 'content' => 'Texto de Primera'],
            ['title' => 'Segunda', 'content' => 'Texto de Segunda'],
        ], $propuesta->sections);
        $this->assertSame('low', $propuesta->ai_confidence);
        $this->assertSame('https://fuente.com/Primera', $propuesta->ai_sources[0]['url'], 'Las fuentes de la primera.');
        $this->assertSame('2026-10-01 08:30:00', $propuesta->created_at->format('Y-m-d H:i:s'), 'La fecha de la más vieja.');

        $this->assertSame(0, DB::table('descriptions')->whereIn('id', [$primera, $segunda])->count(), 'Salieron de descriptions.');
        $this->assertSame(1, DB::table('descriptions')->where('id', $humana)->count(), 'La humana no se toca.');

        // Lo que no se toca.
        $this->assertSame(3, DB::table('descriptions')->whereIn('id', [$ya_revisada, $alta, $media])->count());
        $this->assertSame(0, ArticleDescriptionProposal::whereIn('article_id', [$revisado->id, $seguro->id])->count());
        $this->assertSame(1, DB::table('descriptions')->where('id', $huerfana)->count(), 'Sin artículo no hay dueño: queda como está.');

        // El de la papelera se mueve, con su dueño (el vecino).
        $this->assertSame(0, DB::table('descriptions')->where('id', $de_papelera)->count());
        $de_vecino = ArticleDescriptionProposal::where('article_id', $en_papelera->id)->first();
        $this->assertNotNull($de_vecino);
        $this->assertSame($this->vecino->id, (int) $de_vecino->user_id);
    }

    /**
     * Correrla dos veces no duplica nada: la segunda no encuentra pendientes.
     *
     * @test
     * @group descripciones_ia
     */
    public function correrla_dos_veces_no_duplica()
    {
        $articulo = $this->crear_articulo('Amoladora');
        $this->fila($articulo->id, 'Unica');

        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertSame(1, ArticleDescriptionProposal::where('article_id', $articulo->id)->count());
    }

    /**
     * El camino inverso: las propuestas a revisar vuelven a `descriptions` como las dejaba el lote
     * viejo (IA, su confianza, sin revisar) y se borran; las resueltas quedan.
     *
     * @test
     * @group descripciones_ia
     */
    public function down_devuelve_las_pendientes_a_descriptions()
    {
        $articulo = $this->crear_articulo('Sierra');
        $this->fila($articulo->id, 'Uno');
        $this->fila($articulo->id, 'Dos');

        $otro       = $this->crear_articulo('Lijadora');
        $descartada = $this->sembrar_propuesta($otro, [], ['status' => ArticleDescriptionProposal::STATUS_DESCARTADA]);

        $migracion = $this->migracion();
        $migracion->up();
        $this->assertSame(0, DB::table('descriptions')->where('article_id', $articulo->id)->count());

        $migracion->down();

        $filas = DB::table('descriptions')->where('article_id', $articulo->id)->orderBy('id')->get();
        $this->assertSame(['Uno', 'Dos'], $filas->pluck('title')->all());

        foreach ($filas as $fila) {
            $this->assertSame(1, (int) $fila->ai_generated);
            $this->assertSame('low', $fila->ai_confidence);
            $this->assertNull($fila->ai_reviewed_at);
            $this->assertSame('https://fuente.com/Uno', json_decode($fila->ai_sources, true)[0]['url']);
        }

        $this->assertSame(0, ArticleDescriptionProposal::where('article_id', $articulo->id)->count());
        $this->assertNotNull($this->propuesta_fresca($descartada), 'Lo resuelto no se toca.');
    }
}
