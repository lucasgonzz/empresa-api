<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use Illuminate\Support\Facades\DB;

/**
 * RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql() contra el MySQL real (misión
 * recalculo-precios-motor-rapido, 28/9/2026).
 *
 * Por qué importa: el motor NO escribe una columna decimal cuando el valor nuevo quedaría guardado
 * igual que el que ya tiene la base (Eloquent la da por sucia siempre: compara "123.450000" contra
 * 123.45 como texto). Si esa decisión dijera "igual" donde la base guardaría otra cosa, un precio
 * no se escribiría. Por eso no se decide con round() de PHP sino emulando lo que hace MySQL con el
 * texto que le manda PDO (14 dígitos significativos en PHP 7.4, redondeo mitad hacia afuera del
 * cero). Acá se inserta cada valor en una tabla temporal EXACTAMENTE como lo manda save() (un
 * binding) y se compara lo que quedó guardado con lo que dice la emulación.
 *
 * El caso que justifica la emulación: 1234567.12345649 con escala 6. round() de PHP pre-redondea a
 * 15 dígitos (…1234564|9 → …123456) y MySQL recibe "1234567.1234565" y guarda …123457. Un
 * costo_real de un millón con cola larga no es exótico (costo en dólares × cotización).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Decimales_como_los_guarda_mysql_Test extends RecalculoEnLoteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /* Temporal: no hace commit implícito y desaparece sola con la conexión. */
        DB::statement('CREATE TEMPORARY TABLE `zz_decimales_recalculo` (`id` INT AUTO_INCREMENT PRIMARY KEY, `d2` DECIMAL(22,2) NULL, `d6` DECIMAL(22,6) NULL)');
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS `zz_decimales_recalculo`');

        parent::tearDown();
    }

    /**
     * Los bordes: mitades exactas, negativos, el cero negativo, el acarreo que cambia de entero,
     * textos como los devuelve la base, y los dos casos donde round() de PHP y MySQL no coinciden.
     *
     * @return void
     */
    public function test_la_emulacion_coincide_con_mysql_en_los_bordes()
    {
        $valores = [
            2.675, -2.675, 1.005, 0.125, 99.995, -0.004, -0.005, 0.0, 1500.0, 7, -7,
            '123.450000', '0.000000', '99999.99', '-12.5',
            1234567.12345649,
            12345678901.23494,
            155.44041450777202,
            1.0 / 3.0,
            2.0 / 3.0 * 1000,
        ];

        $comparados = $this->comparar_con_mysql($valores);

        $this->assertGreaterThanOrEqual(count($valores) * 2 - 2, $comparados, 'Se tenían que comparar casi todos los valores en las dos escalas.');

        /* El caso que round() de PHP resuelve distinto que MySQL: la emulación tiene que seguir a MySQL. */
        $this->assertSame('1234567.123457', RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql(1234567.12345649, 6));
        $this->assertNotSame(number_format(round(1234567.12345649, 6), 6, '.', ''), '1234567.123457', 'Precondición: con round() de PHP este valor da otro número (si da igual, el caso dejó de probar la emulación).');
    }

    /**
     * Lo que no se puede emular con certeza (notación científica) devuelve null: el motor escribe
     * la columna, que es lo que hacía save().
     *
     * @return void
     */
    public function test_la_notacion_cientifica_no_se_emula()
    {
        $this->assertNull(RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql(1.0E-7, 6));
        $this->assertNull(RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql(123456789012345.678, 2));
        $this->assertNull(RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql('abc', 2));
        $this->assertNull(RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql(null, 2));
    }

    /**
     * Miles de valores al azar (semilla fija), de magnitudes y colas distintas, como los que sale
     * de la cadena de precios: multiplicaciones y divisiones por márgenes, IVA y cotizaciones.
     *
     * @return void
     */
    public function test_la_emulacion_coincide_con_mysql_en_valores_al_azar()
    {
        mt_srand(28092026);

        $valores = [];

        for ($i = 0; $i < 3000; $i++) {

            $magnitud = pow(10, mt_rand(0, 9));
            $base     = mt_rand(1, 999999) / 1000 * $magnitud / 1000;

            /* Márgenes, IVA, impuestos por división y cotizaciones: colas largas de verdad. */
            $valor = $base * (1 + mt_rand(0, 150) / 100) * 1.21 / (1 - mt_rand(0, 50) / 1000) * (mt_rand(1, 3000) / 1000);

            $valores[] = mt_rand(0, 9) === 0 ? -$valor : $valor;
        }

        $comparados = $this->comparar_con_mysql($valores);

        $this->assertGreaterThan(5000, $comparados, 'Se tenían que comparar casi todos los valores (los de notación científica se saltean).');
    }

    /**
     * Inserta cada valor como binding en las dos columnas, lo lee de vuelta y lo compara con la
     * emulación. Devuelve cuántas comparaciones hizo (las que la emulación contesta).
     *
     * @param  array $valores
     * @return int
     */
    protected function comparar_con_mysql(array $valores)
    {
        DB::table('zz_decimales_recalculo')->delete();

        foreach (array_chunk($valores, 500) as $tanda) {
            foreach ($tanda as $valor) {
                DB::insert('INSERT INTO `zz_decimales_recalculo` (`d2`, `d6`) VALUES (?, ?)', [$valor, $valor]);
            }
        }

        $filas = DB::table('zz_decimales_recalculo')->orderBy('id')->get();

        $this->assertCount(count($valores), $filas);

        $comparados = 0;

        foreach ($filas as $i => $fila) {

            foreach (['d2' => 2, 'd6' => 6] as $columna => $escala) {

                $emulado = RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql($valores[$i], $escala);

                if (is_null($emulado)) {
                    continue;
                }

                $this->assertSame(
                    (string) $fila->{$columna},
                    $emulado,
                    'La emulación no coincide con lo que guardó MySQL para ' . var_export($valores[$i], true) . ' con escala ' . $escala . '.'
                );

                $comparados++;
            }
        }

        return $comparados;
    }
}
