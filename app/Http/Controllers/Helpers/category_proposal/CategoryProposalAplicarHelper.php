<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Models\CategoryProposalRun;

/**
 * Elegir un sistema de categorías, volver atrás y revisar lo dudoso (misión categorizacion-tres-modelos,
 * 5/10/2026). Plan §4.2, §4.3 y §4.4.
 *
 * 🔴 ESQUELETO DE LA BASE: `puede_cambiar` es la única firma que consume el otro constructor (API-1
 * la usa para armar `run.puede_cambiar` en `actual`). API-2 implementa esta clase entera conservando la
 * firma; mientras tanto devuelve "no se puede" por el motivo más inocuo.
 */
class CategoryProposalAplicarHelper
{
    /**
     * ¿Se puede volver atrás (cambiar de sistema) en esta corrida? Regla (b): solo mientras nadie haya
     * aprobado ni editado nada. UNA sola fuente de verdad: la comparte `volver_atras` y el payload de
     * `actual`, y la SPA solo dibuja lo que dice.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return array  ['puede' => bool, 'motivo' => null|'no_esta_elegida'|'hay_revisiones'|'articulos_editados'|'categorias_editadas']
     */
    public static function puede_cambiar(CategoryProposalRun $run)
    {
        return ['puede' => false, 'motivo' => 'no_esta_elegida'];
    }
}
