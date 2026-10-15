<?php

namespace Tests\Feature\DescripcionesIa;

use App\Models\ArticleDescriptionProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El esquema de `article_description_proposals` y el modelo (misión alertas-descripciones,
 * 14/10/2026, plan §1.1). Protege lo que el resto del código da por sentado: sin esto, renombrar una
 * columna rompe el lote, la revisión y la migración de datos sin que nada lo denuncie.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Esquema_Test extends DescripcionesIaTestCase
{
    /**
     * @test
     * @group descripciones_ia
     */
    public function la_tabla_tiene_las_columnas_del_plan()
    {
        $this->assertTrue(Schema::hasTable('article_description_proposals'));

        $columnas = [
            'id', 'user_id', 'article_id', 'sections', 'ai_confidence', 'ai_sources', 'status',
            'resolved_at', 'resolved_by', 'created_at', 'updated_at',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(Schema::hasColumn('article_description_proposals', $columna), "Falta article_description_proposals.{$columna}.");
        }

        // Los dos índices compuestos que usan el número rojo y el lote.
        $indices = [];

        foreach (DB::select('SHOW INDEX FROM article_description_proposals') as $fila) {
            $indices[$fila->Key_name][] = $fila->Column_name;
        }

        $this->assertSame(['user_id', 'status'], $indices['adp_user_status_idx']);
        $this->assertSame(['article_id', 'status'], $indices['adp_article_status_idx']);
    }

    /**
     * Un alta sin `status` nace `a_revisar`; `sections` y `ai_sources` van y vuelven como arreglos;
     * `resolved_at` es fecha; el scope `aRevisar()` filtra por estado.
     *
     * @test
     * @group descripciones_ia
     */
    public function el_modelo_castea_y_filtra()
    {
        $articulo = $this->crear_articulo('Taladro percutor 550 W');

        $propuesta = ArticleDescriptionProposal::create([
            'user_id'    => $this->owner->id,
            'article_id' => $articulo->id,
            'sections'   => [['title' => 'Descripción', 'content' => 'Taladro con mandril de 13 mm']],
            'ai_sources' => [['source' => 'google_name', 'url' => 'https://ejemplo.com', 'title' => 'Ejemplo']],
        ])->fresh();

        $this->assertSame(ArticleDescriptionProposal::STATUS_A_REVISAR, $propuesta->status, 'El default de la columna.');
        $this->assertSame([['title' => 'Descripción', 'content' => 'Taladro con mandril de 13 mm']], $propuesta->sections);
        $this->assertSame('https://ejemplo.com', $propuesta->ai_sources[0]['url']);
        $this->assertSame($articulo->id, $propuesta->article->id);

        $this->assertSame('a_revisar', ArticleDescriptionProposal::STATUS_A_REVISAR);
        $this->assertSame('aprobada', ArticleDescriptionProposal::STATUS_APROBADA);
        $this->assertSame('descartada', ArticleDescriptionProposal::STATUS_DESCARTADA);

        $descartada = $this->sembrar_propuesta($articulo, [], [
            'status'      => ArticleDescriptionProposal::STATUS_DESCARTADA,
            'resolved_at' => '2026-10-14 10:00:00',
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $descartada->resolved_at);

        $ids = ArticleDescriptionProposal::aRevisar()->where('article_id', $articulo->id)->pluck('id')->all();
        $this->assertSame([$propuesta->id], $ids);
    }
}
