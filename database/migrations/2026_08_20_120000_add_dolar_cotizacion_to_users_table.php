<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: cotización del dólar al iniciar sesión (misión cotizacion-dolar).
 *
 * Siete columnas en `users` que describen QUÉ cotización eligió el dueño del comercio, CONTRA QUÉ
 * se mide la variación y CUÁNDO quiere que le avisemos. `users.dollar` no se toca: sigue siendo la
 * única fuente de verdad del valor con el que se costea.
 *
 * - dolar_cotizacion_origen: cómo se obtuvo el valor ('blue', 'oficial', 'mep', 'manual').
 *   🔴 NULL significa NUNCA ELIGIÓ NADA, que NO es lo mismo que 'manual'. De esa distinción
 *   depende que el modal del login no le salte en la cara a las ~40 cuentas existentes: el modal
 *   exige origen != null, y toda cuenta vieja arranca en null.
 *
 * - dolar_cotizacion_casa: la casa DE REFERENCIA contra la que se mide la variación. Con un origen
 *   preestablecido es igual a `origen`; con origen 'manual' es la casa que el usuario eligió para
 *   comparar.
 *
 * - dolar_cotizacion_punta: 'compra' o 'venta' de esa casa de referencia.
 *
 * - dolar_cotizacion_valor: 🔴 CUÁNTO VALÍA casa+punta EN EL MOMENTO DE LA ELECCIÓN, y por eso NO
 *   es redundante con `users.dollar`. Con origen preestablecido los dos coinciden por
 *   construcción; con origen 'manual' no: el usuario tipea 1.600 (eso va a users.dollar) y elige
 *   comparar contra Blue venta, que ese día valía 1.560 (eso va acá). Si la comparación se hiciera
 *   contra users.dollar, el modal le saltaría en el próximo login por una variación del 2,5% que
 *   él mismo acaba de crear tipeando. Con esta separación la fórmula de comparación es UNA sola
 *   para los cuatro orígenes:
 *       variacion% = (cotizacion_hoy[casa][punta] - dolar_cotizacion_valor) / dolar_cotizacion_valor * 100
 *
 * - dolar_cotizacion_actualizada_at: cuándo eligió. Alimenta el "actualizaste la cotización la
 *   última vez el …" del modal.
 *
 * - dolar_avisar_cambios: el checkbox "avisarme cuando cambie". Default 1 porque es la
 *   funcionalidad que se pidió y el usuario la apaga si le molesta; y no le cambia nada a ninguna
 *   cuenta existente, porque el modal del login TAMBIÉN exige dolar_cotizacion_origen != null.
 *
 * - dolar_variacion_minima: porcentaje mínimo para que el modal aparezca al iniciar sesión.
 *   Default 1.00. decimal(5,2) acepta de 0,01 a 999,99; la validación del endpoint la acota a
 *   [0.01, 100]. 🔴 Un 0 haría aparecer el modal en cada login ante cualquier movimiento: si una
 *   fila llegara con 0.00 (cuenta vieja, import a mano), el servicio la trata como 1.00.
 *
 * Sin foreign keys, longitudes explícitas en los string, guards hasColumn en las dos direcciones.
 */
class AddDolarCotizacionToUsersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega cada columna solo si no existe (guards hasColumn para que sea
     * segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('users', 'dolar_cotizacion_origen')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('dolar_cotizacion_origen', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'dolar_cotizacion_casa')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('dolar_cotizacion_casa', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'dolar_cotizacion_punta')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('dolar_cotizacion_punta', 10)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'dolar_cotizacion_valor')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('dolar_cotizacion_valor', 10, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'dolar_cotizacion_actualizada_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('dolar_cotizacion_actualizada_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'dolar_avisar_cambios')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('dolar_avisar_cambios')->default(1);
            });
        }

        if (! Schema::hasColumn('users', 'dolar_variacion_minima')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('dolar_variacion_minima', 5, 2)->default(1.00);
            });
        }
    }

    /**
     * Revierte la migración: elimina cada columna solo si existe.
     *
     * @return void
     */
    public function down()
    {
        $columnas = [
            'dolar_cotizacion_origen',
            'dolar_cotizacion_casa',
            'dolar_cotizacion_punta',
            'dolar_cotizacion_valor',
            'dolar_cotizacion_actualizada_at',
            'dolar_avisar_cambios',
            'dolar_variacion_minima',
        ];

        foreach ($columnas as $columna) {
            if (Schema::hasColumn('users', $columna)) {
                Schema::table('users', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
