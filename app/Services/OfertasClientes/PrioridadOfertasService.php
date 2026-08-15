<?php

namespace App\Services\OfertasClientes;

use App\Models\OfferSuggestionLine;
use Illuminate\Support\Facades\DB;

/**
 * Ranking 1..N de las líneas de una corrida del motor de ofertas, en UNA sola pasada global al
 * cerrar la corrida.
 *
 * 🔴 Por qué global y no por chunk: cada chunk procesa un lote de CLIENTES y numerar adentro del
 * chunk daría tantas "prioridad 1" como chunks tenga la corrida. El paginado del endpoint ordena
 * por esta columna (OfferSuggestionController@lines), así que un ranking repetido se ve
 * directamente como filas fuera de orden en la pantalla.
 *
 * Copia estructural de PrioridadComprasService (sugerencias de compra), que a su vez copia
 * CoberturaService::asignar_prioridades() de stock. Los tres son el mismo patrón con otra tabla y
 * otro criterio de orden: no se parametriza ninguno de los anteriores porque tienen clavados el
 * modelo, la tabla y las columnas de su dominio.
 */
class PrioridadOfertasService
{
    /** Tamaño de lote del UPDATE de prioridades */
    const LOTE_UPDATE_PRIORIDADES = 500;

    /**
     * Orden: score descendente (NULLs al final), desempate por porcentaje sugerido descendente
     * —a igual fuerza de señal, la oferta más golosa primero— y por id para que el ranking sea
     * estable entre dos corridas del mismo dato.
     *
     * @param int $offer_suggestion_id
     * @return void
     */
    public static function asignar_prioridades($offer_suggestion_id)
    {
        $ids_ordenados = OfferSuggestionLine::where('offer_suggestion_id', $offer_suggestion_id)
            ->orderByRaw('score IS NULL ASC')
            ->orderBy('score', 'DESC')
            ->orderBy('porcentaje_sugerido', 'DESC')
            ->orderBy('id', 'ASC')
            ->pluck('id');

        $prioridad = 1;

        foreach ($ids_ordenados->chunk(self::LOTE_UPDATE_PRIORIDADES) as $lote) {

            $cases = [];
            $ids   = [];

            foreach ($lote as $id) {
                $cases[] = 'WHEN ' . (int) $id . ' THEN ' . $prioridad;
                $ids[]   = (int) $id;
                $prioridad++;
            }

            // Un UPDATE por lote (ids propios, casteados a int: acá no entra input de usuario) en
            // vez de un UPDATE por fila, que en una corrida grande son miles de round-trips.
            DB::update(
                'UPDATE offer_suggestion_lines
                 SET prioridad = CASE id ' . implode(' ', $cases) . ' END
                 WHERE id IN (' . implode(',', $ids) . ')'
            );
        }
    }
}
