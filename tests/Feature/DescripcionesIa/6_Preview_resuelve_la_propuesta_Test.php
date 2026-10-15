<?php

namespace Tests\Feature\DescripcionesIa;

use App\Models\ArticleDescriptionProposal;

/**
 * El preview individual (`POST article-description-ai/store/{id}`, lo que confirma una persona desde
 * la ficha) resuelve la propuesta que el artículo tuviera a revisar (misión alertas-descripciones,
 * 14/10/2026, plan §1.3).
 *
 * Sin esto, el artículo quedaba con su descripción revisada Y con la propuesta en Alertas: aprobarla
 * después le duplicaba la descripción.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Preview_resuelve_la_propuesta_Test extends DescripcionesIaTestCase
{
    /**
     * @test
     * @group descripciones_ia
     */
    public function guardar_el_preview_descarta_la_propuesta_a_revisar()
    {
        $empleado = $this->crear_empleado_de($this->owner);

        $articulo   = $this->crear_articulo('Taladro');
        $propuesta  = $this->sembrar_propuesta($articulo);
        $aprobada   = $this->sembrar_propuesta($this->crear_articulo('Otro'), [], ['status' => ArticleDescriptionProposal::STATUS_APROBADA]);

        $this->actuar_como($empleado);

        $this->postJson('api/article-description-ai/store/'.$articulo->id, [
            'sections'   => [['title' => 'Descripción', 'content' => 'Taladro percutor de 550 W']],
            'confidence' => 'high',
            'sources'    => [],
        ])->assertStatus(201);

        $propuesta = $this->propuesta_fresca($propuesta);
        $this->assertSame('descartada', $propuesta->status);
        $this->assertNotNull($propuesta->resolved_at);
        $this->assertSame($empleado->id, (int) $propuesta->resolved_by, 'La persona real.');

        $descripciones = $this->descripciones_de($articulo);
        $this->assertSame(['Taladro percutor de 550 W'], $descripciones->pluck('content')->all());
        $this->assertNotNull($descripciones->first()->ai_reviewed_at);

        $this->assertSame('aprobada', $this->propuesta_fresca($aprobada)->status, 'Lo de otro artículo no se toca.');

        $this->getJson('api/article-description-ai/resumen')->assertJsonPath('a_revisar', 0);
    }
}
