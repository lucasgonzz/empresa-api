<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una tarea de la Agenda puede pedir un recordatorio por WhatsApp a una hora (misión
 * recordatorios-whatsapp, 16/10/2026).
 *
 * Pedido de Lucas: que «recordame el viernes a las 10 que…» le llegue al dueño por WhatsApp a esa
 * hora. Hasta hoy la hora quedaba escrita adelante del detalle («10:00 · Llamar a…») y nada más: el
 * asistente le decía al modelo que NO había aviso. El admin ahora consulta cada 5 minutos los
 * recordatorios vencidos (GET admin-sync/asistente/recordatorios-pendientes), los manda por el
 * número del asistente y recién después los sella (POST admin-sync/asistente/recordatorios/avisado).
 *
 * Las tres columnas:
 *   - `hora_aviso`: la hora del recordatorio. NO la escribe nadie a mano: la deriva
 *     AgendaTareaHelper::validar() del prefijo «HH:MM · » del detalle, así que si el dueño cambia o
 *     borra la hora desde la Agenda, el recordatorio la sigue (ver el docblock de validar()).
 *   - `avisar_por_whatsapp`: 1 solo si la tarea con hora la confirmó EL DUEÑO desde el asistente. Una
 *     tarea creada desde la pantalla, o por un empleado, no le manda un WhatsApp al teléfono del dueño.
 *   - `avisado_at`: el último aviso que salió. Es una marca POR DÍA y no "avisado sí/no": una
 *     recurrente se avisa una vez por cada ocurrencia, y una puntual que se movió de día después de
 *     avisada se vuelve a avisar el día nuevo.
 *
 * 🔴 Aditiva y sin FK, como el resto del esquema: `tienda-api` comparte la base y no lee `pendings`
 * (verificado con grep el 16/10/2026), y la SPA no manda ninguna de las tres. Las tareas que ya
 * existen quedan con `avisar_por_whatsapp = 0`: desplegar esto no dispara ningún WhatsApp.
 */
class AddRecordatorioToPendingsTable extends Migration
{
    /**
     * Agrega las tres columnas y el índice de la consulta del admin, cada una con su guard
     * hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('pendings')) {
            return;
        }

        if (!Schema::hasColumn('pendings', 'hora_aviso')) {

            Schema::table('pendings', function (Blueprint $table) {

                /* La hora del recordatorio, derivada del prefijo «HH:MM · » del detalle; null = sin hora */
                $table->time('hora_aviso')->nullable()->after('fecha_fin_recurrencia');
            });
        }

        if (!Schema::hasColumn('pendings', 'avisar_por_whatsapp')) {

            Schema::table('pendings', function (Blueprint $table) {

                /* 1 = el dueño la confirmó desde el asistente y a esa hora le llega un WhatsApp */
                $table->boolean('avisar_por_whatsapp')->default(0)->after('hora_aviso');
            });
        }

        if (!Schema::hasColumn('pendings', 'avisado_at')) {

            Schema::table('pendings', function (Blueprint $table) {

                /* Cuándo salió el último recordatorio; se compara por día (marca por ocurrencia) */
                $table->timestamp('avisado_at')->nullable()->after('avisar_por_whatsapp');

                /*
                 * Lo que consulta el admin cada 5 minutos: las tareas del dueño que piden aviso.
                 * El resto del filtro (hora, día, ocurrencia) se resuelve en memoria sobre pocas filas.
                 */
                $table->index(['user_id', 'avisar_por_whatsapp'], 'pendings_recordatorio_idx');
            });
        }
    }

    /**
     * Quita las tres columnas con su índice.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('pendings')) {
            return;
        }

        /*
         * El índice se mira por su nombre y no por la columna: un up() cortado a la mitad puede haber
         * dejado `avisado_at` sin el índice (o al revés), y un dropIndex de algo que no existe voltea
         * el rollback entero.
         */
        if (!empty(DB::select("SHOW INDEX FROM pendings WHERE Key_name = 'pendings_recordatorio_idx'"))) {

            Schema::table('pendings', function (Blueprint $table) {
                $table->dropIndex('pendings_recordatorio_idx');
            });
        }

        if (Schema::hasColumn('pendings', 'avisado_at')) {

            Schema::table('pendings', function (Blueprint $table) {
                $table->dropColumn('avisado_at');
            });
        }

        if (Schema::hasColumn('pendings', 'avisar_por_whatsapp')) {

            Schema::table('pendings', function (Blueprint $table) {
                $table->dropColumn('avisar_por_whatsapp');
            });
        }

        if (Schema::hasColumn('pendings', 'hora_aviso')) {

            Schema::table('pendings', function (Blueprint $table) {
                $table->dropColumn('hora_aviso');
            });
        }
    }
}
