<?php

namespace App\Observers;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;

/**
 * Lista de precios nueva -> su diseño de etiquetas de góndola (misión disenos-etiquetas-gondola,
 * 29/9/2026): una copia del diseño de siempre con el precio de esa lista, así la opción que antes
 * aparecía sola en el menú de etiquetas del listado sigue apareciendo.
 *
 * Va en el evento `created` del modelo y no en `PriceTypeController@store` porque las listas se
 * crean por más de un camino: el ABM, la importación de clientes (`ClientImport` ->
 * `LocalImportHelper::savePriceType()`), el alta de una demo (`DemoSetupHelper::crear_price_types()`)
 * y los seeders. Todos pasan por `PriceType::create()`.
 *
 * Solo crea si el dueño trabaja con listas, y es idempotente (`crear_diseno_de_lista()` mira si
 * ya existe el de esa lista, con el candado del dueño). 🔴 Un error acá JAMÁS puede romper el alta
 * de la lista: se loguea y sigue.
 *
 * PHP 7.4 estricto.
 */
class PriceTypeObserver
{
    /**
     * @param  \App\Models\PriceType  $price_type
     * @return void
     */
    public function created(PriceType $price_type)
    {
        try {
            ArticleTicketDesignHelper::crear_diseno_de_lista($price_type);
        } catch (\Throwable $e) {
            Log::warning('PriceTypeObserver: no se pudo crear el diseño de etiquetas de la lista '
                .$price_type->id.': '.$e->getMessage());
        }
    }
}
