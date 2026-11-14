<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las planillas de ventas que el dueño le manda al asistente, como foto o como texto pegado (misión
 * agente-venta-por-planilla, 02/11/2026).
 *
 * 🔴 POR QUÉ UNA TABLA. El caso real, en un cliente, el 31/10/2026: el dueño mandó la misma planilla
 * varias veces, el modelo la releyó distinto en cada turno y terminó cargando ventas duplicadas y
 * cruzadas. La lista vivía en la memoria del modelo y nada comparaba lo que se iba a vender con la
 * planilla. Desde acá la planilla se lee UNA vez, se guarda en esta tabla, y todo sale de la tabla por
 * código.
 *
 * `filas` es un json con una entrada por renglón: `numero`, `celdas` (lo que decía la planilla), `valores`
 * (las mismas celdas por rol), `dudas`, `articulo_id`, `cliente_id`, `cliente_nuevo`, `estado`
 * (pendiente | ya_registrada | registrada | descartada | bloqueada), `venta_numero` y `motivo`. Va en un
 * json y no en una tabla hija porque un renglón no se consulta suelto: la planilla se lee y se escribe
 * entera, con el candado de su fila.
 *
 * `definiciones` no estaba en el plan de la misión: guarda la caja, la sucursal y el tipo de venta cuando
 * el negocio obliga a elegirlos; sin eso `proponer_ventas_de_planilla` no podría cerrar en esas cuentas.
 *
 * Aditiva, sin claves foráneas y con `hasTable`: correrla dos veces no rompe, y los índices llevan nombre
 * corto para no pasar el largo que acepta MySQL.
 */
class CreateAiPlanillasTable extends Migration
{
    /**
     * Crea la tabla si falta.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_planillas')) {
            return;
        }

        Schema::create('ai_planillas', function (Blueprint $table) {

            $table->bigIncrements('id');

            // La conversación donde nació la planilla (ai_conversations.id, sin FK).
            $table->unsignedBigInteger('ai_conversation_id');

            // El dueño de la cuenta (users.id con owner_id null). Por tener esta columna, la tabla va
            // en EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS: la consulta genérica no la tiene que ver.
            $table->unsignedBigInteger('user_id');

            // El mensaje del dueño que trajo la planilla (ai_messages.id, sin FK).
            $table->unsignedBigInteger('ai_message_id')->nullable();

            // foto | texto: de dónde salió la primera lectura.
            $table->string('origen', 10)->default('foto');

            // Los títulos de las columnas, si los había.
            $table->json('columnas')->nullable();

            // Qué columna es cada rol (importe, código, documento...).
            $table->json('roles')->nullable();

            // Los renglones: ver el docblock de la clase.
            $table->json('filas');

            // total | unidad: si el importe es el total del renglón o el precio de una unidad. Null: no se sabe.
            $table->string('importe_es', 10)->nullable();

            // El nombre del método de pago que dijo la persona.
            $table->string('metodo_de_pago', 120)->nullable();

            // contado | cuenta_corriente.
            $table->string('cobro', 20)->nullable();

            // Caja, sucursal y tipo de venta, solo si el negocio obliga a elegirlos.
            $table->json('definiciones')->nullable();

            // De dónde salió cada lectura: modelos, desacuerdos, tokens y las fotos y mensajes que entraron.
            $table->json('lectura')->nullable();

            // activa | cerrada | reemplazada.
            $table->string('estado', 15)->default('activa');

            $table->timestamps();

            $table->index(['ai_conversation_id', 'estado'], 'ai_planillas_conv_idx');
            $table->index(['user_id', 'estado'], 'ai_planillas_user_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_planillas');
    }
}
