<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué proveedor de IA contesta el asistente de cada negocio, configurable POR DUEÑO (misión
 * proveedores-ia-deepseek, 22/9/2026).
 *
 * `agente_proveedor` es la inteligencia que usa el asistente: 'anthropic' (Claude, el de siempre)
 * o 'deepseek' (la alternativa más económica). Se suma a `agente_pensamiento`, que sigue eligiendo
 * la variante adentro del proveedor: con Claude, agil / equilibrado / profundo; con DeepSeek, agil
 * (Flash) o profundo (Pro), y un `equilibrado` guardado cae a agil. Los tres caminos del asistente
 * —el chat del dueño (sistema y WhatsApp), el bot de WhatsApp a clientes y el título de
 * conversación— siguen esta elección; los demás puntos de IA del repo no.
 *
 * El default es 'anthropic': sin tocar la configuración, un negocio queda exactamente como está.
 * Y un escritor viejo de `users` que no conoce la columna sigue andando igual, por el default.
 *
 * Va como columna en `users` del DUEÑO y no en `user_configurations` por el mismo motivo que las
 * otras dos: es del comercio, no de la persona. `User` tiene guarded vacío y sin hidden para esta
 * columna, así que el valor viaja solo en `GET api/user`.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddAgenteProveedorToUsersTable extends Migration
{
    /**
     * Agrega la columna, con su guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'agente_proveedor')) {
                /* 'anthropic' | 'deepseek' — con qué proveedor contesta el asistente. Default 'anthropic'. */
                $table->string('agente_proveedor', 20)->default('anthropic');
            }
        });
    }

    /**
     * Elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'agente_proveedor')) {
                $table->dropColumn('agente_proveedor');
            }
        });
    }
}
