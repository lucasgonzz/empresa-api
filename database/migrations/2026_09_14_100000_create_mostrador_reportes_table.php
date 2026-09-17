<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informes del mostrador del módulo IA (misión modulo-ia-mostrador, 14/9/2026).
 *
 * Una fila por (dueño, tipo, fecha). El API calcula los HECHOS (JSON determinista,
 * `hechos`, estado 'hechos') y la skill /mostrador, corrida desde Claude Code, deposita
 * el TEXTO (`contenido`, JSON de bloques validado por MostradorContenidoValidator,
 * estado 'listo'). El escritorio del dueño muestra solo los 'listo'.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema. Índices con
 * nombre corto: la unique es lo que vuelve idempotente el upsert de POST hechos.
 */
class CreateMostradorReportesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('mostrador_reportes')) {
            return;
        }

        Schema::create('mostrador_reportes', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* ID del dueño de la cuenta (users con owner_id null) */
            $table->unsignedBigInteger('user_id');

            /* 'dia' | 'tienda' | 'compras' | 'stock' */
            $table->string('tipo', 20);

            /* Día del que habla el informe (dia y tienda: ayer; compras y stock: hoy) */
            $table->date('fecha');

            /* Título que deposita la skill */
            $table->string('titulo', 120)->nullable();

            /* Una línea para la carpeta del escritorio; la deposita la skill */
            $table->string('resumen', 300)->nullable();

            /* JSON de hechos calculado por el API */
            $table->longText('hechos')->nullable();

            /* JSON de bloques depositado por la skill (ver MostradorContenidoValidator) */
            $table->longText('contenido')->nullable();

            /* 'hechos' (calculado, sin texto) | 'listo' (depositado, visible en el escritorio) */
            $table->string('estado', 20)->default('hechos');

            /* Cuándo se calcularon los hechos */
            $table->timestamp('hechos_at')->nullable();

            /* Cuándo la skill depositó el contenido */
            $table->timestamp('generado_at')->nullable();

            /* Primera apertura por el dueño */
            $table->timestamp('leido_at')->nullable();

            $table->timestamps();

            /* Un informe por dueño, tipo y día: es la clave del upsert de POST hechos */
            $table->unique(['user_id', 'tipo', 'fecha'], 'mr_user_tipo_fecha_unique');

            /* El escritorio: los 'listo' de un dueño por fecha */
            $table->index(['user_id', 'estado', 'fecha'], 'mr_user_estado_fecha_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mostrador_reportes');
    }
}
