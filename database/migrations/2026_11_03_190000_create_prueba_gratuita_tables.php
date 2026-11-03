<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las tres tablas de la «Prueba gratuita de 7 días» (misión prueba-experiencia-empresa, 3/11/2026).
 *
 * - `semilla_registros`: la marca de lo que sembraron el demo setup y `semilla:datos`. Una fila por registro
 *   sembrado (`tabla` + `registro_id`), agrupada por `lote`. Es lo que permite borrar TODO lo de ejemplo al
 *   primer import sin tocar lo que el negocio cargó después (ver SemillaRegistroHelper).
 * - `prueba_config`: una fila por dueño con la configuración de la prueba que mandó el admin en el demo
 *   setup (días, video de bienvenida, WhatsApp de soporte) y el avance de la experiencia (modal visto,
 *   origen de la primera importación, cuándo se borró la semilla). El VENCIMIENTO no vive acá: es
 *   `users.expired_at` (la columna que `UserHelper::checkUserTrial()` ya leía sin que nadie la usara).
 * - `prueba_hitos`: el primer momento de cada hito de la prueba por dueño (unique owner_id + tipo). Es lo
 *   que garantiza que cada tipo se emita UNA sola vez al admin y lo que contesta `admin-sync/prueba/estado`.
 *
 * 🔴 `owner_id` y NO `user_id`, a propósito: `EsquemaDeDatosIaHelper` expone al asistente toda tabla con
 * columna `user_id` que no esté en su lista negra, y nada de esto es un dato del negocio que se consulte
 * desde el chat (mismo criterio que `local_actividad_eventos` y `acceso_soporte_tokens`).
 *
 * Sin claves foráneas (regla del repo). Índices cortos: `tabla` es varchar(60). Aditiva y con `hasTable`:
 * correrla dos veces no rompe.
 */
class CreatePruebaGratuitaTables extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('semilla_registros')) {
            Schema::create('semilla_registros', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('owner_id');
                $table->string('tabla', 60);
                $table->unsignedBigInteger('registro_id');
                $table->string('lote', 60);
                $table->dateTime('created_at')->nullable();

                $table->index(['owner_id', 'tabla'], 'sr_owner_tabla_idx');
                $table->index(['tabla', 'registro_id'], 'sr_tabla_registro_idx');
            });
        }

        if (!Schema::hasTable('prueba_config')) {
            Schema::create('prueba_config', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('owner_id');
                $table->unsignedSmallInteger('dias')->nullable();
                $table->string('video_url', 500)->nullable();
                $table->string('whatsapp_soporte', 40)->nullable();
                $table->dateTime('modal_visto_at')->nullable();
                $table->string('importacion_origen', 20)->nullable();
                $table->dateTime('semilla_borrada_at')->nullable();
                $table->timestamps();

                $table->unique('owner_id', 'pc_owner_unq');
            });
        }

        if (!Schema::hasTable('prueba_hitos')) {
            Schema::create('prueba_hitos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('owner_id');
                $table->string('tipo', 40);
                $table->dateTime('ocurrido_at');
                $table->dateTime('created_at')->nullable();

                $table->unique(['owner_id', 'tipo'], 'ph_owner_tipo_unq');
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('prueba_hitos');
        Schema::dropIfExists('prueba_config');
        Schema::dropIfExists('semilla_registros');
    }
}
