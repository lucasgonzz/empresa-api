<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Borra los current_acount_payment_method_discounts cuyo metodo de pago ya no existe.
 *
 * 🔴 POR QUE HACE FALTA UNA MIGRACION Y NO ALCANZA CON EL ARREGLO DEL CONTROLADOR.
 *
 * `CurrentAcountPaymentMethodController::destroy()` ahora se lleva los descuentos junto con el
 * metodo, asi que no se generan huerfanos NUEVOS. Pero los que ya existen en las bases de los
 * clientes siguen ahi, y son los que rompen: un descuento sin metodo deja la relacion en null y el
 * SPA le lee `.name` en seis lugares —incluido `vender_set_total.js`, el calculo del total de una
 * venta— sin preguntar.
 *
 * Paso en la produccion de masquito el 3/9/2026 y dejo al comercio sin poder editar stock. Ese se
 * limpio a mano ese mismo dia; esta migracion es para los otros 40, donde nadie fue a mirar.
 *
 * 🔴 ES SEGURA DE CORRER: solo toca filas cuyo `current_acount_payment_method_id` no matchea
 * ninguna fila de `current_acount_payment_methods`. Un descuento en ese estado no se puede aplicar
 * a ninguna venta —no hay metodo de pago al cual aplicarlo—, asi que no se pierde nada que
 * estuviera funcionando. No toca historial: `sales`, `expenses` y las cuentas corrientes quedan
 * intactas.
 *
 * Es idempotente: correrla dos veces no encuentra nada la segunda vez.
 */
class BorrarDescuentosDeMetodosDePagoInexistentes extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        /*
         * LEFT JOIN + IS NULL en vez de whereNotIn con una subconsulta: con la tabla de metodos
         * vacia, un `NOT IN (SELECT ...)` sobre un conjunto vacio se comporta distinto segun el
         * motor y podria no borrar nada (o borrarlo todo). El join dice exactamente lo que hace.
         */
        $huerfanos = DB::table('current_acount_payment_method_discounts as d')
            ->leftJoin(
                'current_acount_payment_methods as m',
                'm.id', '=', 'd.current_acount_payment_method_id'
            )
            ->whereNull('m.id')
            ->pluck('d.id')
            ->all();

        if (! count($huerfanos)) {
            return;
        }

        DB::table('current_acount_payment_method_discounts')->whereIn('id', $huerfanos)->delete();

        Log::info(
            'Migracion borrar_descuentos_de_metodos_de_pago_inexistentes: se borraron '
            . count($huerfanos) . ' descuento(s) cuyo metodo de pago ya no existia. Ids: '
            . implode(',', $huerfanos)
        );
    }

    /**
     * No hay vuelta atras, y no es un descuido.
     *
     * Lo que se borro son filas que apuntaban a un metodo de pago inexistente: no hay dato desde
     * el cual reconstruirlas, porque justamente lo que les falta es el registro al que apuntaban.
     * Un `down()` que no puede restituir nada es mejor como no-op explicito que como promesa falsa.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
