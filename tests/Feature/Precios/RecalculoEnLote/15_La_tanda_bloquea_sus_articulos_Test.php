<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * El motor lee su tanda con candado, adentro de su transacción (misión
 * recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * El problema: el motor lee los artículos, calcula en memoria y escribe con un UPDATE ... CASE que
 * no vuelve a leer. Si otra transacción confirmaba cambios de esos artículos en el medio (la
 * propagación de descuentos, un usuario editando el costo), el motor los pisaba con precios
 * calculados con lo viejo. Ahora la lectura es la primera sentencia de la transacción de la tanda,
 * con FOR UPDATE: la otra transacción espera y escribe después.
 *
 * Dos pruebas:
 *  - la forma: la lectura de la tanda es lo primero después de que el motor abre su transacción,
 *    sale con FOR UPDATE y ordenada por id (el orden en que se toman los candados);
 *  - el comportamiento, con DOS conexiones reales: una segunda conexión PDO (fuera de Laravel, con
 *    datos que ella misma confirmó, porque los del test viven sin confirmar en la transacción de
 *    DatabaseTransactions y ninguna otra conexión los vería) intenta escribir el artículo justo
 *    después de que el motor lo leyó. Tiene que quedar esperando el candado (con un tope de
 *    espera de 1 segundo en su sesión, para no colgar el test) en vez de escribir.
 *
 * 🔴 El límite de la segunda prueba: PHP corre en un solo hilo, así que la otra conexión no puede
 * esperar de verdad a que el motor termine; lo que se prueba es que en ese momento NO PUEDE
 * escribir (el candado está tomado entre la lectura y la escritura del motor). Que en producción
 * su escritura entra después, encima de la del motor, es lo que hace InnoDB con cualquier espera
 * de candado; no se reproduce acá.
 *
 * Los datos de la segunda conexión se borran en tearDown(), DESPUÉS de que DatabaseTransactions
 * revierte la transacción del test (que tiene el candado tomado hasta ese momento).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class La_tanda_bloquea_sus_articulos_Test extends RecalculoEnLoteTestCase
{
    /** @var \PDO|null La segunda conexión, independiente de Laravel. */
    protected $segunda = null;

    /** @var array Filas que confirmó la segunda conexión, para borrarlas al final: [tabla => [ids]]. */
    protected $confirmadas = [];

    protected function tearDown(): void
    {
        /* Primero se revierte la transacción del test (que tiene el candado), después se limpia. */
        parent::tearDown();

        if (!is_null($this->segunda)) {

            foreach (['articles', 'users'] as $tabla) {
                if (!empty($this->confirmadas[$tabla])) {
                    $marcas = implode(', ', array_fill(0, count($this->confirmadas[$tabla]), '?'));
                    $this->segunda->prepare('DELETE FROM `' . $tabla . '` WHERE `id` IN (' . $marcas . ')')->execute($this->confirmadas[$tabla]);
                }
            }

            $this->segunda = null;
        }
    }

    /**
     * La lectura de la tanda: primera sentencia de la transacción del motor, con FOR UPDATE y en
     * orden de id.
     *
     * @return void
     */
    public function test_la_lectura_de_la_tanda_es_lo_primero_de_su_transaccion_y_con_candado()
    {
        $dueno = $this->crear_dueno();

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 100 + $i])->id;
        }

        $secuencia = [];

        Event::listen(TransactionBeginning::class, function () use (&$secuencia) {
            $secuencia[] = 'BEGIN';
        });

        DB::listen(function ($query) use (&$secuencia) {
            $secuencia[] = $query->sql;
        });

        RecalculoDePreciosEnLote::recalcular($ids, User::find($dueno->id));

        $indice = null;

        foreach ($secuencia as $i => $sql) {
            if ($sql !== 'BEGIN' && preg_match('/^select .* from `articles` where `articles`\.`id` in \(/i', $sql)) {
                $indice = $i;
                break;
            }
        }

        $this->assertNotNull($indice, 'No se encontró la lectura de la tanda: ' . implode("\n", $secuencia));

        $lectura = $secuencia[$indice];

        $this->assertMatchesRegularExpression('/ for update$/i', $lectura, 'La lectura de la tanda no toma candado.');
        $this->assertMatchesRegularExpression('/order by `articles`\.`id` asc/i', $lectura, 'La lectura de la tanda no está ordenada por id (el orden en que se toman los candados).');

        $this->assertSame('BEGIN', $indice > 0 ? $secuencia[$indice - 1] : null, 'La lectura de la tanda no es la primera sentencia de la transacción del motor.');

        /* Y las relaciones se cargan después, ya con el candado tomado. */
        $descuentos = null;
        foreach ($secuencia as $i => $sql) {
            if ($sql !== 'BEGIN' && strpos($sql, 'from `article_discounts`') !== false) {
                $descuentos = $i;
                break;
            }
        }

        $this->assertNotNull($descuentos);
        $this->assertGreaterThan($indice, $descuentos, 'Las relaciones se leyeron antes de tomar el candado.');
    }

    /**
     * Con dos conexiones reales: mientras el motor tiene la tanda leída y todavía no escribió, otra
     * conexión no puede escribir esos artículos.
     *
     * @return void
     */
    public function test_otra_conexion_no_puede_escribir_un_articulo_entre_la_lectura_y_la_escritura_del_motor()
    {
        $this->abrir_segunda_conexion();

        $ahora = date('Y-m-d H:i:s');

        $owner_id = $this->confirmar('users', [
            'name'       => 'zz Dueno dos conexiones',
            'email'      => 'dos-conexiones-' . uniqid('', true) . '@test.local',
            'password'   => 'x',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $article_id = $this->confirmar('articles', [
            'name'       => 'zz Articulo dos conexiones',
            'user_id'    => $owner_id,
            'status'     => 'active',
            'cost'       => 100,
            'iva_id'     => self::IVA_21,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $disparado = false;
        $resultado = null;

        $segunda = $this->segunda;

        DB::listen(function ($query) use (&$disparado, &$resultado, $segunda, $article_id) {

            if ($disparado || !preg_match('/^select .* from `articles` where `articles`\.`id` in \(/i', $query->sql)) {
                return;
            }

            $disparado = true;

            try {
                $segunda->prepare('UPDATE `articles` SET `cost` = 500 WHERE `id` = ?')->execute([$article_id]);
                $resultado = 'escribio';
            } catch (\PDOException $e) {
                $resultado = (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1205) ? 'bloqueada' : 'error: ' . $e->getMessage();
            }
        });

        RecalculoDePreciosEnLote::recalcular([$article_id], User::find($owner_id));

        $this->assertTrue($disparado, 'La otra conexión no llegó a intentar escribir: el test no probó nada.');

        $this->assertSame(
            'bloqueada',
            $resultado,
            'Otra conexión pudo escribir el artículo entre la lectura y la escritura del motor: el motor pisaría ese cambio con un precio calculado con lo viejo.'
        );
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Abre la segunda conexión con la misma base que el test, en autocommit, con un tope de espera
     * de candado de 1 segundo en su sesión.
     *
     * @return void
     */
    protected function abrir_segunda_conexion()
    {
        $config = config('database.connections.' . config('database.default'));

        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . DB::connection()->getDatabaseName() . ';charset=utf8mb4';

        $this->segunda = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $this->segunda->exec('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /**
     * Inserta y CONFIRMA una fila con la segunda conexión (autocommit) y la anota para borrarla.
     *
     * @param  string $tabla
     * @param  array  $fila
     * @return int
     */
    protected function confirmar($tabla, array $fila)
    {
        $columnas = array_keys($fila);

        $sql = 'INSERT INTO `' . $tabla . '` (`' . implode('`, `', $columnas) . '`) VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')';

        $this->segunda->prepare($sql)->execute(array_values($fila));

        $id = (int) $this->segunda->lastInsertId();

        $this->confirmadas[$tabla][] = $id;

        return $id;
    }
}
