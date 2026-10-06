<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\category_proposal\CategoryProposalNombreHelper;
use App\Models\CategoryProposalItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La base de la categorización con IA: el esquema de las cuatro tablas y la normalización de nombres
 * (misión categorizacion-tres-modelos, 5/10/2026). Protege las decisiones del plan §3 que el resto del
 * código da por sentadas: sin esto, un constructor que renombre una columna rompe al otro sin enterarse.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Esquema_y_nombres_Test extends CategoryProposalsTestCase
{
    /**
     * @test
     * @group categorias_ia
     */
    public function las_cuatro_tablas_tienen_las_columnas_que_el_plan_da_por_sentadas()
    {
        $esperadas = [
            'category_proposal_runs' => [
                'user_id', 'estado', 'origen', 'articulos_total', 'propuesta_elegida_id', 'elegida_at', 'elegida_por',
                'elegida_con_acceso_maestro', 'eliminar_categorias_vacias', 'categorias_eliminadas', 'resultado',
                'revision_iniciada_at', 'visto_at', 'descartada_at',
            ],
            'category_proposals' => [
                'run_id', 'user_id', 'clave', 'tipo', 'nombre', 'resumen', 'descripcion', 'orden',
            ],
            'category_proposal_nodes' => [
                'proposal_id', 'user_id', 'parent_id', 'nombre', 'clave_nombre', 'orden', 'existing_category_id',
                'existing_sub_category_id', 'real_category_id', 'real_sub_category_id', 'real_creado',
            ],
            'category_proposal_items' => [
                'proposal_id', 'user_id', 'article_id', 'node_id', 'sub_node_id', 'confianza', 'motivo', 'estado',
                'prev_category_id', 'prev_sub_category_id', 'revisado_por', 'revisado_at',
            ],
        ];

        foreach ($esperadas as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
            $this->assertTrue(Schema::hasColumn($tabla, 'id'), "La tabla {$tabla} necesita `id` (el catálogo del asistente la lee).");

            foreach ($columnas as $columna) {
                $this->assertTrue(Schema::hasColumn($tabla, $columna), "Falta {$tabla}.{$columna}.");
            }
        }
    }

    /**
     * @test
     * @group categorias_ia
     */
    public function un_articulo_solo_puede_tener_un_item_por_propuesta()
    {
        // El UNIQUE (proposal_id, article_id) es lo que permite el upsert idempotente de la skill.
        $articulo = $this->crear_articulo('Bisagra de prueba');
        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => []],
                    'items' => [[$articulo, 'Bisagras', null, 'segura']],
                ],
            ],
        ]);

        $proposal = $sembrado['propuestas']['A']['proposal'];

        try {
            CategoryProposalItem::create([
                'proposal_id' => $proposal->id,
                'user_id'     => $this->owner->id,
                'article_id'  => $articulo->id,
                'confianza'   => 'segura',
                'estado'      => 'propuesta',
            ]);
            $this->fail('El segundo ítem del mismo artículo en la misma propuesta no debería poder insertarse.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('cpi_prop_art_uq', $e->getMessage());
        }

        $this->assertSame(1, DB::table('category_proposal_items')->where('proposal_id', $proposal->id)->count());
    }

    /**
     * @test
     * @group categorias_ia
     */
    public function la_clave_de_un_nombre_ignora_mayusculas_acentos_y_espacios()
    {
        $this->assertSame('ferreteria y herrajes', CategoryProposalNombreHelper::clave_de('  Ferretería   y  HERRAJES '));
        $this->assertSame('bisagras', CategoryProposalNombreHelper::clave_de('Bisagras'));
        $this->assertSame('', CategoryProposalNombreHelper::clave_de('   '));
        $this->assertSame('', CategoryProposalNombreHelper::clave_de(null));
    }

    /**
     * @test
     * @group categorias_ia
     */
    public function la_categoria_que_la_tienda_esconde_por_nombre_no_es_usable()
    {
        // tienda-api esconde `La de siempre` por nombre: ni se crea ni se reutiliza.
        $this->assertFalse(CategoryProposalNombreHelper::es_usable('La de siempre'));
        $this->assertFalse(CategoryProposalNombreHelper::es_usable('  LA  DE  SIEMPRE '));
        $this->assertFalse(CategoryProposalNombreHelper::es_usable(''));
        $this->assertTrue(CategoryProposalNombreHelper::es_usable('Bisagras'));
    }
}
