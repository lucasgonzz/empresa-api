<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos columnas del canje de puntos en la venta.
 *
 * POR QUÉ VAN EN `sales` Y NO SOLO EN EL LIBRO DE MOVIMIENTOS: el canje baja `sales.total`,
 * y cualquiera que lea `sales` --el PDF, el ticket, ContabilidadRepository, AfipHelper--
 * tiene que poder explicar esa diferencia sin joinear el libro de puntos. Además
 * SaleController@update necesita el valor VIEJO para deshacer el canje anterior antes de
 * aplicar el nuevo.
 *
 * 🔴 POR QUÉ NO SE REUSA `sales.descuento`: es decimal(10,4) y guarda un PORCENTAJE, no
 * pesos (src/mixins/vender_set_total.js::aplicar_descuento() hace
 * total_articles * descuento / 100). Meter pesos ahí rompería el total de todas las ventas
 * existentes del cliente.
 *
 * // 🔴 NO reusar current_acount_payment_method_sale.discount_amount para el canje de puntos.
 * // Esa columna es el descuento/recargo por MEDIO DE PAGO -- Capa 3 del motor de precios -- y
 * // ContabilidadRepository.php:640 la declara fuente prohibida para cualquier otra lectura.
 * // El canje de puntos es otra capa: baja sales.total y por ahi entra a ventas_brutas(), que es
 * // la unica fuente que ese reporte declara suya (ContabilidadRepository.php:190).
 */
class AddPuntosToSalesTable extends Migration
{
    /**
     * Agrega las columnas, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {

            /* Cuántos puntos se canjearon en esta venta. null = no hubo canje */
            if (!Schema::hasColumn('sales', 'puntos_canjeados')) {
                $table->decimal('puntos_canjeados', 20, 2)->nullable();
            }

            /*
             * Los pesos que ese canje descontó. Lo recalcula SIEMPRE el servidor
             * (puntos_canjeados * valor_punto): el valor que manda el front se ignora.
             */
            if (!Schema::hasColumn('sales', 'descuento_puntos')) {
                $table->decimal('descuento_puntos', 20, 2)->nullable();
            }
        });
    }

    /**
     * Saca las columnas.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {

            $columnas = [];

            if (Schema::hasColumn('sales', 'puntos_canjeados')) {
                $columnas[] = 'puntos_canjeados';
            }

            if (Schema::hasColumn('sales', 'descuento_puntos')) {
                $columnas[] = 'descuento_puntos';
            }

            if (count($columnas)) {
                $table->dropColumn($columnas);
            }
        });
    }
}
