<?php

namespace Tests\Feature\Compras;

use App\Models\ProviderOrder;

/**
 * Tanda correctivos 24/8 — ítem 4: al BORRAR una compra,
 * ProviderOrderHelper::resetArticlesStock() devolvía al stock la cantidad PEDIDA
 * (pivot->amount) sin mirar la recibida. Pediste 10, entraron 3, y al borrar salían 10:
 * el stock quedaba 7 unidades abajo de la realidad.
 *
 * Regla de Lucas (informe 20260824 §7): devolver la RECIBIDA si está indicada, si no la
 * pedida — la misma semántica de interpretar_cantidad_real() que usó la compra para SUMAR
 * (incluido el 0 cargado a mano, que es un dato real: "llegaron 0").
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Borrar_compra_devuelve_lo_recibido_Test extends ComprasTestCase
{
    /**
     * Crea la compra por el endpoint real y devuelve el modelo persistido.
     *
     * @param array $payload
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra($payload)
    {
        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('model', $cuerpo, 'POST api/provider-order no devolvió la compra.');

        return ProviderOrder::find($cuerpo['model']['id']);
    }

    /**
     * Pedida 10 / recibida 3: la compra suma 3 al stock, y borrarla devuelve exactamente
     * esos 3 (no los 10 pedidos). El stock termina donde arrancó.
     *
     * @group compras
     * @test
     */
    public function borrar_una_compra_con_recibida_indicada_devuelve_la_recibida()
    {
        $articulo = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($articulo);
        $stock_inicial = (float) $articulo->stock;

        try {

            $compra = $this->crear_compra($this->payload_compra([
                'moneda_id' => 1,
                'articles'  => [
                    $this->item('Pinza', 1000, 10, ['received' => 3]),
                ],
            ]));

            // La compra sumó la cantidad REAL (3), no la pedida: es la semántica ya cubierta
            // por 5_Cantidad_Recibida_Test, acá solo se ancla el punto de partida.
            $this->assertEquals(
                $stock_inicial + 3,
                (float) $this->articulo('Pinza')->stock,
                'La compra no dejó el stock con la cantidad recibida; el escenario del test no sirve.'
            );

            $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

            // El corazón del ítem: antes del fix acá quedaba stock_inicial - 7 (devolvía 10).
            $this->assertEquals(
                $stock_inicial,
                (float) $this->articulo('Pinza')->stock,
                'Borrar la compra tiene que devolver la cantidad RECIBIDA (3), no la pedida (10).'
            );

        } finally {
            $this->restaurar_articulo($articulo, $snapshot);
        }
    }

    /**
     * Sin recibida cargada (null): borrar devuelve la pedida, que es lo que la compra sumó.
     * El comportamiento histórico para compras sin recepción indicada no cambia.
     *
     * @group compras
     * @test
     */
    public function borrar_una_compra_sin_recibida_devuelve_la_pedida()
    {
        $articulo = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($articulo);
        $stock_inicial = (float) $articulo->stock;

        try {

            $compra = $this->crear_compra($this->payload_compra([
                'moneda_id' => 1,
                'articles'  => [
                    $this->item('Pinza', 1000, 10, ['received' => null]),
                ],
            ]));

            $this->assertEquals(
                $stock_inicial + 10,
                (float) $this->articulo('Pinza')->stock,
                'La compra sin recibida tiene que sumar la pedida; el escenario del test no sirve.'
            );

            $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

            $this->assertEquals(
                $stock_inicial,
                (float) $this->articulo('Pinza')->stock,
                'Borrar la compra sin recibida tiene que devolver la pedida (10).'
            );

        } finally {
            $this->restaurar_articulo($articulo, $snapshot);
        }
    }

    /**
     * Recibida 0 cargada a mano ("llegaron 0"): la compra no movió stock, y borrarla
     * tampoco tiene que moverlo. Con el código viejo, borrar restaba las 10 pedidas.
     *
     * @group compras
     * @test
     */
    public function borrar_una_compra_con_recibida_cero_no_mueve_stock()
    {
        $articulo = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($articulo);
        $stock_inicial = (float) $articulo->stock;

        try {

            $compra = $this->crear_compra($this->payload_compra([
                'moneda_id' => 1,
                'articles'  => [
                    $this->item('Pinza', 1000, 10, ['received' => 0]),
                ],
            ]));

            $this->assertEquals(
                $stock_inicial,
                (float) $this->articulo('Pinza')->stock,
                'La compra con recibida 0 no tiene que sumar stock; el escenario del test no sirve.'
            );

            $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

            $this->assertEquals(
                $stock_inicial,
                (float) $this->articulo('Pinza')->stock,
                'Borrar una compra con recibida 0 no tiene que mover el stock.'
            );

        } finally {
            $this->restaurar_articulo($articulo, $snapshot);
        }
    }
}
