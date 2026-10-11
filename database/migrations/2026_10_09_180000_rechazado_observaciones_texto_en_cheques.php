<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cheques.rechazado_observaciones` pasa de INT a TEXT NULL (misión cheque-motivo-rechazo,
 * 9/10/2026): es el motivo del rechazo de un cheque, un texto que escribe el usuario.
 *
 * La columna nació ENTERA (`2025_07_08_101522_create_cheques_table`:
 * `$table->integer('rechazado_observaciones')`), así que el motivo se perdía SIEMPRE: el modal
 * RechazarCheque.vue lo manda como `notas`, ChequeController::rechazar() leía
 * `rechazado_observaciones` (que nunca llegaba) y, aunque hubiera llegado, un texto como
 * "Sin fondos" en una columna INT con `'strict' => true` es SQLSTATE 1366 → un 500.
 *
 * ALTER crudo y no `->change()`, por el mismo criterio que
 * `2026_09_30_130000_valor_dolar_decimal_en_sales`: `change()` reconstruye la columna con
 * doctrine/dbal a partir de lo declarado, y el DDL explícito es el mismo en MySQL 5.7 y 8. Pasar
 * de INT a TEXT reescribe la tabla (copia); `cheques` es chica (cientos de filas en los clientes que
 * más la usan).
 *
 * 🔴 Compatibilidad durante el deploy. `DeploymentService::execute_steps()` (admin-api) sube el SPA,
 * después la API y RECIÉN AHÍ migra: en esa ventana —y para siempre si una migración falla en un
 * cliente— la API nueva corre contra la columna INT. Por eso la API no escribe el motivo a ciegas:
 * ChequeHelper::asignar_motivo_de_rechazo() mira el tipo real de la columna
 * (columna_de_motivo_acepta_texto()) y, si todavía es entera, rechaza el cheque sin motivo (lo de
 * siempre) y deja un warning en el log.
 *
 * Con guarda `Schema::hasColumn`: una base donde la columna no exista no falla.
 */
class RechazadoObservacionesTextoEnCheques extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('cheques', 'rechazado_observaciones')) {
            DB::statement('ALTER TABLE `cheques` MODIFY `rechazado_observaciones` TEXT NULL DEFAULT NULL');

            /*
             * Un 0 entero nunca fue un motivo: era lo único que la columna podía tener además de
             * NULL (la semilla y los datos a mano). Al pasar a TEXT queda como el texto '0' y el
             * listado mostraría un "0" como motivo del rechazo; pasa a NULL, que es "sin motivo".
             */
            DB::statement("UPDATE `cheques` SET `rechazado_observaciones` = NULL WHERE `rechazado_observaciones` = '0'");
        }
    }

    /**
     * Salida de emergencia, no un camino normal: el pipeline de upgrade no hace rollback.
     *
     * 🔴 PIERDE LOS MOTIVOS: volver a INT no puede guardar un texto, así que todo lo que no sea un
     * entero se pone en NULL antes de cambiar el tipo (si no, el ALTER corta con SQLSTATE 1366 en
     * modo estricto). Lo único que sobrevive es un motivo que casualmente sea un número de hasta
     * nueve cifras: uno más largo ("12345678901") tampoco entra en un INT y también va a NULL.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('cheques', 'rechazado_observaciones')) {
            DB::statement("UPDATE `cheques` SET `rechazado_observaciones` = NULL WHERE `rechazado_observaciones` NOT REGEXP '^-?[0-9]{1,9}$'");

            DB::statement('ALTER TABLE `cheques` MODIFY `rechazado_observaciones` INT NULL DEFAULT NULL');
        }
    }
}
