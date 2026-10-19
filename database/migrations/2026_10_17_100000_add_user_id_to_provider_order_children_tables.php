<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Misión `tenencia-compra-hermanos` (17/10/2026): el dueño de los hijos de la compra a proveedor.
 *
 * Costos extra, descuentos y alícuotas de IVA no tenían `user_id`: su dueño se deducía de la compra
 * (o de la factura) de la que cuelgan. Pero el formulario los crea ANTES de guardar la compra, con
 * `temporal_id` y sin padre, y en ese tiempo no hay de dónde sacar de quién son. La columna es
 * nullable a propósito: las filas que ya existen se resuelven por su padre (no hace falta backfill)
 * y las nuevas la llevan marcada desde el alta (el backfill de abajo las marca igual, por el asistente de IA).
 */
class AddUserIdToProviderOrderChildrenTables extends Migration
{
    /** @var array<int,string> */
    protected $tablas = [
        'provider_order_extra_costs',
        'provider_order_discounts',
        'provider_order_afip_ticket_ivas',
    ];

    public function up()
    {
        foreach ($this->tablas as $tabla) {

            if (Schema::hasTable($tabla) && !Schema::hasColumn($tabla, 'user_id')) {

                Schema::table($tabla, function (Blueprint $table) {
                    $table->integer('user_id')->nullable()->index();
                });
            }
        }

        /*
         * Backfill desde el padre. No es solo prolijidad: el asistente de IA
         * (EjecutorAccionDePantallaIaHelper::verificar_fila) trata una tabla CON `user_id` como
         * "la fila se prueba por su propia columna" y rechaza como ajena la que la tiene en NULL.
         * Antes de esta migración esas tablas no tenían la columna y se resolvían por sus padres.
         */
        foreach (['provider_order_extra_costs', 'provider_order_discounts'] as $tabla) {

            DB::statement(
                "UPDATE `$tabla` t INNER JOIN `provider_orders` po ON po.id = t.provider_order_id " .
                "SET t.user_id = po.user_id WHERE t.user_id IS NULL"
            );
        }

        // Las facturas pueden no tener `user_id` (viejas): se les toma el de la compra primero.
        DB::statement(
            "UPDATE `provider_order_afip_ticket_ivas` i " .
            "INNER JOIN `provider_order_afip_tickets` ft ON ft.id = i.provider_order_afip_ticket_id " .
            "LEFT JOIN `provider_orders` po ON po.id = ft.provider_order_id " .
            "SET i.user_id = COALESCE(ft.user_id, po.user_id) WHERE i.user_id IS NULL"
        );
    }

    public function down()
    {
        foreach ($this->tablas as $tabla) {

            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'user_id')) {

                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('user_id');
                });
            }
        }
    }
}
