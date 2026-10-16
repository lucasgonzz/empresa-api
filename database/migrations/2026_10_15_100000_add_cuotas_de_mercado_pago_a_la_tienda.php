<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas con recargo de Mercado Pago en la tienda (misión tienda-cuotas-mercado-pago, 15/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ GUARDA CADA COLUMNA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  - `payment_methods.current_acount_payment_method_id`: el método de pago de CUENTA CORRIENTE con el que
 *    se vincula el método de la tienda (tipo MercadoPago). Las filas de `cuotas` cargadas para ese método
 *    son las cuotas que la tienda le ofrece al comprador. NULL = sin vínculo = la tienda se comporta como
 *    siempre.
 *  - `carts.cuotas`: la cantidad de cuotas que el comprador eligió en el checkout. NULL = contado, o un
 *    método sin cuotas.
 *  - `orders.cuotas` / `orders.cuotas_recargo`: lo que eligió y el recargo neto (%) con el que se cobró. Los
 *    escribe `tienda-api` al crear el pedido, resolviendo el porcentaje desde la base (nunca del request).
 *  - `orders.cuotas_cobradas` / `orders.medio_de_pago_cobrado`: lo que Mercado Pago dice que se COBRÓ de
 *    verdad (cuotas y tipo de medio de pago). Checkout Pro no puede forzar la cantidad de cuotas ni excluir
 *    "dinero en cuenta", así que el webhook deja anotado lo real para que el comerciante lo vea.
 *
 * ⚠️ TODO ADITIVO, NULLABLE Y SIN DEFAULT. Un registro viejo queda en NULL, que significa "esto no existía".
 * Sin foreign keys (la tienda no tiene migraciones y la base es compartida).
 *
 * 🔴 `empresa` y `tienda` comparten la base física del cliente y se despliegan por separado; un deploy sube
 * los archivos ANTES de migrar. Por eso TODO punto de lectura o escritura de estas columnas, en los dos
 * proyectos, va detrás de una guarda de esquema (`Schema::hasColumn`). Sin la columna, la funcionalidad queda
 * apagada y el código se comporta como antes.
 *
 * Con guard `hasColumn` en cada columna para que sea segura de re-ejecutar (la `tienda` la aplica a mano en
 * sus bases de testing, que no tienen migraciones propias).
 */
class AddCuotasDeMercadoPagoALaTienda extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('payment_methods', 'current_acount_payment_method_id')) {

            Schema::table('payment_methods', function (Blueprint $table) {
                $table->integer('current_acount_payment_method_id')->unsigned()->nullable();
                $table->index('current_acount_payment_method_id', 'payment_methods_ca_pm_idx');
            });
        }

        if (!Schema::hasColumn('carts', 'cuotas')) {

            Schema::table('carts', function (Blueprint $table) {
                $table->integer('cuotas')->unsigned()->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'cuotas')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->integer('cuotas')->unsigned()->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'cuotas_recargo')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('cuotas_recargo', 12, 2)->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'cuotas_cobradas')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->integer('cuotas_cobradas')->unsigned()->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'medio_de_pago_cobrado')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->string('medio_de_pago_cobrado', 40)->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('orders', 'medio_de_pago_cobrado')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('medio_de_pago_cobrado');
            });
        }

        if (Schema::hasColumn('orders', 'cuotas_cobradas')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('cuotas_cobradas');
            });
        }

        if (Schema::hasColumn('orders', 'cuotas_recargo')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('cuotas_recargo');
            });
        }

        if (Schema::hasColumn('orders', 'cuotas')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('cuotas');
            });
        }

        if (Schema::hasColumn('carts', 'cuotas')) {

            Schema::table('carts', function (Blueprint $table) {
                $table->dropColumn('cuotas');
            });
        }

        if (Schema::hasColumn('payment_methods', 'current_acount_payment_method_id')) {

            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropIndex('payment_methods_ca_pm_idx');
                $table->dropColumn('current_acount_payment_method_id');
            });
        }
    }
}
