<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La importación de clientes y proveedores desde el ticket de soporte, con código y deshacer (misión
 * importaciones-de-martin, 11/11/2026).
 *
 * `importaciones_de_soporte`: una corrida por Excel reenviado por el admin (idempotente por dueño, ticket y
 * clave). Guarda el mapeo que se usó, el resumen que se le mostró al dueño, la huella de ese resumen y la
 * revisión del código de confirmación.
 *
 * `importacion_de_soporte_cambios`: lo que la corrida hizo, para poder deshacerlo. Una fila por cliente o
 * proveedor creado, por cada uno actualizado (con los valores de antes y de después de los campos que
 * cambiaron) y por cada movimiento de cuenta corriente que generó (saldo inicial, nota de crédito o de
 * débito).
 *
 * Aditiva: solo crea las dos tablas.
 */
class CreateImportacionesDeSoporte extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('importaciones_de_soporte')) {
            Schema::create('importaciones_de_soporte', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                // Dueño del comercio: todo se filtra por acá (hay bases compartidas).
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('support_ticket_id');
                // client | provider.
                $table->string('modelo', 20);
                // sha256 de la clave que manda el admin (o del archivo): reenvíos del mismo adjunto.
                $table->string('clave', 64);
                $table->string('archivo', 255);
                $table->string('nombre', 255);
                // analizar | confirmacion | requiere_correccion | importar | terminada | revirtiendo | revertida |
                // error | cancelada.
                $table->string('estado', 20)->default('analizar');
                // Por qué quedó así (filas_ambiguas, no_coincide_con_el_resumen, se_corto, reversion_incompleta).
                $table->string('motivo', 40)->nullable();
                // Veces que la consulta del admin la volvió a despachar (tope 2: nunca entra en ciclo).
                $table->unsignedTinyInteger('relanzamientos')->default(0);
                // Lo que el resumen previó fila por fila: contra esto se controla cada tanda importada.
                $table->longText('previsto')->nullable();
                // Las importaciones grandes van en tandas de filas, cada una en su transacción.
                $table->unsignedInteger('tandas_total')->default(0);
                $table->unsignedInteger('tandas_hechas')->default(0);
                $table->unsignedInteger('tandas_revertidas')->default(0);
                $table->unsignedInteger('revision')->default(0);
                $table->boolean('avisar_por_whatsapp')->default(true);
                $table->unsignedSmallInteger('hoja')->default(0);
                $table->string('hoja_nombre', 120)->nullable();
                $table->unsignedInteger('fila_inicio')->default(2);
                $table->longText('columnas')->nullable();
                $table->longText('resumen')->nullable();
                $table->string('huella', 64)->nullable();
                $table->string('codigo', 16)->nullable();
                $table->longText('resultado')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('confirmada_at')->nullable();
                $table->timestamp('terminada_at')->nullable();
                $table->timestamp('revertida_at')->nullable();
                $table->timestamps();

                // Sin unique: la idempotencia es solo entre corridas vivas (una cancelada o con error se puede reintentar).
                $table->index(['user_id', 'support_ticket_id', 'clave'], 'ids_dueno_ticket_clave_idx');
                $table->index(['user_id', 'estado'], 'ids_dueno_estado_idx');
            });
        }

        if (!Schema::hasTable('importacion_de_soporte_cambios')) {
            Schema::create('importacion_de_soporte_cambios', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('importacion_de_soporte_id');
                // La tanda de filas que lo hizo (se revierte tanda por tanda, de la última a la primera).
                $table->unsignedInteger('tanda')->default(1);
                // creado | actualizado | movimiento.
                $table->string('tipo', 20);
                // client | provider: el modelo de la fila (o el dueño del movimiento).
                $table->string('modelo', 20);
                $table->unsignedBigInteger('modelo_id');
                $table->unsignedBigInteger('current_acount_id')->nullable();
                $table->longText('antes')->nullable();
                $table->longText('despues')->nullable();
                // Qué pasó al revertir: revertido | conservado (con el motivo) | contraasiento.
                $table->string('reversion', 20)->nullable();
                $table->string('motivo_reversion', 190)->nullable();
                $table->timestamps();

                $table->index(['importacion_de_soporte_id', 'tanda', 'tipo'], 'idsc_corrida_tanda_tipo_idx');
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('importacion_de_soporte_cambios');
        Schema::dropIfExists('importaciones_de_soporte');
    }
}
