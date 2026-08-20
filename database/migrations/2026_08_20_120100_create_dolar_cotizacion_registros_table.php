<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cotizaciones del dólar usadas por cada comercio (misión cotizacion-dolar).
 *
 * 🔴 POR QUÉ EXISTE, Y POR QUÉ NO ALCANZA CON LO QUE YA HAY:
 *
 * 1. Se pidió textual: "tiene que quedar registro qué cotización fue la que se utilizó". Las siete
 *    columnas nuevas de `users` responden "cuál está vigente hoy", no "cuáles usó".
 *
 * 2. `price_update_runs` (2026_08_11_150000) parece cubrirlo —tiene origen, origen_detalle y
 *    timestamps— pero SOLO NACE CUANDO EL DÓLAR CAMBIÓ y se despachó un recálculo. Si el usuario
 *    reconfirma el mismo valor, o cambia solo la casa de referencia, no queda rastro. Un registro
 *    que se saltea casos no es un registro.
 *
 * 3. `price_update_runs.origen_detalle` es un string(255) de TEXTO PARA QUE LO LEA UNA PERSONA (así
 *    lo usa PriceUpdateRunHelper). Meterle ahí una tripleta parseable por máquina es exactamente la
 *    "columna derivada más angosta que su fuente" que el repo ya se cansó de documentar, y acopla
 *    esta funcionalidad al texto de la notificación de recálculo.
 *
 * 4. `UserProfileChangeDescriptionHelper` rastrea `dollar` con la etiqueta 'Cotización del dólar',
 *    pero ARMA UN TEXTO PARA UN BROADCAST: no persiste nada consultable.
 *
 * 🔴 NO AGREGAR UNA COLUMNA `price_update_run_id`. `PriceUpdateRunHelper::abrir()` se llama adentro
 * de `ProcessSetFinalPrices::handle()`, o sea DESPUÉS del dispatch(): el controller nunca conoce el
 * id, y la columna quedaría siempre en null. El vínculo con la corrida se hace por
 * `price_update_runs.origen = 'dolar'` + `origen_detalle`, que sí se llenan.
 *
 * Sin foreign keys físicas, igual que el resto del schema.
 */
class CreateDolarCotizacionRegistrosTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('dolar_cotizacion_registros')) {
            return;
        }

        Schema::create('dolar_cotizacion_registros', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /*
             * 🔴 SIEMPRE EL ID DEL OWNER. El historial es del comercio, no de la persona que
             * apretó el botón: la cotización con la que se costea es una sola por empresa.
             * unsignedBigInteger y no integer porque users.id es id() -> bigint unsigned, y una
             * columna derivada más angosta que su fuente es la clase de error que el repo ya
             * documenta (APRENDER_NO_PARCHEAR.md).
             */
            $table->unsignedBigInteger('user_id');

            /*
             * Quién lo hizo: el dueño, o el empleado con admin_access que operó en su nombre.
             * Nullable porque un registro escrito por un camino sin sesión (comando, import)
             * no tiene una persona detrás, y mentir un id ahí sería peor que dejarlo vacío.
             */
            $table->unsignedBigInteger('auth_user_id')->nullable();

            /* Cómo se obtuvo el valor: 'blue' / 'oficial' / 'mep' / 'manual'. */
            $table->string('origen', 20);

            /*
             * Casa de referencia contra la que se mide la variación, y su punta. Nullable las dos:
             * un dólar cargado a mano desde el formulario de Configuración no tiene ninguna casa
             * elegida, y guardar una inventada haría que la próxima comparación mida contra algo
             * que el usuario nunca eligió.
             */
            $table->string('casa', 20)->nullable();
            $table->string('punta', 10)->nullable();

            /* El valor que quedó en users.dollar. Mismo tipo que su fuente: decimal(10,2). */
            $table->decimal('valor_dolar', 10, 2);

            /*
             * Cuánto valía casa+punta en ese momento. Nullable por el mismo motivo que `casa`:
             * sin referencia no hay valor de referencia.
             */
            $table->decimal('valor_cotizacion', 10, 2)->nullable();

            /* El users.dollar de antes. Nullable: la primera vez no hay anterior. */
            $table->decimal('valor_dolar_anterior', 10, 2)->nullable();

            /*
             * Variación respecto del valor anterior. 🔴 NULL CUANDO NO SE PUDO MEDIR (no había
             * anterior, o era 0). Nunca un 0 de consuelo: "no se pudo medir" y "no varió" son dos
             * cosas distintas y tienen que quedar distinguibles en la base. decimal(8,2) y no
             * (5,2) porque una variación desde un valor viejo muy chico puede pasar el 999,99%.
             */
            $table->decimal('variacion_porcentaje', 8, 2)->nullable();

            /* Qué disparó el registro: 'login' / 'configuracion' / 'formulario'. */
            $table->string('disparo', 20);

            $table->timestamps();

            /*
             * Único índice: la única lectura prevista es "el historial de ESTE comercio, del más
             * nuevo al más viejo", así que este índice cubre el WHERE user_id = ? + ORDER BY
             * created_at DESC de una sola pasada. Ninguno más: cada índice es un árbol que se
             * actualiza en cada insert.
             */
            $table->index(['user_id', 'created_at'], 'dolar_registros_user_created_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dolar_cotizacion_registros');
    }
}
