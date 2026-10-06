<?php

namespace Tests\Feature\Compras\PreciosEnLote;

/**
 * Costo en dólares y precios con IVA incluido (misión compras-precios-en-lote, 29/9/2026).
 *
 * - Dólares: el renglón con cost_in_dollars le deja el costo en dólares al artículo y el cálculo
 *   lo cotiza con el dólar del proveedor (Buenos Aires: 1200). En una compra en pesos y en una
 *   compra en dólares (moneda 2).
 * - IVA incluido (precios_incluyen_iva): el costo del renglón viene bruto y se guarda neto
 *   (back-out con la alícuota del artículo) según la condición fiscal de la cuenta, Responsable
 *   Inscripto o Monotributista (set_condicion_iva).
 *
 * Todas con las bonificaciones de Buenos Aires del fixture, así la #3 vuelve a mover el precio y
 * cada escenario pasa también por el caso D1.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Moneda_y_condicion_fiscal_Test extends ComprasPreciosEnLoteTestCase
{
    /** Moneda de la compra: pesos y dólares (tabla monedas del fixture). */
    const PESOS = 1;
    const DOLARES = 2;

    /**
     * @group compras
     * @test
     */
    public function costo_en_dolares_en_una_compra_en_pesos()
    {
        $this->assert_compra_con_costo_en_dolares(self::PESOS, 'costo en dólares, compra en pesos');
    }

    /**
     * @group compras
     * @test
     */
    public function costo_en_dolares_en_una_compra_en_dolares()
    {
        $this->assert_compra_con_costo_en_dolares(self::DOLARES, 'costo en dólares, compra en dólares');
    }

    /**
     * @group compras
     * @test
     */
    public function precios_con_iva_incluido_responsable_inscripto()
    {
        $this->assert_compra_con_iva_incluido('RRII', 'IVA incluido, Responsable Inscripto');
    }

    /**
     * @group compras
     * @test
     */
    public function precios_con_iva_incluido_monotributista()
    {
        $this->assert_compra_con_iva_incluido('MT', 'IVA incluido, Monotributista');
    }

    /**
     * Un artículo en pesos que pasa a costo en dólares con la compra, y uno del catálogo en pesos en
     * el mismo renglón de al lado.
     *
     * @param  int    $moneda_id
     * @param  string $escenario
     * @return void
     */
    protected function assert_compra_con_costo_en_dolares($moneda_id, $escenario)
    {
        $this->set_condicion_iva('RRII');

        $en_dolares = $this->crear_articulo([
            'cost'            => 1500,
            'cost_in_dollars' => 0,
        ]);

        $pinza = $this->articulo('Pinza');

        $r = $this->dos_caminos(function () use ($en_dolares, $pinza, $moneda_id) {

            $compra_id = $this->alta($this->payload_compra([
                'moneda_id' => $moneda_id,
                'articles'  => [
                    $this->renglon($en_dolares, 1.35, 20, ['cost_in_dollars' => 1]),
                    $this->renglon($pinza, 1090, 4),
                ],
            ]));

            return [
                'articulos' => [$en_dolares->id, $pinza->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen($escenario, $r);

        $this->assert_hubo_caso_d1($r, 2);

        $this->assertSame('1', $r['motor']['articles'][$en_dolares->id]['cost_in_dollars'], 'Guarda: el renglón en dólares le tenía que dejar el costo en dólares al artículo.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * Tres artículos con alícuotas distintas (21%, 10,5% y exento) y el costo del renglón con IVA
     * incluido.
     *
     * @param  string $condicion 'RRII' o 'MT'.
     * @param  string $escenario
     * @return void
     */
    protected function assert_compra_con_iva_incluido($condicion, $escenario)
    {
        $this->set_condicion_iva($condicion);

        $pinza    = $this->articulo('Pinza');
        $cuchilla = $this->articulo('Cuchilla');
        $cuchara  = $this->articulo('Cuchara');

        $r = $this->dos_caminos(function () use ($pinza, $cuchilla, $cuchara) {

            $compra_id = $this->alta($this->payload_compra([
                'precios_incluyen_iva' => 1,
                'articles'             => [
                    $this->renglon($pinza, 1331, 10),
                    $this->renglon($cuchilla, 580.2, 5),
                    $this->renglon($cuchara, 111, 8),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $cuchilla->id, $cuchara->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen($escenario, $r);

        $this->assert_hubo_caso_d1($r, 3);

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }
}
