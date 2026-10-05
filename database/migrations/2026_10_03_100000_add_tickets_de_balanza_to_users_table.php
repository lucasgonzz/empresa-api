<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: cómo lee VENDER los tickets de balanza, como configuración del dueño (misión
 * balanzas-configurables, 3/10/2026).
 *
 * Hasta esta misión las balanzas eran dos EXTENSIONES: `plu_balanza_bar_code` (el ticket trae el
 * PLU del artículo y el peso, La Martina) y `balanza_bar_code` (el ticket trae un importe y se le
 * imputa a un artículo con el id HARDCODEADO 6346, Panchito). Ahora es una preferencia del
 * comercio, en el usuario dueño, con estos valores:
 *
 *   NULL        -> nunca se configuró. VENDER no lee ningún ticket de balanza.
 *   'ninguno'   -> el dueño eligió explícitamente no usar balanzas. Mismo efecto que NULL.
 *   'plu'       -> la lectura PLU de siempre (BalanzaHelper::leer_ticket_por_plu()).
 *   'balanzas'  -> la lectura por las balanzas del ABM (tabla `balanzas`,
 *                  BalanzaHelper::leer_ticket_por_balanzas()).
 *
 * 🔴 NULLABLE y default NULL, NO 'ninguno', a propósito. El comando
 * `balanzas:migrar-desde-extensiones` necesita distinguir "nunca se configuró" (lo pasa a la
 * dinámica nueva según las extensiones viejas) de "el dueño ya eligió" (no lo toca, aunque haya
 * elegido 'ninguno'). Con un default 'ninguno' esa diferencia se pierde: o el comando pisa una
 * elección del dueño, o no migra a nadie. Para la lectura NULL y 'ninguno' son lo mismo
 * (UserHelper::modo_tickets_de_balanza() devuelve null para los dos).
 *
 * La base es compartida con `tienda`: `tienda-api` no lee esta columna y sigue andando sin ella.
 * Es aditiva; nada se renombra ni se saca. Las filas de las extensiones viejas tampoco se tocan
 * acá (ver el docblock del comando: el código viejo las sigue leyendo durante el despliegue).
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna (parche a mano) no puede tumbar la migración.
 */
class AddTicketsDeBalanzaToUsersTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('users', 'tickets_de_balanza')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // 'ninguno' | 'plu' | 'balanzas', o NULL = nunca se configuró (ver el docblock).
            $table->string('tickets_de_balanza', 20)->nullable()->default(null);
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('users', 'tickets_de_balanza')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tickets_de_balanza');
        });
    }
}
