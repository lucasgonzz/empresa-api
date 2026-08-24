<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La configuración del programa de puntos de un comercio.
 *
 * NOMBRE EN PLURAL DESDE EL DÍA UNO, A PROPÓSITO (decisión de Lucas, 21/8/2026): el MVP
 * usa un solo programa activo por negocio, pero la tabla es plural para que el día que se
 * quieran varios no haya que renombrarla ni hacer un backfill sobre las bases de los 40 y
 * pico de clientes. Consecuencia obligatoria: el modelo `SistemaDePuntos` tiene que
 * declarar `$table` a mano, porque Laravel derivaría 'sistema_de_puntos' (singular).
 *
 * 🔴 A PROPÓSITO NO HAY UNIQUE SOBRE `user_id`. El invariante "uno solo activo por
 * negocio" se sostiene EN CÓDIGO en SistemaDePuntosController (al guardar con activo = 1
 * se apagan los demás del mismo user_id, en la misma transacción) y tiene su test. Un
 * unique acá rompería el día que se quieran varios programas, que es justamente el hedge
 * que la tabla plural está guardando.
 *
 * COLUMNA QUE NO VA: `suma_al_cobrar`. El boceto (ideas/crm_puntos_clientes.md:128) la
 * nombraba, pero la semántica ya quedó cerrada ("los puntos aparecen cuando la venta queda
 * cobrada, todo o nada") y una columna con una sola rama viva es exactamente la clase
 * "rama muerta por condición que otro flujo deja siempre verdadera"
 * (APRENDER_NO_PARCHEAR.md:152). Si algún día se quiere "al facturar", es una migración
 * aditiva de una línea.
 *
 * Sin foreign keys físicas, igual que todo el schema.
 */
class CreateSistemasDePuntosTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('sistemas_de_puntos')) {
            return;
        }

        Schema::create('sistemas_de_puntos', function (Blueprint $table) {

            /* Clave primaria autoincremental (bigint unsigned) */
            $table->id();

            /*
             * El negocio dueño del programa. unsignedInteger y no unsignedBigInteger porque
             * `users.id` es increments() -> int unsigned. Espeja el tipo de su fuente.
             */
            $table->unsignedInteger('user_id');

            /* El ABM necesita un `is_title` para la fila del listado */
            $table->string('nombre', 191)->default('Programa de puntos');

            /*
             * 🔴 APAGADO POR DEFECTO. Son dos interruptores distintos: la extensión
             * 'puntos_clientes' prende el MÓDULO para la empresa, y esto prende el PROGRAMA.
             * Un comercio puede tener el módulo y estar todavía configurando la tasa.
             */
            $table->boolean('activo')->default(0);

            /* "N puntos cada $M": puntos_por_tramo puntos por cada puntos_cada pesos de base */
            $table->decimal('puntos_cada', 20, 2)->default(1000.00);
            $table->decimal('puntos_por_tramo', 20, 2)->default(1.00);

            /* Pesos que vale un punto al canjear */
            $table->decimal('valor_punto', 20, 2)->default(10.00);

            /* null = los puntos no vencen nunca. El comando puntos:vencer sale en una línea */
            $table->unsignedSmallInteger('vencimiento_meses')->nullable()->default(12);

            /* Mínimo de puntos para poder canjear */
            $table->decimal('minimo_canje', 20, 2)->default(500.00);

            /* Máximo % de la compra que se puede pagar con puntos */
            $table->decimal('tope_porcentaje', 5, 2)->default(20.00);

            $table->timestamps();

            /*
             * Las dos únicas formas en que se lee esta tabla: la config del comercio en el
             * ABM (user_id) y "¿hay programa activo?" en el camino caliente de guardar una
             * venta (user_id + activo). Un solo índice cubre las dos.
             */
            $table->index(['user_id', 'activo'], 'sistemas_de_puntos_user_activo_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sistemas_de_puntos');
    }
}
