<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\CategoryProposalRun;

/**
 * Lo que ve la SPA de una corrida de propuestas de categorías: el objeto `run`, las tarjetas con sus
 * árboles y conteos, los ítems paginados de la revisión y el resumen del badge (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato B del plan, §6.
 *
 * 🔴 ESQUELETO DE LA BASE: `run_payload` y `conteos` son las firmas que consume el otro constructor
 * (API-2 las usa en las respuestas de `elegir` y `volver_atras`). API-1 implementa esta clase entera
 * conservando las firmas; mientras tanto devuelven lo mínimo.
 */
class CategoryProposalLecturaHelper
{
    /**
     * El objeto `run` del contrato B (§6.2): id, estado, articulos_total, creada_at, elegida_at,
     * propuesta_elegida_id, puede_gestionar, puede_cambiar, motivo_no_puede_cambiar y resultado.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array
     */
    public static function run_payload(CategoryProposalRun $run)
    {
        return [
            'id'     => $run->id,
            'estado' => $run->estado,
        ];
    }

    /**
     * Los conteos de las tres solapas de la revisión, de la propuesta elegida de la corrida.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array  ['a_revisar' => n, 'asignados' => n, 'sin_categoria' => n]
     */
    public static function conteos(CategoryProposalRun $run)
    {
        return ['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0];
    }
}
