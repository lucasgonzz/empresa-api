<?php

namespace Tests\Feature\Compras\PreciosEnLote;

/**
 * La compra común: costo nuevo en cada renglón, sin bonificaciones, sin flete y sin descuentos
 * (misión compras-precios-en-lote, 29/9/2026).
 *
 * Es el caso que la decisión D1 promete idéntico: "una compra sin flete ni descuentos nuevos queda
 * idéntica a hoy en articles y en price_changes". Hoy, la llamada #1 (update_cost) ya deja el
 * precio final y la #3 lo recalcula sin cambios, así que había UN cambio de precio por artículo, y
 * el "Precio Final" del historial de proveedores ya era el final (D2: "mismo valor que hoy en la
 * compra común"). Por eso acá, además de las reglas, se exige la foto idéntica en TODO.
 *
 * Cuatro artículos del catálogo del fixture con IVA distinto (21%, 21%, 10,5% y exento), para que
 * el cálculo pase por las tres ramas de la alícuota.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Compra_comun_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * @group compras
     * @test
     */
    public function compra_simple_con_costo_nuevo_queda_identica_a_la_de_hoy()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $martillo = $this->articulo('Martillo acero');
        $pinza    = $this->articulo('Pinza');
        $cuchilla = $this->articulo('Cuchilla');
        $cuchara  = $this->articulo('Cuchara');

        $r = $this->dos_caminos(function () use ($martillo, $pinza, $cuchilla, $cuchara) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($martillo, 2150, 4),
                    $this->renglon($pinza, 1080, 10),
                    $this->renglon($cuchilla, 530, 6),
                    $this->renglon($cuchara, 95, 12),
                ],
            ]));

            return [
                'articulos' => [$martillo->id, $pinza->id, $cuchilla->id, $cuchara->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('compra simple con costo nuevo', $r);

        $this->assertSame(4, count($r['hoy']['cambios']), 'Guarda: la compra tenía que cambiar el precio de los cuatro artículos.');

        $this->assertSame([], $r['resumen']['varios_hoy'], 'Referencia: sin flete ni descuentos, hoy ya quedaba un solo cambio de precio por artículo.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);

        $this->assert_identico($r['hoy'], $r['motor'], 'D1: una compra sin flete ni descuentos nuevos tiene que quedar idéntica a la de hoy en todo.');
    }
}
