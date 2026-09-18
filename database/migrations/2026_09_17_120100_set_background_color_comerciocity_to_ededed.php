<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El fondo de fabrica de la plantilla ComercioCity pasa a ser #EDEDED (pedido de Lucas,
 * 17/9/2026).
 *
 * 🔴 POR QUE HACE FALTA UNA MIGRACION Y NO ALCANZA CON CAMBIAR EL VALOR POR DEFECTO.
 * `tienda-spa` resuelve el fondo con `normalize_hex_color(background_color, <default>)`: el
 * default solo se usa si la columna esta vacia o no es un hex valido. Y esa columna NUNCA esta
 * vacia — `2026_07_23_130000_add_background_color_to_online_configurations_table.php` la creo
 * con `default('#FFFFFF')`, lo que rellena todas las filas existentes, y ademas hizo backfill
 * explicito por plantilla. Sin esta migracion, el default nuevo es una rama muerta: no hay una
 * sola tienda real que pase por ella.
 *
 * QUE SE TOCA Y QUE NO. Solo las tiendas con plantilla ComercioCity cuyo fondo es exactamente
 * el blanco de fabrica (o esta vacio): esas nunca eligieron un color, se lo puso la migracion de
 * julio. A la que eligio cualquier otro color no se la toca, que es la decision que tomo Lucas
 * cuando se le mostro que "los que ya eligieron" y "los que tienen el default puesto por una
 * migracion" eran indistinguibles hasta que se midio.
 *
 * El id de la plantilla se resuelve por su `slug`, nunca hardcodeado: mismo criterio que la
 * migracion de julio, porque el id cambia entre bases.
 */
class SetBackgroundColorComerciocityToEdeded extends Migration
{
    /** El blanco de fabrica que dejo la migracion de julio. */
    const BLANCO_DE_FABRICA = '#FFFFFF';

    /** El gris que apoya las tarjetas blancas de la ficha, como hace Mercado Libre. */
    const GRIS_COMERCIOCITY = '#EDEDED';

    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('online_templates') || !Schema::hasColumn('online_configurations', 'background_color')) {
            return;
        }

        $plantilla = DB::table('online_templates')->where('slug', 'comerciocity')->first();

        if (!$plantilla) {
            return;
        }

        DB::table('online_configurations')
            ->where('online_template_id', $plantilla->id)
            ->where(function ($query) {
                $query->whereNull('background_color')
                      ->orWhere('background_color', '')
                      ->orWhere('background_color', self::BLANCO_DE_FABRICA);
            })
            ->update(['background_color' => self::GRIS_COMERCIOCITY]);
    }

    /**
     * Devuelve al blanco de fabrica lo que esta migracion pinto de gris.
     *
     * No es simetrica y no puede serlo: si despues de correr el `up()` alguien eligio #EDEDED a
     * proposito, este `down()` se lo pisa. No hay forma de distinguir un caso del otro mirando
     * la columna, asi que queda dicho aca en vez de quedar como sorpresa.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('online_templates') || !Schema::hasColumn('online_configurations', 'background_color')) {
            return;
        }

        $plantilla = DB::table('online_templates')->where('slug', 'comerciocity')->first();

        if (!$plantilla) {
            return;
        }

        DB::table('online_configurations')
            ->where('online_template_id', $plantilla->id)
            ->where('background_color', self::GRIS_COMERCIOCITY)
            ->update(['background_color' => self::BLANCO_DE_FABRICA]);
    }
}
