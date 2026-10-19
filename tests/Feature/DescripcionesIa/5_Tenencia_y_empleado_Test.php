<?php

namespace Tests\Feature\DescripcionesIa;

use App\Models\ArticleDescriptionProposal;
use App\Models\Description;

/**
 * Tenencia y permisos de la revisión (misión alertas-descripciones, 14/10/2026, contrato §1.4 y
 * decisión D2).
 *
 * Qué protege:
 *  - Lo de otro comercio no existe: no se cuenta, no se lista, y aprobar / descartar / editar /
 *    quitar sobre un artículo ajeno es 404 sin tocar nada.
 *  - Un empleado del comercio puede revisar (como Imágenes): ve lo del dueño y lo que resuelve
 *    queda a su nombre en `resolved_by`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Tenencia_y_empleado_Test extends DescripcionesIaTestCase
{
    /**
     * Lo del vecino no aparece en el resumen ni en las solapas del dueño.
     *
     * @test
     * @group descripciones_ia
     */
    public function lo_de_otro_comercio_no_se_cuenta_ni_se_lista()
    {
        $propio = $this->crear_articulo('Propio');
        $this->sembrar_propuesta($propio);

        $ajeno = $this->crear_articulo('Ajeno', [], $this->vecino);
        $this->sembrar_propuesta($ajeno);
        $this->sembrar_descripcion($this->crear_articulo('Ajeno publicado', [], $this->vecino), 'D', 'd');

        $this->getJson('api/article-description-ai/resumen')
            ->assertStatus(200)
            ->assertExactJson(['a_revisar' => 1, 'publicadas' => 0, 'badge' => 1]);

        $this->assertSame([$propio->id], array_column($this->getJson('api/article-description-ai/revision')->json('models'), 'article_id'));
        $this->assertSame([], $this->getJson('api/article-description-ai/revision?solapa=publicadas')->json('models'));
        $this->assertSame([], $this->getJson('api/article-description-ai/revision?buscar=Ajeno')->json('models'));
    }

    /**
     * 🔴 Aprobar, descartar, editar o quitar sobre un artículo de otro comercio es 404, y lo del
     * vecino queda exactamente como estaba.
     *
     * @test
     * @group descripciones_ia
     */
    public function resolver_sobre_un_articulo_ajeno_da_404_y_no_toca_nada()
    {
        $con_propuesta = $this->crear_articulo('Ajeno a revisar', [], $this->vecino);
        $propuesta     = $this->sembrar_propuesta($con_propuesta);

        $publicado   = $this->crear_articulo('Ajeno publicado', [], $this->vecino);
        $descripcion = $this->sembrar_descripcion($publicado, 'Descripción', 'Del vecino');

        $this->postJson('api/article-description-ai/revision/'.$con_propuesta->id.'/aprobar')->assertStatus(404);
        $this->postJson('api/article-description-ai/revision/'.$con_propuesta->id.'/descartar')->assertStatus(404);
        $this->putJson('api/article-description-ai/revision/'.$publicado->id.'/publicada', [
            'secciones' => [['id' => $descripcion->id, 'title' => 'Pisada', 'content' => 'Pisada']],
        ])->assertStatus(404);
        $this->postJson('api/article-description-ai/revision/'.$publicado->id.'/quitar')->assertStatus(404);

        $this->assertSame('a_revisar', $this->propuesta_fresca($propuesta)->status);
        $this->assertSame(0, Description::where('article_id', $con_propuesta->id)->count());
        $this->assertSame('Del vecino', Description::find($descripcion->id)->content);
        $this->assertSame(0, ArticleDescriptionProposal::where('article_id', $publicado->id)->count());
    }

    /**
     * Un empleado del comercio (sin permisos especiales) ve la revisión del dueño y puede resolverla;
     * `resolved_by` es el empleado, no el dueño.
     *
     * @test
     * @group descripciones_ia
     */
    public function un_empleado_del_comercio_puede_revisar()
    {
        $empleado = $this->crear_empleado_de($this->owner);

        $aprobar   = $this->crear_articulo('Para aprobar');
        $descartar = $this->crear_articulo('Para descartar');
        $quitar    = $this->crear_articulo('Para quitar');
        $p_aprobar   = $this->sembrar_propuesta($aprobar);
        $p_descartar = $this->sembrar_propuesta($descartar);
        $this->sembrar_descripcion($quitar, 'D', 'd');

        $this->actuar_como($empleado);

        $this->getJson('api/article-description-ai/resumen')
            ->assertStatus(200)
            ->assertExactJson(['a_revisar' => 2, 'publicadas' => 1, 'badge' => 2]);
        $this->assertSame(2, $this->getJson('api/article-description-ai/revision')->json('total'));

        $this->postJson('api/article-description-ai/revision/'.$aprobar->id.'/aprobar')->assertStatus(200);
        $this->postJson('api/article-description-ai/revision/descartar-varios', ['article_ids' => [$descartar->id]])
            ->assertStatus(200)
            ->assertJsonPath('resueltos', 1);
        $this->postJson('api/article-description-ai/revision/'.$quitar->id.'/quitar')->assertStatus(200);

        $this->assertSame($empleado->id, (int) $this->propuesta_fresca($p_aprobar)->resolved_by);
        $this->assertSame($empleado->id, (int) $this->propuesta_fresca($p_descartar)->resolved_by);
        $this->assertSame($empleado->id, (int) ArticleDescriptionProposal::where('article_id', $quitar->id)->value('resolved_by'));

        // Las propuestas siguen siendo del dueño.
        $this->assertSame($this->owner->id, (int) $this->propuesta_fresca($p_aprobar)->user_id);
    }
}
