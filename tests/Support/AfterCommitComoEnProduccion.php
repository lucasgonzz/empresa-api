<?php

namespace Tests\Support;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;

/**
 * Misión gate-4-3-68-rojos (19/11/2026) — `DB::afterCommit()` bajo `DatabaseTransactions`, como en
 * producción.
 *
 * Desde fbc900da, `BackgroundProcessHelper::emitir()` difiere el broadcast al commit si hay una
 * transacción abierta (emitir antes anunciaría un proceso o un avance que todavía puede revertirse).
 * En producción un job o un request sin transacción propia emite en el acto. En la suite, en cambio,
 * TODO corre adentro de la transacción de `DatabaseTransactions`, que nunca se confirma: en Laravel 8.83
 * el callback queda colgado de esa transacción y no corre nunca, así que el test ya no mide lo que pasa
 * en producción.
 *
 * Laravel 9+ lo resuelve ignorando la transacción base del test (`callbacksShouldIgnore`). Este trait
 * hace lo mismo para la clase que lo usa, sin tocar el resto de la suite:
 *
 *  - Un callback registrado cuando la única transacción abierta es la del test corre en el acto
 *    (igual que en producción sin transacción).
 *  - Un callback registrado dentro de una transacción PROPIA del código (anidada sobre la del test)
 *    corre recién cuando esa transacción se confirma y se vuelve al nivel del test.
 *  - Si esa transacción propia hace rollback, el callback se descarta (no se emite nada).
 *
 * El contrato real (rollback = sin evento, commit real = un evento) lo prueba además
 * `Tests\Feature\Listado\EfectosDeTandasTest` con commit/rollback de verdad, sin este trait.
 *
 * Uso: llamar a `ejecutar_after_commit_como_en_produccion()` en el `setUp()` del test, DESPUÉS de
 * `parent::setUp()` (cuando la transacción del test ya está abierta).
 */
trait AfterCommitComoEnProduccion
{
    /**
     * Reemplaza el administrador de transacciones de la conexión por defecto por uno que no conoce la
     * transacción base del test, y engancha la ejecución de los callbacks al commit que vuelve a ese nivel.
     *
     * @return void
     */
    protected function ejecutar_after_commit_como_en_produccion(): void
    {
        /** Conexión por defecto: la que usan `DB::afterCommit()` y `DB::transactionLevel()`. */
        $conexion = DB::connection();

        /** Nombre de la conexión, para filtrar los commits de otras conexiones. */
        $nombre = $conexion->getName();

        /** Nivel de la transacción del test (1 con DatabaseTransactions). Es el "cero" de producción. */
        $nivel_base = $conexion->transactionLevel();

        /*
         * Administrador nuevo y vacío: no registra la transacción del test, así que un callback sin
         * transacción propia abierta corre en el acto (DatabaseTransactionsManager::addCallback).
         * Las transacciones propias del código (anidadas) sí quedan registradas por begin().
         */
        $administrador = new DatabaseTransactionsManager();
        $conexion->setTransactionManager($administrador);
        $this->app->instance('db.transactions', $administrador);

        /*
         * Laravel 8 solo llama a commit() del administrador cuando el nivel vuelve a 0, cosa que en el
         * test nunca pasa. Al confirmarse una transacción propia que vuelve al nivel del test, se
         * ejecutan (y se descartan) los callbacks registrados en ella. El rollback ya los descarta solo:
         * la conexión llama a rollback() del administrador con el nivel nuevo.
         */
        $conexion->getEventDispatcher()->listen(TransactionCommitted::class, function ($evento) use ($nombre, $nivel_base, $administrador) {
            if ($evento->connectionName === $nombre && $evento->connection->transactionLevel() === $nivel_base) {
                $administrador->commit($nombre);
            }
        });
    }
}
