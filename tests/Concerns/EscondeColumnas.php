<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corre el cuerpo de un test con una columna de la base ESCONDIDA (renombrada), para probar una
 * guarda de esquema (misión formas-envio-direcciones-maps, 19/10/2026).
 *
 * La ventana que esto reproduce no es hipotética: un deploy de empresa sube los archivos ANTES de
 * migrar (`DeploymentService::execute_steps()` de `admin-api`: `upload_api` -> `sync_env_keys` ->
 * `run_migrations`). En el medio, el código nuevo corre contra una base sin las columnas nuevas.
 *
 * Es la técnica de `tests/Feature/ForzarTotal/9_Guarda_de_esquema_de_la_columna_Test.php`,
 * sacada a un trait para usarla con cualquier tabla. Resuelve las mismas dos cosas cruzadas:
 *
 *  1. El DDL de MySQL hace COMMIT IMPLÍCITO de la sesión que lo corre. Un `ALTER TABLE` por la
 *     conexión del test cerraría la transacción de `DatabaseTransactions` y lo que escriba el
 *     test quedaría pegado en la base del slot. Por eso el ALTER va por una conexión PDO APARTE.
 *  2. En MySQL 8 `information_schema` sale del diccionario de datos (InnoDB): adentro de una
 *     transacción REPEATABLE READ ya abierta, `Schema::hasColumn()` responde con el SNAPSHOT y
 *     sigue viendo la columna. Por eso se cierra la transacción del trait ANTES del rename y se
 *     abre una NUEVA después: lo que escribe el cuerpo va en la nueva, que se revierte igual.
 *
 * Se RENOMBRA y no se borra: devolverla es un statement, y si algo se corta a la mitad la columna
 * sigue entera bajo el otro nombre (`restaurar_columna_si_quedo_escondida()` la devuelve en el
 * `setUp` de la corrida siguiente).
 *
 * Requiere un test con `DatabaseTransactions` (por ejemplo `Tests\EmpresaTestCase`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
trait EscondeColumnas
{
    /**
     * Nombre al que se corre la columna para que la guarda no la encuentre.
     *
     * @param  string  $columna
     * @return string
     */
    protected function nombre_escondido($columna)
    {
        return $columna . '_escondida_por_el_test';
    }

    /**
     * Conexión PDO aparte contra la MISMA base, para correr el DDL sin cerrar la transacción del
     * test. Sale de la config de Laravel, nunca de valores escritos a mano.
     *
     * @return \PDO
     */
    protected function conexion_aparte_para_ddl()
    {
        $config = config('database.connections.' . config('database.default'));

        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // Sin esto, un metadata lock cuelga la suite: el default de MySQL para MDL es un año.
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        return $pdo;
    }

    /**
     * Renombra una columna por la conexión aparte.
     *
     * @param  string  $tabla
     * @param  string  $desde
     * @param  string  $hacia
     * @return void
     */
    protected function renombrar_columna($tabla, $desde, $hacia)
    {
        $this->conexion_aparte_para_ddl()->exec(
            'ALTER TABLE `' . $tabla . '` RENAME COLUMN `' . $desde . '` TO `' . $hacia . '`'
        );
    }

    /**
     * Red de seguridad para el `setUp`: si una corrida anterior se cortó con la columna
     * escondida, la devuelve antes de que ningún test la necesite.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return void
     */
    protected function restaurar_columna_si_quedo_escondida($tabla, $columna)
    {
        if (Schema::hasColumn($tabla, $this->nombre_escondido($columna))) {
            $this->renombrar_columna($tabla, $this->nombre_escondido($columna), $columna);
        }
    }

    /**
     * Corre `$cuerpo` con la base SIN la columna y la devuelve pase lo que pase.
     *
     * Antes de largar el cuerpo verifica el escenario: sin esa aserción de control, un test que
     * no lograra esconder la columna pasaría igual, con la columna puesta, sin probar nada.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_la_columna($tabla, $columna, \Closure $cuerpo)
    {
        DB::rollBack();

        $this->renombrar_columna($tabla, $columna, $this->nombre_escondido($columna));

        DB::beginTransaction();

        try {

            $this->assertFalse(
                Schema::hasColumn($tabla, $columna),
                'El escenario no se armó: la base sigue viendo ' . $tabla . '.' . $columna . '.'
            );

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->renombrar_columna($tabla, $this->nombre_escondido($columna), $columna);

            // Para que el rollback del tearDown de DatabaseTransactions tenga una transacción.
            DB::beginTransaction();
        }
    }
}
