<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Services\Importacion\PlanillaDePrueba;
use Tests\TestCase;

/**
 * Test generativo de la clase «filas de la planilla que no son datos» (misión prueba-e2e-campana-imperio, ronda 6,
 * E8.3, 14/11/2026). Con una semilla fija arma en memoria cientos de planillas de artículos y de listas de contactos
 * combinando al azar las formas de la clase (GeneradorDePlanillasDesordenadas) y verifica las dos invariantes:
 *
 *   1. TODO dato real entra (ninguna fila de artículo o de contacto queda afuera);
 *   2. NINGÚN no-dato entra (rubros, subtotales, cabecera repetida, totales, notas, títulos de sección).
 *
 * Corre la MISMA regla que la carga: PlanillaDePrueba::motivos_de_la_hoja() (resumir_la_hoja() +
 * motivo_para_saltear(), lo que usa filas()) y LocalImportHelper::filas_que_no_son_contactos_de() (lo que usan
 * ClientImport y ProviderImport). La parte que este test no ve —encontrar la cabecera debajo del membrete— la cubre
 * ImportacionDePruebaFilasQueNoSonDatosTest::test_planillas_generadas_por_la_carga_entera.
 *
 * Si falla, el mensaje dice la semilla, el caso y la fila: se reproduce con ese caso solo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaFilasQueNoSonDatosGenerativoTest extends TestCase
{
    const SEMILLA = 20261114;
    const PLANILLAS = 300;

    /** Las filas desde la de inicio (lo que ve filas(): filas_crudas() arranca en `inicio`). */
    private static function desde_el_inicio(array $planilla): array
    {
        return array_filter($planilla['filas'], function ($numero) use ($planilla) {
            return $numero >= $planilla['inicio'];
        }, ARRAY_FILTER_USE_KEY);
    }

    public function test_cientos_de_planillas_de_articulos(): void
    {
        mt_srand(self::SEMILLA);
        $fallas = [];
        $filas_juzgadas = 0;
        $totales_largos = 0;
        $como_un_total = 0;
        $subtotales_con_importe = 0;
        for ($caso = 0; $caso < self::PLANILLAS; $caso++) {
            $planilla = GeneradorDePlanillasDesordenadas::articulos($caso);
            $totales_largos += $planilla['totales_largos'];
            $como_un_total += $planilla['articulos_como_un_total'];
            $subtotales_con_importe += $planilla['subtotales_con_importe'];
            $filas = self::desde_el_inicio($planilla);
            $motivos = PlanillaDePrueba::motivos_de_la_hoja($filas, $planilla['mapeo'], $planilla['titulos']);
            foreach ($planilla['datos'] as $numero) {
                $filas_juzgadas++;
                if (isset($motivos[$numero])) {
                    $fallas[] = 'caso ' . $caso . ' fila ' . $numero . ': un ARTÍCULO quedó afuera como ' . $motivos[$numero] . ' '
                        . json_encode($filas[$numero], JSON_UNESCAPED_UNICODE) . ($planilla['con_codigos'] ? ' (con códigos)' : ' (sin códigos)');
                }
            }
            foreach ($planilla['no_datos'] as $numero) {
                $filas_juzgadas++;
                if (!isset($motivos[$numero])) {
                    $fallas[] = 'caso ' . $caso . ' fila ' . $numero . ': un NO-DATO entró como artículo o desapareció sin contarse '
                        . json_encode($filas[$numero], JSON_UNESCAPED_UNICODE) . ($planilla['con_codigos'] ? ' (con códigos)' : ' (sin códigos)');
                }
            }
            /*
             * E8.6: ninguna fila desaparece en silencio. Filas leídas = filas que entran + filas salteadas contadas,
             * salvo las vacías (la cabecera repetida también se cuenta).
             */
            $no_vacias = array_filter($filas, function ($fila) {
                return array_filter($fila, function ($celda) { return trim((string) $celda) !== ''; }) !== [];
            });
            // Las que entran, contadas por su cuenta: filas no vacías cuyo motivo es null.
            $entran = [];
            foreach ($no_vacias as $numero => $fila) {
                if (!array_key_exists($numero, $motivos)) {
                    $entran[] = $numero;
                }
            }
            // Y las salteadas, solo las que son filas leídas (un motivo de una fila vacía o inexistente sería un fantasma).
            $salteadas_leidas = count(array_intersect_key($motivos, $no_vacias));
            if ($entran !== $planilla['datos'] || count($no_vacias) !== count($entran) + $salteadas_leidas || $salteadas_leidas !== count($motivos)) {
                $fallas[] = 'caso ' . $caso . ': leídas ' . count($no_vacias) . ', entran ' . count($entran) . ', salteadas ' . count($motivos)
                    . ', datos esperados ' . count($planilla['datos']);
            }
        }
        fwrite(STDERR, PHP_EOL . 'Artículos: ' . self::PLANILLAS . ' planillas, ' . $filas_juzgadas . ' filas juzgadas, ' . count($fallas) . ' fallas, '
            . $totales_largos . ' totales del pie con rótulo largo y la suma real, ' . $como_un_total . ' artículos que se llaman como un total, '
            . $subtotales_con_importe . ' subtotales con el número en la columna del precio.' . PHP_EOL);
        // Quinta pasada: los dos lados de los totales.
        $this->assertGreaterThan(20, $como_un_total, 'La muestra casi no trae artículos que se llaman como un total.');
        $this->assertGreaterThan(20, $subtotales_con_importe, 'La muestra casi no trae subtotales con importe.');
        // Cuarta pasada, B1: la clase está en la muestra.
        $this->assertGreaterThan(20, $totales_largos, 'La muestra casi no trae totales con rótulo largo.');
        $this->assertSame([], array_slice($fallas, 0, 25), 'Semilla ' . self::SEMILLA . ': ' . count($fallas) . ' fallas.');
    }

    public function test_cientos_de_listas_de_contactos(): void
    {
        mt_srand(self::SEMILLA + 1);
        $fallas = [];
        $filas_juzgadas = 0;
        $listas_con_sueltas = 0;
        $listas_con_reales = 0;
        $listas_con_formato = 0;
        $notas_con_forma_de_oracion = 0;
        $totales_sin_nombre = 0;
        $listas_con_tres_grupos_o_mas = 0;
        $con_sena = 0;
        $negritas_dispersas = 0;
        $reales_al_final = 0;
        $subtotales_de_contactos = 0;
        $titulos_repetidos = 0;
        $saldos_con_cierre = 0;
        $saldos_con_menos_atras = 0;
        $clientes_con_palabra_de_total = 0;
        for ($caso = 0; $caso < self::PLANILLAS; $caso++) {
            $lista = GeneradorDePlanillasDesordenadas::contactos($caso);
            $listas_con_sueltas += $lista['sueltas'] > 0 ? 1 : 0;
            $listas_con_reales += $lista['reales_de_solo_nombre'] > 0 ? 1 : 0;
            $listas_con_formato += $lista['sueltas_con_formato'] > 0 ? 1 : 0;
            $notas_con_forma_de_oracion += $lista['notas_con_forma_de_oracion'];
            $totales_sin_nombre += $lista['totales_sin_nombre'];
            $listas_con_tres_grupos_o_mas += $lista['grupos'] >= 3 && $lista['sueltas'] > 0 ? 1 : 0;
            $con_sena += $lista['con_sena'];
            $negritas_dispersas += $lista['negritas_dispersas'];
            $reales_al_final += $lista['reales_al_final'];
            $subtotales_de_contactos += $lista['subtotales_de_contactos'] ?? 0;
            $titulos_repetidos += $lista['titulos_repetidos'] ?? 0;
            $saldos_con_cierre += $lista['saldos_con_cierre'] ?? 0;
            $saldos_con_menos_atras += $lista['saldos_con_menos_atras'] ?? 0;
            $clientes_con_palabra_de_total += $lista['clientes_con_palabra_de_total'] ?? 0;
            $filas = array_values($lista['filas']); // como llega a collection(): 0-based, la fila 1 en el índice 0
            $descartes = LocalImportHelper::filas_que_no_son_contactos_de($filas, $lista['columnas'], $lista['inicio'], count($filas),
                $lista['formato'] ?? []);
            foreach ($lista['datos'] as $numero) {
                $filas_juzgadas++;
                if (isset($descartes[$numero])) {
                    $fallas[] = 'caso ' . $caso . ' fila ' . $numero . ': un CONTACTO quedó afuera como ' . $descartes[$numero][0] . ' '
                        . json_encode($lista['filas'][$numero], JSON_UNESCAPED_UNICODE);
                }
            }
            foreach ($lista['no_datos'] as $numero) {
                $filas_juzgadas++;
                if (!isset($descartes[$numero])) {
                    $fallas[] = 'caso ' . $caso . ' fila ' . $numero . ': un NO-DATO entró como contacto ' . json_encode($lista['filas'][$numero], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        fwrite(STDERR, PHP_EOL . 'Contactos: ' . self::PLANILLAS . ' listas, ' . $filas_juzgadas . ' filas juzgadas, ' . count($fallas) . ' fallas, '
            . $listas_con_sueltas . ' listas con filas sueltas, ' . $listas_con_reales . ' con contactos reales de solo nombre, '
            . $listas_con_formato . ' con sueltas que solo se reconocen por el formato, ' . $listas_con_tres_grupos_o_mas
            . ' con tres grupos o más, ' . $notas_con_forma_de_oracion . ' anotaciones en el medio, '
            . $totales_sin_nombre . ' totales sin nombre, ' . $con_sena . ' clientes con seña, ' . $negritas_dispersas
            . ' clientes de solo nombre en negrita dispersos, ' . $reales_al_final . ' clientes de solo nombre debajo de un total de arriba, '
            . $subtotales_de_contactos . ' subtotales de zona, ' . $titulos_repetidos . ' títulos repetidos en su total, ' . $saldos_con_cierre
            . ' saldos con «.-», ' . $saldos_con_menos_atras . ' con el menos atrás, ' . $clientes_con_palabra_de_total
            . ' clientes reales que empiezan con palabra de total.' . PHP_EOL);
        // La comparación de fallas va primero: si la regla falla, que se vea cuál.
        $this->assertSame([], array_slice($fallas, 0, 25), 'Semilla ' . (self::SEMILLA + 1) . ': ' . count($fallas) . ' fallas.');
        // Ronda 8, A1: la clase de las filas sueltas tiene que estar en la muestra, no solo en los tests de forma.
        $this->assertGreaterThan(40, $listas_con_sueltas, 'La muestra casi no trae listas con filas sueltas.');
        // Segunda pasada de A1: los dos lados, también los contactos de solo nombre que tienen que entrar.
        $this->assertGreaterThan(40, $listas_con_reales, 'La muestra casi no trae contactos reales de solo nombre.');
        // Tercera pasada: las sueltas fuera del vocabulario, que solo se reconocen por el formato.
        $this->assertGreaterThan(20, $listas_con_formato, 'La muestra casi no trae sueltas con formato.');
        // Cuarta pasada: tres grupos o más (sin tope), notas con forma de oración y totales sin nombre.
        $this->assertGreaterThan(20, $listas_con_tres_grupos_o_mas, 'La muestra casi no trae listas con tres grupos o más.');
        $this->assertGreaterThan(20, $notas_con_forma_de_oracion, 'La muestra casi no trae notas con forma de oración.');
        $this->assertGreaterThan(20, $totales_sin_nombre, 'La muestra casi no trae totales sin nombre.');
        // Quinta pasada: del lado «tiene que entrar».
        $this->assertGreaterThan(20, $con_sena, 'La muestra casi no trae clientes con seña.');
        $this->assertGreaterThan(40, $negritas_dispersas, 'La muestra casi no trae clientes en negrita dispersos.');
        $this->assertGreaterThan(20, $reales_al_final, 'La muestra casi no trae clientes debajo de un total de arriba.');
        // Sexta pasada: subtotales de zona con la suma real, títulos que se repiten en su total y saldos con adornos.
        $this->assertGreaterThan(40, $subtotales_de_contactos, 'La muestra casi no trae subtotales de zona.');
        $this->assertGreaterThan(30, $titulos_repetidos, 'La muestra casi no trae títulos repetidos en su total.');
        $this->assertGreaterThan(10, $saldos_con_cierre + $saldos_con_menos_atras, 'La muestra casi no trae saldos con adornos.');
        // Séptima pasada: clientes reales con solo el saldo que empiezan con palabra de total, mezclados con totales de grupo.
        $this->assertGreaterThan(20, $clientes_con_palabra_de_total, 'La muestra casi no trae clientes que empiezan con palabra de total.');
        $this->assertSame([], array_slice($fallas, 0, 25), 'Semilla ' . (self::SEMILLA + 1) . ': ' . count($fallas) . ' fallas.');
    }

    /* ---------- E8.4: los casos de la revisión, sin azar ---------- */

    /** Una hoja: $con artículos con código (y precio, si la hoja lo trae) y después $sin filas sin código. */
    private static function hoja(int $con, array $sin, bool $con_precio = true, array $arriba = []): array
    {
        $filas = [];
        $n = 1;
        foreach ($arriba as $fila) {
            $filas[++$n] = $fila;
        }
        for ($i = 1; $i <= $con; $i++) {
            $filas[++$n] = ['C-' . $i, 'Artículo con código ' . $i, $con_precio ? 100 * $i : null, $i];
        }
        foreach ($sin as $fila) {
            $filas[++$n] = $fila;
        }

        return $filas;
    }

    private static function afuera(array $filas, bool $con_precio = true): array
    {
        $mapeo = ['codigo_de_proveedor' => 0, 'nombre' => 1, 'stock_actual' => 3] + ($con_precio ? ['precio' => 2] : []);

        return PlanillaDePrueba::motivos_de_la_hoja($filas, $mapeo);
    }

    public function test_los_articulos_sin_codigo_con_otro_dato_entran_esten_donde_esten(): void
    {
        $sin_codigo = function (int $cuantos, bool $con_precio = false) {
            $filas = [];
            for ($i = 1; $i <= $cuantos; $i++) {
                $filas[] = [null, 'Artículo nuevo ' . $i, $con_precio ? 50 * $i : null, $i];
            }

            return $filas;
        };
        // 20 con código + 30 sin código ni precio pero con stock.
        $this->assertSame([], self::afuera(self::hoja(20, $sin_codigo(30))));
        // Hoja de stock SIN columna de precio: 20 con código + 30 sin.
        $this->assertSame([], self::afuera(self::hoja(20, $sin_codigo(30), false), false));
        // 40 con código + 30 nuevos sin codificar ni precio.
        $this->assertSame([], self::afuera(self::hoja(40, $sin_codigo(30))));
        // 10 con código arriba + 200 sin código ni precio: la hoja está a medio codificar, no es «con códigos».
        $this->assertSame([], self::afuera(self::hoja(10, $sin_codigo(200))));
    }

    public function test_un_nombre_suelto_debajo_del_ultimo_codigo_queda_afuera_nombrado(): void
    {
        /*
         * «20 con código y 5 al final con solo el nombre»: en una hoja con códigos un nombre sin NINGÚN otro dato no se
         * distingue de un rubro o de una nota (parece_un_rubro() ya los trataba así en el medio). Debajo del último
         * código es pie: los 5 quedan afuera y salen nombrados en el resumen («Si alguna era un producto, decímelo»).
         */
        $nombres = [];
        for ($i = 1; $i <= 5; $i++) {
            $nombres[] = [null, 'Tornillo suelto ' . $i, null, null];
        }
        $afuera = self::afuera(self::hoja(20, $nombres));
        $this->assertSame([22, 23, 24, 25, 26], array_keys($afuera));
        $this->assertSame([PlanillaDePrueba::FILA_TITULO], array_values(array_unique($afuera)));

        // Con un dato más (el stock), los mismos entran.
        $con_stock = array_map(function ($fila) { $fila[3] = 4; return $fila; }, $nombres);
        $this->assertSame([], self::afuera(self::hoja(20, $con_stock)));
    }

    public function test_las_cuentas_de_la_definicion_de_hoja_con_codigos(): void
    {
        /*
         * E8.5. D = filas con datos (nombre + otro dato mapeado, sin rótulo de total), C = las de D con código,
         * S = filas sueltas (una sola celda de texto). con_codigos = C ≥ 10, 4·C ≥ 3·D y 2·S ≤ D.
         *
         * (i) La medición a ciegas: 17 con código, 1 sin código con precio y stock, 4 rubros sueltos, 3 subtotales en
         * el medio con la suma en el stock, y al pie un subtotal, «Son 18 artículos» y 2 notas. D=18, C=17, S=6:
         * 68 ≥ 54 y 12 ≤ 18 → con códigos. Entran los 18 y quedan afuera los 4 rubros, los 4 subtotales, el pie y
         * las 2 notas. En c6efa1f0 los rubros y las notas estaban en el denominador (17·4 = 68 < 23·3 = 69) y
         * entraban 23.
         */
        $filas = [];
        $n = 1;
        $datos = [];
        $rubros = ['--- CONSTRUCCION ---', 'PLOMERIA', '*** Pinturería ***', 'Rubro: Bulonería'];
        $codigo = 0;
        foreach ($rubros as $r => $rubro) {
            if ($r > 0) {
                $filas[++$n] = [null, ['Subtotal construccion', 'Subtotal plomeria', 'Subtotal pintureria'][$r - 1], null, 10 * $r];
            }
            $filas[++$n] = $r === 0 ? [$rubro, null, null, null] : [null, $rubro, null, null];
            $cuantos = $r === 3 ? 5 : 4;
            for ($i = 0; $i < $cuantos; $i++) {
                $filas[++$n] = ['C-' . (++$codigo), 'Artículo ' . $codigo, 100 + $codigo, $codigo];
                $datos[] = $n;
            }
        }
        $filas[++$n] = [null, 'Cable sin código', 1500, 7];
        $datos[] = $n;
        $filas[++$n] = [null, 'Subtotal bulonería', null, 64];
        $filas[++$n] = [null, 'Son 18 artículos', null, null];
        $filas[++$n] = [null, 'Actualizó: Mirta 12/11', null, null];
        $filas[++$n] = ['Firma: Carlos', null, null, null];
        $this->assertSame(17, $codigo);

        $resumen = PlanillaDePrueba::resumir_la_hoja($filas, ['codigo_de_proveedor' => 0, 'nombre' => 1, 'precio' => 2, 'stock_actual' => 3]);
        $this->assertTrue($resumen['con_codigos']);
        $afuera = self::afuera($filas);
        $this->assertSame([], array_values(array_intersect($datos, array_keys($afuera))), 'Ningún artículo afuera.');
        $this->assertSame(count($filas) - 18, count($afuera), 'Todo lo demás afuera: ' . json_encode($afuera));

        // (vi) 10 con código + 200 nombres pelados: S=200 > D/2=5 → NO es «con códigos» y los 200 entran.
        $pelados = [];
        for ($i = 1; $i <= 200; $i++) {
            $pelados[] = [null, 'Nombre pelado ' . $i, null, null];
        }
        $this->assertFalse(PlanillaDePrueba::resumir_la_hoja(self::hoja(10, $pelados), ['codigo_de_proveedor' => 0, 'nombre' => 1, 'precio' => 2, 'stock_actual' => 3])['con_codigos']);
        $this->assertSame([], self::afuera(self::hoja(10, $pelados)));
    }

    public function test_el_pie_que_cuenta_y_la_nota_de_abajo_siguen_afuera(): void
    {
        $afuera = self::afuera(self::hoja(20, [[null, 'Total artículos: 20', null, null], [null, 'Actualizó: Mirta 12/11', null, null], ['Firma: Carlos', null, null, null]]));
        $this->assertSame([22 => PlanillaDePrueba::FILA_TOTAL, 23 => PlanillaDePrueba::FILA_TITULO, 24 => PlanillaDePrueba::FILA_TITULO], $afuera);
    }

    public function test_los_dos_puntos_al_final_con_numeros_son_un_articulo(): void
    {
        // Hoja SIN códigos: «Cable 2x1,5:» es un artículo; «Pinturería:» y «Rubro: Construcción» son títulos.
        $mapeo = ['nombre' => 0, 'precio' => 1];
        $filas = [2 => ['Rubro: Construcción', null], 3 => ['Pinturería:', null], 4 => ['Cable 2x1,5:', null], 5 => ['Martillo', 1000], 6 => ['Tenaza', 900]];
        $this->assertSame([2 => PlanillaDePrueba::FILA_TITULO, 3 => PlanillaDePrueba::FILA_TITULO], PlanillaDePrueba::motivos_de_la_hoja($filas, $mapeo));
    }

    /** Lo mismo que filas() saltea sin contar: ninguna columna mapeada con dato. */
    private static function sin_datos_mapeados(array $fila, array $mapeo): bool
    {
        foreach ($mapeo as $posicion) {
            if (isset($fila[$posicion]) && trim((string) $fila[$posicion]) !== '') {
                return false;
            }
        }

        return true;
    }
}
