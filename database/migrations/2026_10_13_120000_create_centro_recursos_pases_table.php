<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pases de un solo uso para entrar al centro de recursos con el usuario del sistema (misión
 * centro-de-recursos-clientes, 13/10/2026).
 *
 * El usuario toca "Centro de recursos" en el menú, empresa-api crea un pase acá y le arma la URL de
 * la página (`?pase=…&api=…`). La página se lo manda al admin, y el admin lo canjea llamando a ESTA
 * API (`POST api/admin-sync/recursos/canjear-pase`). El molde es `version_session_transfers`.
 *
 * - `user_id`: quien tocó el botón (dueño o empleado). Es la persona, no el dueño: el centro guarda
 *   el avance de cada uno.
 * - `token_hash`: sha256 del pase. El pase en claro solo viaja en la URL; acá nunca se guarda.
 * - `video`: código del video con el que se abre la página (ej. `T1.29`), si vino.
 * - `expires_at`: 5 minutos después de crearse.
 * - `usado_at`: se llena en el canje. El canje es un UPDATE condicionado a `usado_at IS NULL`, así
 *   que dos canjes simultáneos del mismo pase no pueden ganar los dos.
 *
 * 🔴 `expires_at` y `usado_at` son DATETIME y no TIMESTAMP a propósito: en un MariaDB con
 * `explicit_defaults_for_timestamp` apagado (el default antes de la 10.10), la primera columna
 * TIMESTAMP NOT NULL de la tabla se lleva `ON UPDATE CURRENT_TIMESTAMP` y el canje le pisaría el
 * vencimiento. Con DATETIME eso no pasa en ningún motor.
 *
 * Sin foreign keys (regla del repo) y con nombres de índice cortos.
 */
class CreateCentroRecursosPasesTable extends Migration
{
    /**
     * Crea la tabla de pases del centro de recursos.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('centro_recursos_pases')) {
            return;
        }

        Schema::create('centro_recursos_pases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64);
            $table->string('video', 20)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('usado_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'centro_rec_pases_hash_uq');
            $table->index('user_id', 'centro_rec_pases_user_idx');
        });
    }

    /**
     * Saca la tabla. Los pases viven 5 minutos: no se pierde nada que importe.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('centro_recursos_pases');
    }
}
