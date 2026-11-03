<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cola de avisos para «El local», el módulo animado del admin (misión el-local-fase-2, 3/11/2026).
 *
 * Cada fila es un evento (login, actividad, logout, escaneo, agente_negocio) que TODAVÍA no llegó al admin:
 * `LocalActividad::registrar()` la crea sin tocar la red y el comando `local:enviar-actividad` la manda y la
 * BORRA. No es un historial: lo normal es que esté vacía.
 *
 * 🔴 `owner_id` y NO `user_id`, a propósito. `EsquemaDeDatosIaHelper` deriva como entidad consultable por el
 * asistente toda tabla con columna `user_id` que no esté en su lista negra, y esto no es un dato del negocio que
 * se lea desde el chat. Es el mismo motivo que en `acceso_soporte_tokens` y `ai_avisos_del_asistente`.
 *
 * `ocurrido_at` es `datetime` y no `timestamp`: en MySQL un `timestamp` NOT NULL puede auto-actualizarse en cada
 * UPDATE, y acá cada reintento hace un UPDATE de `intentos`. Una hora que se mueve sola le mentiría al admin.
 *
 * Sin claves foráneas (regla del repo). Aditiva y con `hasTable`: correrla dos veces no rompe.
 */
class CreateLocalActividadEventosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('local_actividad_eventos')) {
            return;
        }

        Schema::create('local_actividad_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('tipo', 20);
            $table->string('usuario', 120)->nullable();
            $table->text('detalle')->nullable();
            $table->dateTime('ocurrido_at');
            $table->unsignedTinyInteger('intentos')->default(0);

            $table->index(['owner_id', 'id'], 'lae_owner_id_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('local_actividad_eventos');
    }
}
