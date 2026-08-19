<?php

namespace App\Services\PurchaseSuggestion;

use App\Models\PurchaseSuggestionArticle;
use Illuminate\Support\Facades\DB;

/**
 * Ranking 1..N de urgencia de una corrida de sugerencia de compra, en una
 * sola pasada global al cerrar la corrida (no por chunk: numerar por chunk
 * daría varias "prioridad 1" en la misma corrida).
 *
 * Copia estructural de CoberturaService::asignar_prioridades() (sugerencias
 * de stock, app/Services/StockSuggestion/CoberturaService.php:292-322)
 * adaptada a purchase_suggestion_articles: el original NO se toca ni se
 * parametriza, porque tiene clavados el modelo, la tabla y las columnas de
 * stock (StockSuggestionArticle / suggested_amount) — ver
 * INSUMOS-MEDIDOS.md §10, punto 3.
 */
class PrioridadComprasService
{
    /** Tamaño de lote del UPDATE de prioridades */
    const LOTE_UPDATE_PRIORIDADES = 500;

    /**
     * Orden: cobertura ascendente (NULLs -cobertura infinita, artículos sin
     * venta reciente- al final), desempate por cantidad sugerida
     * descendente (lo más voluminoso primero dentro de la misma urgencia) y
     * por id para que el ranking sea estable entre corridas del mismo dato.
     *
     * @param int $purchase_suggestion_id
     * @return void
     */
    public static function asignar_prioridades($purchase_suggestion_id)
    {
        $ids_ordenados = PurchaseSuggestionArticle::where('purchase_suggestion_id', $purchase_suggestion_id)
            ->orderByRaw('cobertura_dias IS NULL ASC')
            ->orderBy('cobertura_dias', 'ASC')
            ->orderBy('cantidad_sugerida', 'DESC')
            ->orderBy('id', 'ASC')
            ->pluck('id');

        $prioridad = 1;

        foreach ($ids_ordenados->chunk(self::LOTE_UPDATE_PRIORIDADES) as $lote) {

            $cases = [];
            $ids = [];

            foreach ($lote as $id) {
                $cases[] = 'WHEN ' . (int) $id . ' THEN ' . $prioridad;
                $ids[] = (int) $id;
                $prioridad++;
            }

            // Un UPDATE por lote (ids propios, casteados a int: no hay input
            // de usuario acá) en vez de un UPDATE por fila.
            DB::update(
                'UPDATE purchase_suggestion_articles
                 SET prioridad = CASE id ' . implode(' ', $cases) . ' END
                 WHERE id IN (' . implode(',', $ids) . ')'
            );
        }
    }
}
