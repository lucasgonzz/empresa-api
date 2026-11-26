<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de actualización del sistema, una fila por dueño (misión aviso-actualizacion-sistema, 25/11/2026).
 *
 * Es el contrato entre empresa-api y tienda-api (plan §4.2): las dos leen la MISMA base y sólo
 * empresa-api crea y escribe esta tabla. tienda-api lee únicamente `estado`, `vence_at` y `owner_id`
 * (`owner_id` = `commerce_id` de la tienda) y bloquea sii `estado = 'actualizando' AND vence_at > NOW()`.
 *
 * Estados: `libre` (o sin fila), `pidiendo_ok`, `rechazado` y `actualizando`. Un `actualizando` con
 * `vence_at` pasado se lee como `libre`: el bloqueo nunca queda para siempre si el pipeline se cae.
 *
 * Sin foreign keys y con defaults en todo lo que no es nullable (tabla nueva, no hay riesgo de
 * "columna obligatoria de golpe"). El índice por dueño tiene nombre corto.
 */
class CreateActualizacionesDelSistemaTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe (idempotente, porque la base la comparten dos frentes).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('actualizaciones_del_sistema')) {
            return;
        }

        Schema::create('actualizaciones_del_sistema', function (Blueprint $table) {
            $table->id();
            // Dueño del comercio (users.id con owner_id NULL). 0 = sin dueño resuelto. Sin FK.
            $table->unsignedBigInteger('owner_id')->default(0);
            // libre | pidiendo_ok | rechazado | actualizando.
            $table->string('estado', 20)->default('libre');
            // Upgrade del admin que originó el estado y versión a la que se actualiza (informativos).
            $table->unsignedBigInteger('upgrade_id')->nullable();
            $table->string('version', 40)->nullable();
            // Identifica el pedido de OK vigente; cada pedido nuevo tiene el suyo.
            $table->string('pedido_uuid', 36)->nullable();
            $table->timestamp('pedido_at')->nullable();
            // Hasta cuándo tienen las computadoras para contestar el pedido.
            $table->timestamp('vence_respuesta_at')->nullable();
            $table->timestamp('rechazado_at')->nullable();
            // Quién apretó "Ahora no" (users.id, sin FK).
            $table->unsignedBigInteger('rechazado_por_user_id')->nullable();
            $table->timestamp('actualizando_desde')->nullable();
            // Vencimiento del estado `actualizando` (y del `pidiendo_ok` ya aceptado).
            $table->timestamp('vence_at')->nullable();
            $table->timestamps();

            $table->index('owner_id', 'act_sis_owner_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('actualizaciones_del_sistema');
    }
}
