<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAgenteConsolidaciones extends Migration
{
    public function up()
    {
        // Guarda de reintento: la tabla se crea sólo si falta (vuelta atrás que dejó tablas sin la fila de migrations).
        if (!Schema::hasTable('agente_consolidacion_estados')) {
            Schema::create('agente_consolidacion_estados', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->primary();
                $t->date('fecha_descubierta')->nullable();
                $t->string('proyeccion_hash', 64)->nullable();
                $t->string('detalle_hash', 64)->nullable();
                $t->unsignedBigInteger('bloqueada_hasta_id')->default(0);
                $t->timestamps();
            });
        }
        // Guarda de reintento: la tabla se crea sólo si falta (vuelta atrás que dejó tablas sin la fila de migrations).
        if (!Schema::hasTable('agente_consolidaciones')) {
            Schema::create('agente_consolidaciones', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('ai_conversation_id');
                $t->date('fecha');
                $t->unsignedBigInteger('ultimo_mensaje_id')->default(0);
                $t->unsignedInteger('desplazamiento')->default(0);
                $t->unsignedInteger('tamano_lote')->default(18000);
                $t->string('estado', 20)->default('pendiente');
                $t->string('error', 200)->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'ai_conversation_id', 'fecha'], 'agente_consolidacion_unica');
            });
        }
        // Guarda de reintento: la tabla se crea sólo si falta (vuelta atrás que dejó tablas sin la fila de migrations).
        if (!Schema::hasTable('agente_consolidacion_memorias')) {
            Schema::create('agente_consolidacion_memorias', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('user_id');
                $t->string('tema', 80);
                $t->text('instruccion');
                $t->text('evidencia');
                $t->unsignedBigInteger('mensaje_id');
                $t->unsignedBigInteger('ai_conversation_id');
                $t->timestamps();
                $t->string('bloque_hash', 64)->nullable();
                $t->unsignedInteger('mensaje_desplazamiento')->default(0);
                $t->unsignedBigInteger('bloqueada_hasta_id')->default(0);
                $t->string('estado', 24)->default('activa');
                $t->unique(['user_id', 'tema'], 'agente_memoria_tema');
            });
        }
        // Guarda de reintento: la tabla se crea sólo si falta (vuelta atrás que dejó tablas sin la fila de migrations).
        if (!Schema::hasTable('agente_consolidacion_hallazgos')) {
            Schema::create('agente_consolidacion_hallazgos', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('ai_conversation_id');
                $t->unsignedBigInteger('mensaje_id');
                $t->date('fecha');
                $t->string('clase', 30)->default('mejora_producto');
                $t->string('huella', 64);
                $t->text('descripcion');
                $t->text('evidencia');
                $t->string('estado', 20)->default('pendiente');
                $t->timestamps();
                $t->index(['user_id', 'created_at'], 'agente_hallazgo_fecha');
                $t->unique(['user_id', 'mensaje_id', 'huella'], 'agente_hallazgo_unico');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('agente_consolidacion_hallazgos');
        Schema::dropIfExists('agente_consolidacion_memorias');
        Schema::dropIfExists('agente_consolidaciones');
        Schema::dropIfExists('agente_consolidacion_estados');
    }
}
