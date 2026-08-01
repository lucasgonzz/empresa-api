<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\ProcessRow;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Test de guardia (grupo 282, prompt 02): ProcessRow::$numeric_precision declara,
 * por columna de `articles`, [digitos_enteros_maximos, decimales] usados para
 * redondear y validar el rango de los valores que manda el importador. Si una
 * migracion futura cambia la precision real de una de esas columnas sin
 * actualizar el mapa, el importador vuelve a truncar en silencio (o a dejar
 * pasar valores que MySQL despues rechaza), exactamente lo que paso desde 2019
 * hasta este grupo sin que nada lo detectara. Este test lo pone rojo de
 * inmediato en vez de dejarlo degradar en silencio.
 *
 * No usa DatabaseTransactions ni ImportTestSeeder: es de solo lectura contra
 * information_schema, no toca ninguna fila de `articles`.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class NumericPrecisionSchemaTest extends TestCase
{
    /** Tipos de columna entera de MySQL: sus decimales tienen que ser 0. */
    const TIPOS_ENTEROS = ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'];

    /**
     * Lee ProcessRow::$numeric_precision via Reflection. Es protected static y
     * no es API publica del helper: no hay razon para abrirla solo por este test.
     *
     * @return array
     */
    protected function mapa_numeric_precision()
    {
        $reflection = new ReflectionClass(ProcessRow::class);
        $propiedad = $reflection->getProperty('numeric_precision');
        $propiedad->setAccessible(true);

        return $propiedad->getValue();
    }

    /**
     * Para cada entrada del mapa, [max_enteros, decimales] tiene que coincidir
     * con [NUMERIC_PRECISION - NUMERIC_SCALE, NUMERIC_SCALE] de la columna real
     * en `articles` (o con decimales = 0 si la columna es un entero).
     *
     * @return void
     */
    public function test_numeric_precision_coincide_con_el_esquema_real()
    {
        $mapa = $this->mapa_numeric_precision();

        $this->assertNotEmpty($mapa, 'ProcessRow::$numeric_precision no puede estar vacio.');

        $columnas = array_keys($mapa);

        $placeholders = implode(',', array_fill(0, count($columnas), '?'));

        $filas = DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE '
            . 'FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND COLUMN_NAME IN (' . $placeholders . ')',
            array_merge(['articles'], $columnas)
        );

        $esquema_real = [];
        foreach ($filas as $fila) {
            $esquema_real[$fila->COLUMN_NAME] = $fila;
        }

        foreach ($mapa as $campo => $config) {

            $this->assertArrayHasKey(
                $campo,
                $esquema_real,
                "La columna '{$campo}' declarada en ProcessRow::\$numeric_precision no existe en articles."
            );

            $columna = $esquema_real[$campo];

            if (in_array($columna->DATA_TYPE, self::TIPOS_ENTEROS, true)) {

                $this->assertSame(
                    0,
                    $config[1],
                    "'{$campo}' es {$columna->DATA_TYPE}: los decimales del mapa tienen que ser 0, no {$config[1]}."
                );

                continue;
            }

            $esperado = [
                (int) $columna->NUMERIC_PRECISION - (int) $columna->NUMERIC_SCALE,
                (int) $columna->NUMERIC_SCALE,
            ];

            $this->assertSame(
                $esperado,
                [(int) $config[0], (int) $config[1]],
                "'{$campo}': el mapa dice [{$config[0]}, {$config[1]}] pero la columna real es "
                . "decimal({$columna->NUMERIC_PRECISION},{$columna->NUMERIC_SCALE}) -> deberia decir "
                . "[{$esperado[0]}, {$esperado[1]}]."
            );
        }
    }
}
