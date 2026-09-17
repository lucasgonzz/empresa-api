<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de que el informe de la mañana ya se le avisó al dueño por WhatsApp (misión
 * asistente-por-whatsapp, 16/9/2026).
 *
 * El admin corre un comando a las 8:30, pide los informes del día con
 * `GET admin-sync/asistente/informes-pendientes` (los 'listo' de hoy con esta columna en null) y,
 * SOLO si el WhatsApp salió, vuelve con `POST admin-sync/asistente/informes/{id}/avisado`.
 *
 * 🔴 Son dos pasos a propósito y la marca vive de ESTE lado. Si el envío falla (ventana de 24 h
 * cerrada, plantilla no aprobada, Kapso caído), el informe no queda marcado y el aviso sale la
 * próxima corrida. Y como la marca la escribe el cliente, el comando del admin es idempotente sin
 * guardar estado propio: correrlo dos veces el mismo día no le manda el informe dos veces al dueño.
 *
 * Nullable y sin default: todos los informes que ya existen quedan "sin avisar", que es lo
 * correcto — nadie les mandó nada.
 */
class AddAvisadoAtToMostradorReportesTable extends Migration
{
    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('mostrador_reportes') || Schema::hasColumn('mostrador_reportes', 'avisado_at')) {
            return;
        }

        Schema::table('mostrador_reportes', function (Blueprint $table) {

            /* Cuándo se le avisó al dueño por WhatsApp. Null = todavía no */
            $table->timestamp('avisado_at')->nullable()->after('leido_at');
        });
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('mostrador_reportes') || !Schema::hasColumn('mostrador_reportes', 'avisado_at')) {
            return;
        }

        Schema::table('mostrador_reportes', function (Blueprint $table) {
            $table->dropColumn('avisado_at');
        });
    }
}
