<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presencia de las "computadoras" conectadas, para el pedido de OK de la actualización del sistema
 * (misión aviso-actualizacion-sistema, 25/11/2026, plan §4.2).
 *
 * Una computadora es una sesión de Laravel (un navegador; sus pestañas comparten sesión). Se guarda
 * `sesion = hash('sha256', session()->getId())`, nunca el id en claro. `visto_at` lo refresca el polling
 * del SPA (`GET api/actualizacion-del-sistema/estado`); `respuesta` es `pendiente`, `acepto`, `ahora_no`
 * o `expulsado` (no contestó a tiempo) y se llena sólo cuando hay un pedido de OK abierto.
 *
 * Sin foreign keys, con defaults, e índices con nombre corto: uno por sesión (la consulta de cada
 * poll) y otro por dueño y última vez vista (la consulta "quién está conectado ahora").
 */
class CreateActualizacionDispositivosTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe (idempotente).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('actualizacion_dispositivos')) {
            return;
        }

        Schema::create('actualizacion_dispositivos', function (Blueprint $table) {
            $table->id();
            // Dueño del comercio (users.id con owner_id NULL). Sin FK.
            $table->unsignedBigInteger('owner_id')->default(0);
            // Usuario que está sentado en esa computadora (dueño o empleado). Sin FK.
            $table->unsignedBigInteger('user_id')->default(0);
            // sha256 del id de sesión: 64 caracteres hexadecimales.
            $table->string('sesion', 64)->default('');
            // Última vez que esa computadora pidió el estado.
            $table->timestamp('visto_at')->nullable();
            // Pedido de OK al que pertenece la respuesta.
            $table->string('pedido_uuid', 36)->nullable();
            // pendiente | acepto | ahora_no | expulsado.
            $table->string('respuesta', 20)->nullable();
            $table->timestamp('respondio_at')->nullable();
            $table->timestamps();

            $table->index('sesion', 'act_disp_sesion_idx');
            $table->index(['owner_id', 'visto_at'], 'act_disp_owner_visto_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('actualizacion_dispositivos');
    }
}
