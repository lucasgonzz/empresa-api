<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `carts.payment_id` y `orders.payment_id` pasan de INT a BIGINT.
 *
 * Ahi va el id del pago de Mercado Pago, que la tienda escribe cuando el comprador vuelve de
 * pagar (`tienda-api: CartController@update` -> `CartHelper::checkPaymentStatus`) y, desde la
 * mision `mercado-pago-cobro-demo`, tambien el webhook. Los ids de pago de Mercado Pago tienen hoy
 * 11-12 digitos (del orden de 1.2e11) y un INT con signo termina en 2.147.483.647: en MySQL 8 con
 * `STRICT_TRANS_TABLES` (el modo del VPS, medido el 5/9/2026) el UPDATE revienta con "Out of range
 * value", el `PUT /carts` post-pago responde 500 y el comprador ve "Recibimos tu pago, pero hubo un
 * problema al confirmar tu pedido" con la plata ya cobrada.
 *
 * ALTER crudo y no `->change()`, por el criterio que ya escribieron las dos migraciones anteriores
 * que ensancharon una columna (`2026_08_22_100000_widen_price_type_id_in_sales_table`,
 * `2026_07_30_120000_change_iva_percentage_to_string_in_pivots`): DBAL reconstruye la columna a
 * partir de lo declarado y se come lo que no se repita. Aca no habia nada que perder (sin default,
 * sin indice, sin comentario), pero el DDL explicito es el mismo en MySQL 5.7 y 8 y no depende de
 * lo que DBAL decida comparar.
 *
 * Es aditivo y compatible en las dos direcciones: una tienda vieja sigue escribiendo enteros chicos
 * sin problema, y una base que ya tenga la columna en BIGINT (la demo, ampliada a mano el 5/9)
 * recibe un ALTER que la deja igual. `payments.payment_id` no se toca: ya es string.
 */
class AmpliarPaymentIdDeCartsYOrders extends Migration
{
    /** Tope de un INT con signo en MySQL: lo que no entra ahi no puede volver al tipo viejo. */
    const MAX_INT = 2147483647;

    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('carts', 'payment_id')) {
            DB::statement('ALTER TABLE `carts` MODIFY `payment_id` BIGINT NULL DEFAULT NULL');
        }

        if (Schema::hasColumn('orders', 'payment_id')) {
            DB::statement('ALTER TABLE `orders` MODIFY `payment_id` BIGINT NULL DEFAULT NULL');
        }
    }

    /**
     * Salida de emergencia, no un camino normal: el pipeline de upgrade no hace rollback.
     *
     * Antes de angostar se anulan los ids que no entran en un INT. Sin eso, en modo estricto el
     * ALTER falla con "Out of range value" y el rollback queda a mitad; en modo no estricto MySQL
     * recorta a 2147483647 en silencio y la referencia al pago queda corrupta. Mismo criterio que
     * el `down()` de las dos migraciones citadas arriba.
     *
     * @return void
     */
    public function down()
    {
        foreach (['carts', 'orders'] as $tabla) {
            if (!Schema::hasColumn($tabla, 'payment_id')) {
                continue;
            }

            DB::table($tabla)->where('payment_id', '>', self::MAX_INT)->update(['payment_id' => null]);
            DB::statement('ALTER TABLE `'.$tabla.'` MODIFY `payment_id` INT NULL DEFAULT NULL');
        }
    }
}
