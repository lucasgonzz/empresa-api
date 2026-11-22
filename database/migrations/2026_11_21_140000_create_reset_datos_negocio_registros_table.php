<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada simulación y ejecución del reset de datos de negocio (misión reset-datos-cliente, 21/11/2026).
 *
 * Es una tabla de SISTEMA: el reset no la vacía nunca (ver CatalogoDeTablasDelResetHelper). Guarda quién pidió qué,
 * desde dónde, cuánto se vació y dónde quedó el respaldo; de los tokens de simulación guarda sólo el sha256.
 *
 * Sin foreign keys (convención del proyecto) y con índices de nombre corto.
 */
class CreateResetDatosNegocioRegistrosTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('reset_datos_negocio_registros')) {
            return;
        }

        Schema::create('reset_datos_negocio_registros', function (Blueprint $table) {
            $table->bigIncrements('id');
            // simulacion | ejecucion
            $table->string('modo', 20)->default('simulacion');
            // en_curso | ok | ok_con_advertencias | rechazado | error
            $table->string('estado', 30)->default('en_curso');
            // Código del contrato (en_curso, varios_duenos, respaldo_fallido...) cuando no salió todo bien.
            $table->string('codigo', 60)->nullable();
            // Nombre de la base sobre la que se pidió (los tokens de simulación valen sólo para esa base).
            $table->string('base', 120)->default('');
            // sha256 del token de simulación (nunca el token). Sólo en filas de modo `simulacion` con token emitido.
            $table->string('token_hash', 64)->nullable();
            $table->dateTime('token_vence_at')->nullable();
            $table->dateTime('token_usado_at')->nullable();
            $table->string('solicitante', 60)->default('admin');
            $table->string('origen', 20)->default('admin');
            $table->string('motivo', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 120)->nullable();
            // JSON tabla => filas (lo que se vaciaría o lo que se vació).
            $table->longText('conteos')->nullable();
            $table->unsignedBigInteger('total_filas')->default(0);
            $table->string('respaldo_ruta', 255)->nullable();
            $table->unsignedBigInteger('respaldo_bytes')->default(0);
            $table->string('respaldo_sha256', 64)->nullable();
            // JSON con ajustes, archivos movidos, verificación y bloqueos.
            $table->longText('resultado')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['modo', 'created_at'], 'rdn_modo_created_idx');
            $table->index('token_hash', 'rdn_token_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('reset_datos_negocio_registros');
    }
}
