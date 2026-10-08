<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasDetector;
use Tests\TestCase;

/**
 * El bloqueo por bigramas también gasta presupuesto (misión importacion-relaciones-parecidas,
 * tanda 4).
 *
 * Con 1999 valores contra 20.000 existentes el chequeo tardaba 46-73 s con el presupuesto de
 * comparaciones sin tocar: cada valor compara 20 como máximo, pero para elegirlos recorre las
 * listas del índice de todos sus bigramas, y eso no se contaba. Ahora se cobra antes de recorrer;
 * agotado, los valores que faltan quedan sin candidatos y la corrida sale `recortado`. Y los
 * candidatos se buscan de los valores con más filas a los de menos, para que lo que se quede sin
 * revisar sea lo que menos pesa.
 *
 * Sin base: el detector es puro.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PresupuestoDeBloqueoTest extends TestCase
{
    protected function existentes()
    {
        return [
            ['id' => 1, 'nombre' => 'Ferretería Del Norte'],
            ['id' => 2, 'nombre' => 'Bulonería Tomi'],
            ['id' => 3, 'nombre' => 'Martillos'],
        ];
    }

    public function test_sin_presupuesto_de_bloqueo_no_se_recorre_nada_y_sale_recortado()
    {
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, 0);

        $grupo = RelacionesParecidasDetector::analizar_grupo(['Martillo' => 1, 'Bulonera Tomy' => 1], $this->existentes(), [], true, $presupuesto);

        $this->assertTrue($grupo['recortado']);
        $this->assertSame('nuevo', $grupo['valores']['Martillo']['estado']);
        $this->assertSame('nuevo', $grupo['valores']['Bulonera Tomy']['estado']);

        /* Ni una comparación: el corte es antes de recorrer las listas. */
        $this->assertSame(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $presupuesto->restante);
    }

    public function test_agotado_en_el_medio_se_quedan_sin_revisar_los_de_menos_filas()
    {
        /* Cuánto cuesta revisar "Martillo" solo. */
        $medida = RelacionesParecidasDetector::nuevo_presupuesto();
        RelacionesParecidasDetector::analizar_grupo(['Martillo' => 5], $this->existentes(), [], true, $medida);
        $costo_martillo = RelacionesParecidasDetector::PRESUPUESTO_BLOQUEO - $medida->bloqueo_restante;

        $this->assertGreaterThan(0, $costo_martillo);

        /* Con eso justo, y "Martillo" ÚLTIMO en el archivo pero con más filas: se revisa él. */
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $costo_martillo);

        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Ferret. del Norte' => 1, 'Bulonera Tomy' => 2, 'Martillo' => 5],
            $this->existentes(),
            [],
            true,
            $presupuesto
        );

        $this->assertTrue($grupo['recortado']);
        $this->assertSame(0, $presupuesto->bloqueo_restante);

        $this->assertSame('consultar', $grupo['valores']['Martillo']['estado']);
        $this->assertSame(3, $grupo['valores']['Martillo']['candidatos'][0]['id']);

        $this->assertSame('nuevo', $grupo['valores']['Bulonera Tomy']['estado']);
        $this->assertSame('nuevo', $grupo['valores']['Ferret. del Norte']['estado']);
    }

    public function test_con_el_presupuesto_de_siempre_no_cambia_nada()
    {
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto();

        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Ferret. del Norte' => 1, 'Bulonera Tomy' => 2, 'Martillo' => 5],
            $this->existentes(),
            [],
            true,
            $presupuesto
        );

        $this->assertFalse($grupo['recortado']);

        foreach (['Ferret. del Norte' => 1, 'Bulonera Tomy' => 2, 'Martillo' => 3] as $valor => $id) {
            $this->assertSame('consultar', $grupo['valores'][$valor]['estado'], $valor);
            $this->assertSame($id, $grupo['valores'][$valor]['candidatos'][0]['id'], $valor);
        }

        /* El orden de los valores en el resultado sigue siendo el del archivo. */
        $this->assertSame(['Ferret. del Norte', 'Bulonera Tomy', 'Martillo'], array_keys($grupo['valores']));
    }
}
