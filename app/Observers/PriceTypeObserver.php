<?php

namespace App\Observers;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;

/**
 * Los diseños de etiquetas de góndola que el sistema genera para cada lista de precios (misión
 * disenos-etiquetas-gondola, 29/9/2026) siguen a su lista:
 *
 *   - created: una copia del diseño de siempre con el precio de esa lista, así la opción que antes
 *     aparecía sola en el menú de etiquetas del listado sigue apareciendo.
 *   - updated: si la lista cambió de nombre, su diseño también (si no lo renombró el usuario).
 *   - deleted: se borra su diseño.
 *
 * Va en el evento `created` del modelo y no en `PriceTypeController@store` porque las listas se
 * crean por más de un camino: el ABM, el alta de una demo (`DemoSetupHelper::crear_price_types()`)
 * y los seeders, que pasan por `PriceType::create()`.
 *
 * ⚠️ La importación de clientes (`ClientImport` -> `LocalImportHelper::savePriceType()` ->
 * `Controller::createIfNotExist()`) inserta con `DB::table('price_types')->insert()` y NO dispara
 * este observer: esa lista queda sin diseño. No se pierde nada porque el menú de etiquetas del
 * listado le deja a toda lista sin diseño su opción de siempre (`?price_type_id=`), y el seeder la
 * cubre en la próxima corrida.
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

    /**
     * Lista renombrada -> el diseño que el sistema generó para ella toma el nombre nuevo, si
     * todavía tenía el viejo (un nombre que el usuario cambió a mano se respeta).
     *
     * @param  \App\Models\PriceType  $price_type
     * @return void
     */
    public function updated(PriceType $price_type)
    {
        try {
            if ($price_type->wasChanged('name')) {
                ArticleTicketDesignHelper::renombrar_disenos_de_lista($price_type, $price_type->getOriginal('name'));
            }
        } catch (\Throwable $e) {
            Log::warning('PriceTypeObserver: no se pudo renombrar el diseño de etiquetas de la lista '
                .$price_type->id.': '.$e->getMessage());
        }
    }

    /**
     * Lista borrada -> se borran los diseños que el sistema generó para ella (sin lista, su
     * precio no imprime nada). Los que creó el usuario tienen `price_type_id` null y no se tocan.
     *
     * @param  \App\Models\PriceType  $price_type
     * @return void
     */
    public function deleted(PriceType $price_type)
    {
        try {
            ArticleTicketDesignHelper::borrar_disenos_de_lista($price_type);
        } catch (\Throwable $e) {
            Log::warning('PriceTypeObserver: no se pudieron borrar los diseños de etiquetas de la lista '
                .$price_type->id.': '.$e->getMessage());
        }
    }
}
