<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Todas las listas de precios pasan a `update_existing_articles_percentage_mode = 'none'`
 * (misión sincronizar-margen-lista-precios, 1/10/2026).
 *
 * La propiedad "Al actualizar el margen por defecto, actualizar los artículos que..." se sacó: los
 * artículos se sincronizan con el margen de la lista SOLO con el botón "Sincronizar artículos" del
 * modal, que viaja en el PUT como `sincronizar_margen`. PriceTypeController ya no persiste el modo
 * (store() y update() escriben siempre 'none').
 *
 * Por qué hace falta además tocar el DATO: el formulario de la SPA se llena con la fila que
 * devuelve la API y vuelve a mandar en el PUT todo lo que trae. Una fila guardada en 'all' u
 * 'only_default_matches' haría que el SPA viejo (cacheado en la PWA, convive uno o dos releases
 * con esta API) reenviara ese modo en CADA guardado sin que la persona lo eligiera de nuevo, y
 * PriceTypeController::update() lo honra por compatibilidad. Con la fila en 'none', el SPA viejo
 * solo actualiza artículos si la persona elige la opción en ese guardado.
 *
 * La columna NO se borra: la sigue leyendo el SPA viejo. Se saca en una misión posterior, cuando no
 * quede SPA viejo.
 *
 * UPDATE acotado a las filas que no están en 'none', con guarda `Schema::hasColumn`. Compatible en
 * las dos direcciones: un deploy de empresa sube los archivos antes de migrar, y el código nuevo
 * ya no actúa por el valor guardado.
 */
class ResetUpdateExistingArticlesPercentageMode extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('price_types', 'update_existing_articles_percentage_mode')) {
            DB::statement("UPDATE `price_types` SET `update_existing_articles_percentage_mode` = 'none' WHERE `update_existing_articles_percentage_mode` IS NULL OR `update_existing_articles_percentage_mode` <> 'none'");
        }
    }

    /**
     * No deshace nada: no hay forma de saber qué modo tenía cada lista, y el código nuevo no lo lee.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
