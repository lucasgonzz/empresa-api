<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea los "Diseños de etiquetas de góndola" de cada dueño con la etiqueta de siempre (misión
 * disenos-etiquetas-gondola, 29/9/2026):
 *
 *   - Dueño que trabaja con listas de precios y tiene alguna -> un diseño por lista (nombre y
 *     posición de la lista, precio de esa lista), si todavía no tiene el de esa lista.
 *   - Dueño sin listas (o con la extensión pero sin listas cargadas) -> un diseño "Etiqueta de
 *     góndola" con el precio final, solo si no tiene ningún diseño.
 *
 * Así el menú de etiquetas del listado ofrece exactamente las mismas opciones que antes (una por
 * lista, o una sola), ahora como diseños editables.
 *
 * STANDALONE E IDEMPOTENTE: se corre como seeder de la versión sobre las bases de producción, y
 * también lo llaman `UserSetupHelper`, `DemoSetupHelper` y `DatabaseSeeder`. Correrlo dos veces no
 * duplica nada. NO le crea nada a un empleado: los diseños son del dueño.
 *
 * La decisión y el alta viven en `ArticleTicketDesignHelper::crear_disenos_del_sistema()`, la misma
 * que usa el alta de una lista de precios (`PriceTypeController@store`), con el candado del dueño.
 */
class ArticleTicketDesignSeeder extends Seeder
{
    /**
     * Recorre los dueños (`owner_id` null) de a 200 (bases compartidas con decenas de comercios).
     *
     * @return void
     */
    public function run()
    {
        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($owners) {
                foreach ($owners as $owner) {
                    ArticleTicketDesignHelper::crear_disenos_del_sistema($owner->id);
                }
            });
    }
}
