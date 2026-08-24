<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de snapshots diarios de saldo POR ENTIDAD (cliente/proveedor × moneda),
 * el cimiento del historial crediticio (parte C de la misión de sugerencias
 * inteligentes; la UI y el uso en sugerencias de compra llegan en la misión B).
 *
 * A diferencia de `debt_snapshots` (que guarda 4 totales agregados por usuario y
 * día, todos los días), acá se escribe una fila SOLO cuando el saldo de esa
 * credit_account cambió respecto de su último snapshot: 1000 entidades × 2
 * monedas × 365 días serían 730k filas/año si se guardara todo. La regla de
 * lectura que eso impone (el saldo al día d es el último snapshot con date <= d;
 * sin snapshot previo, 0) vive en el modelo CreditAccountSnapshot.
 *
 * model_name / model_id / moneda_id vienen denormalizados de credit_accounts
 * para que el historial de una entidad se consulte sin join.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateCreditAccountSnapshotsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('credit_account_snapshots')) {
            return;
        }

        Schema::create('credit_account_snapshots', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* ID del usuario dueño del negocio (owner_id null en users) */
            $table->integer('user_id');

            /* Cabecera de saldo de la entidad (credit_accounts.id) */
            $table->integer('credit_account_id');

            /* Fecha a la que corresponde el snapshot (el día capturado) */
            $table->date('date');

            /* Saldo de la credit_account ese día (misma precisión que credit_accounts.saldo) */
            $table->decimal('saldo', 22, 2);

            /* 'client' o 'provider' (denormalizado de credit_accounts.model_name) */
            $table->string('model_name', 20);

            /* ID del cliente o proveedor (denormalizado de credit_accounts.model_id) */
            $table->integer('model_id');

            /* 1 = ARS, 2 = USD (denormalizado de credit_accounts.moneda_id) */
            $table->integer('moneda_id');

            $table->timestamps();

            /* Un snapshot por cuenta por día: es lo que vuelve idempotente al comando */
            $table->unique(['credit_account_id', 'date'], 'credit_account_snapshots_account_date_unique');

            /* Barrido de todos los saldos de un comercio a una fecha */
            $table->index(['user_id', 'date'], 'credit_account_snapshots_user_date_index');

            /* Historial de una entidad puntual (cliente/proveedor × moneda) sin join */
            $table->index(['model_name', 'model_id', 'moneda_id', 'date'], 'credit_account_snapshots_model_moneda_date_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('credit_account_snapshots');
    }
}
