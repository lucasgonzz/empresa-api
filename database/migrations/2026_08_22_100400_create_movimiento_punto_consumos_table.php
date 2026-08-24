<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué lote de puntos consumió cada canje y cada vencimiento. Es el calco del `pagado_por`
 * de la cuenta corriente de plata.
 *
 * 🔴 POR QUÉ EXISTE ESTA TABLA Y NO SE CALCULA EL FIFO AL VUELO: el vencimiento a los N
 * meses obliga a saber QUÉ puntos quedaron sin gastar, no cuántos. Sin consumo por lote:
 *
 *   - deshacer un canje (venta editada o anulada) no puede devolver los puntos a los lotes
 *     correctos, así que el vencimiento después vence puntos que ya se habían gastado;
 *   - y el barrido de vencimiento no puede distinguir un lote viejo ya consumido de uno
 *     viejo intacto.
 *
 * Es la misma razón por la que la cuenta corriente tiene `pagado_por` en vez de deducir las
 * imputaciones a partir del saldo.
 *
 * Sin foreign keys físicas, igual que todo el schema.
 */
class CreateMovimientoPuntoConsumosTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('movimiento_punto_consumos')) {
            return;
        }

        Schema::create('movimiento_punto_consumos', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* El lote 'ganados' que se consume. `movimiento_puntos.id` es bigint unsigned */
            $table->unsignedBigInteger('movimiento_origen_id');

            /* El 'canjeados' o 'vencidos' que lo consume */
            $table->unsignedBigInteger('movimiento_consumo_id');

            /* Cuánto de ese lote se llevó este consumo. Siempre positivo */
            $table->decimal('puntos', 20, 2);

            $table->timestamps();

            /*
             * Deshacer un canje: se leen sus consumos y se devuelve cada uno a su lote. Es la
             * entrada por la que se lee cuando se edita o se anula una venta.
             */
            $table->index(['movimiento_consumo_id'], 'movimiento_punto_consumos_consumo_index');

            /* Cuánto le queda vivo a un lote (auditoría y el barrido de vencimiento) */
            $table->index(['movimiento_origen_id'], 'movimiento_punto_consumos_origen_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('movimiento_punto_consumos');
    }
}
