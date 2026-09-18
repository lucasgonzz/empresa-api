<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El plan de IA del cliente, empujado por el admin y guardado en el DUEÑO (misión
 * foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * El admin es el que maneja los paquetes de IA y su precio; cuando le asigna uno a un cliente,
 * pushea acá el nombre y los dos topes con `PUT api/admin-sync/plan-ia`. Este proyecto guarda esos
 * tres valores en el dueño y el footer del chat los muestra (consumo del mes contra el tope).
 *
 * 🔴 LOS DOS TOPES SON NULLABLE Y NULL = SIN TOPE. Es la guarda de compatibilidad de las dos
 * direcciones: un cliente al que el admin todavía no le pusheó nada (o un admin viejo que no pushea)
 * queda con las columnas en null y el agente NUNCA corta, funcionando como hoy. El corte por tope
 * solo se activa con un tope > 0 recibido del admin.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddPlanIaToUsersTable extends Migration
{
    /**
     * Agrega las tres columnas, cada una con su propio guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'plan_ia_nombre')) {
                /* Nombre del paquete de IA, para mostrar en el footer. */
                $table->string('plan_ia_nombre')->nullable();
            }

            if (!Schema::hasColumn('users', 'plan_ia_tope_tokens_mensual')) {
                /* Tope de tokens del mes calendario. Null o 0 = sin tope. */
                $table->unsignedBigInteger('plan_ia_tope_tokens_mensual')->nullable();
            }

            if (!Schema::hasColumn('users', 'plan_ia_tope_interacciones_diarias')) {
                /* Tope de interacciones (mensajes de chat) por día. Null o 0 = sin tope. */
                $table->unsignedInteger('plan_ia_tope_interacciones_diarias')->nullable();
            }
        });
    }

    /**
     * Elimina las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'plan_ia_nombre')) {
                $table->dropColumn('plan_ia_nombre');
            }

            if (Schema::hasColumn('users', 'plan_ia_tope_tokens_mensual')) {
                $table->dropColumn('plan_ia_tope_tokens_mensual');
            }

            if (Schema::hasColumn('users', 'plan_ia_tope_interacciones_diarias')) {
                $table->dropColumn('plan_ia_tope_interacciones_diarias');
            }
        });
    }
}
