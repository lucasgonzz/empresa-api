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
