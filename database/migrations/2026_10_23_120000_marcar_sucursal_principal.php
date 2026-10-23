<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursal principal (misión sucursal-siempre, 23/10/2026, D2): todo comercio que tiene al menos una
 * sucursal queda con EXACTAMENTE UNA marcada como principal (`addresses.default_address = 1`).
 *
 * Migración de DATOS, sin esquema (la columna existe desde 2021). Por cada dueño con sucursales
 * (`user_id` no nulo y `buyer_id` nulo: la tabla es compartida con tienda-api, que guarda ahí los
 * domicilios de envío de los compradores):
 *
 *  - ninguna marcada → marca el depósito madre (`es_deposito_madre = 1`, si la columna existe) o, si no
 *    hay madre, la de menor id. Es el mismo orden que `Address::principal_de()`, así que no cambia
 *    qué sucursal "es la principal" para el código: solo la deja escrita;
 *  - más de una marcada (dato viejo: el ABM dejaba tildar varias, y la semilla de la demo marcaba tres)
 *    → queda la de menor id y se apagan las otras;
 *  - exactamente una → no se toca.
 *
 * Solo marca: no crea sucursales (eso es el comando `sucursales:migrar-a-principal`, cliente por
 * cliente, con respaldo). Idempotente: una segunda corrida no encuentra nada que hacer. Va por query
 * builder y no por el modelo a propósito: los hooks de `Address` desmarcan por modelo y acá el
 * resultado ya se calcula entero.
 *
 * El `down()` no hace nada: no hay forma de saber qué marcas eran de antes y cuáles puso esta
 * migración, y desmarcar todo dejaría peor a los que ya tenían una sola.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class MarcarSucursalPrincipal extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('addresses') || !Schema::hasColumn('addresses', 'default_address')) {
            return;
        }

        $hay_madre = Schema::hasColumn('addresses', 'es_deposito_madre');

        $duenos = DB::table('addresses')
            ->whereNotNull('user_id')
            ->whereNull('buyer_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($duenos as $user_id) {

            $marcadas = DB::table('addresses')
                ->where('user_id', $user_id)
                ->whereNull('buyer_id')
                ->where('default_address', 1)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if (count($marcadas) === 1) {
                continue;
            }

            if (count($marcadas) > 1) {

                // Queda la de menor id; las demás se apagan.
                DB::table('addresses')
                    ->whereIn('id', array_slice($marcadas, 1))
                    ->update(['default_address' => 0]);

                continue;
            }

            $elegida = null;

            if ($hay_madre) {
                $elegida = DB::table('addresses')
                    ->where('user_id', $user_id)
                    ->whereNull('buyer_id')
                    ->where('es_deposito_madre', 1)
                    ->orderBy('id')
                    ->value('id');
            }

            if (is_null($elegida)) {
                $elegida = DB::table('addresses')
                    ->where('user_id', $user_id)
                    ->whereNull('buyer_id')
                    ->orderBy('id')
                    ->value('id');
            }

            if (!is_null($elegida)) {
                DB::table('addresses')->where('id', $elegida)->update(['default_address' => 1]);
            }
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // Sin reversión: ver el docblock de la clase.
    }
}
