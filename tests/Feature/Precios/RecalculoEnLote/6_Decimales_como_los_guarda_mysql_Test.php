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
     * es_decimal_sin_cambio() decide con la emulación de MySQL y no con round() (chequeo de
     * mutantes del 29/9/2026, R03: con round() los tests de arriba seguían verdes, porque prueban
     * la emulación suelta y no la decisión del motor).
     *
     * El contraejemplo es real: 0.124999999999999 tiene 15 dígitos significativos; PDO lo manda
     * como "0.125" (14 dígitos en PHP 7.4) y MySQL guarda 0.13 en un DECIMAL(22,2), pero
     * round(v, 2) de PHP da 0.12. Con round(), el motor creería que un 0.12 de la base "no
     * cambió" y dejaría un centavo distinto del que dejaba save().
     *
     * @return void
     */
    public function test_la_decision_de_no_escribir_sigue_a_mysql_y_no_a_round()
    {
        $borde = 0.124999999999999;

        /* Precondiciones contra la base real: MySQL guarda 0.13, round() de PHP da 0.12, y final_price tiene escala 2. */
        DB::insert('INSERT INTO `zz_decimales_recalculo` (`d2`) VALUES (?)', [$borde]);

        $this->assertSame('0.13', (string) DB::table('zz_decimales_recalculo')->orderBy('id', 'DESC')->value('d2'), 'Precondición: MySQL tenía que guardar 0.13.');
        $this->assertSame(0.12, round($borde, 2), 'Precondición: round() de PHP tiene que dar otro número, o el caso no prueba nada.');

        $escala = DB::selectOne("SELECT NUMERIC_SCALE AS escala FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'final_price'");

        $this->assertSame(2, (int) $escala->escala, 'Precondición: articles.final_price tenía que ser DECIMAL(x,2).');

        $es_decimal_sin_cambio = new \ReflectionMethod(RecalculoDePreciosEnLote::class, 'es_decimal_sin_cambio');
        $es_decimal_sin_cambio->setAccessible(true);

        $this->assertFalse($es_decimal_sin_cambio->invoke(null, 'final_price', $borde, '0.12'), 'La base tiene 0.12 y save() dejaría 0.13: es un cambio y se tiene que escribir.');
        $this->assertTrue($es_decimal_sin_cambio->invoke(null, 'final_price', $borde, '0.13'), 'La base ya tiene 0.13, lo mismo que dejaría save(): no hace falta reescribirla.');
    }

    /**
     * Una segunda pasada que no cambia ningún precio no reescribe columnas decimales (chequeo de
     * mutantes del 29/9/2026, R02: sin el filtro de es_decimal_sin_cambio() los tests seguían
     * verdes, porque la base queda igual; lo que se pierde es rendimiento). Eloquent da por
     * sucio todo decimal que salió de una cuenta (compara el texto de la base con un float), y sin
     * el filtro el UPDATE en bloque las reescribiría todas con lo mismo en cada recálculo. Solo
     * tiene que quedar updated_at, que el camino por artículo tocaba cuando el costo real sale de
     * una cuenta (acá, el IVA al costo).
     *
     * @return void
     */
    public function test_una_segunda_pasada_sin_cambios_no_reescribe_decimales()
    {
        $dueno     = $this->crear_dueno(['aplicar_iva_al_costo' => 1, 'redondear_precios_en_centavos' => 1]);
        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 100 + $i * 1.37, 'provider_id' => $proveedor->id])->id;
        }

        $this->recalcular_con_el_motor($ids, $dueno->id);

        $updates = [];

        DB::listen(function ($query) use (&$updates) {
            if (preg_match('/^\s*update\s+`articles`/i', $query->sql)) {
                $updates[] = $query->sql;
            }
        });

        $r = $this->recalcular_con_el_motor($ids, $dueno->id);

        $this->assertSame([], $r['cambiaron'], 'Precondición: la segunda pasada no tenía que cambiar ningún precio.');

        /* La pasada sí escribió (updated_at): o sea que las columnas sucias pasaron por el filtro. */
        $this->assertNotEmpty($updates, 'Precondición: la segunda pasada tenía que escribir updated_at; sin ningún UPDATE el test no prueba el filtro.');

        foreach ($updates as $sql) {
            $this->assertStringNotContainsString('`final_price`', $sql, 'La segunda pasada reescribió final_price: ' . $sql);
            $this->assertStringNotContainsString('`costo_real`', $sql, 'La segunda pasada reescribió costo_real: ' . $sql);
            $this->assertStringContainsString('`updated_at`', $sql);
        }
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
