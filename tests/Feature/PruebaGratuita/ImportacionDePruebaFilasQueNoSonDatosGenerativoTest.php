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
        for ($caso = 0; $caso < self::PLANILLAS; $caso++) {
            $planilla = GeneradorDePlanillasDesordenadas::articulos($caso);
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
                if (!isset($motivos[$numero]) && !self::sin_datos_mapeados($filas[$numero], $planilla['mapeo'])) {
                    $fallas[] = 'caso ' . $caso . ' fila ' . $numero . ': un NO-DATO entró como artículo '
                        . json_encode($filas[$numero], JSON_UNESCAPED_UNICODE) . ($planilla['con_codigos'] ? ' (con códigos)' : ' (sin códigos)');
                }
            }
        }
        fwrite(STDERR, PHP_EOL . 'Artículos: ' . self::PLANILLAS . ' planillas, ' . $filas_juzgadas . ' filas juzgadas, ' . count($fallas) . ' fallas.' . PHP_EOL);
        $this->assertSame([], array_slice($fallas, 0, 25), 'Semilla ' . self::SEMILLA . ': ' . count($fallas) . ' fallas.');
    }

    public function test_cientos_de_listas_de_contactos(): void
    {
        mt_srand(self::SEMILLA + 1);
        $fallas = [];
        $filas_juzgadas = 0;
        for ($caso = 0; $caso < self::PLANILLAS; $caso++) {
            $lista = GeneradorDePlanillasDesordenadas::contactos($caso);
            $filas = array_values($lista['filas']); // como llega a collection(): 0-based, la fila 1 en el índice 0
            $descartes = LocalImportHelper::filas_que_no_son_contactos_de($filas, $lista['columnas'], $lista['inicio'], count($filas));
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
        fwrite(STDERR, PHP_EOL . 'Contactos: ' . self::PLANILLAS . ' listas, ' . $filas_juzgadas . ' filas juzgadas, ' . count($fallas) . ' fallas.' . PHP_EOL);
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
