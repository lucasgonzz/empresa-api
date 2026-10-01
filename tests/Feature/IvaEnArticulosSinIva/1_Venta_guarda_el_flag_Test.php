<?php

namespace Tests\Feature\IvaEnArticulosSinIva;

use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\Sale;

/**
 * Archivo 1 — LA VENTA guarda, preserva y devuelve `iva_en_articulos_sin_iva`.
 *
 * El contrato con la SPA (plan de la mision, "Contrato SPA <-> API"):
 *  - el campo es OPCIONAL en el POST y el PUT de `sales`;
 *  - SPA vieja + API nueva: en el alta queda en 0 y en el update se preserva lo guardado;
 *  - viene de vuelta en el modelo, para que la SPA sepa de que estado parten los precios.
 *
 * Mismo patron que `iva_aplicado` (ver Sales/31, que fija la preservacion de su pariente).
 *
 * @group sales
 * @group iva_en_articulos_sin_iva
 */
class Venta_guarda_iva_en_articulos_sin_iva_Test extends IvaEnArticulosSinIvaTestCase
{
    /**
     * Alta con el check prendido: queda en 1 en la base y viaja de vuelta en la respuesta.
     *
     * @test
     */
    public function el_alta_con_el_flag_prendido_lo_guarda_y_lo_devuelve()
    {
        $modelo = $this->crear_venta_por_endpoint($this->payload_venta([
            'iva_en_articulos_sin_iva' => 1,
        ]));

        $this->assertSame(
            1,
            (int) Sale::find($modelo['id'])->iva_en_articulos_sin_iva,
            'Con el check prendido en Vender, la venta tiene que guardarlo en 1.'
        );

        $this->assertArrayHasKey(
            'iva_en_articulos_sin_iva',
            $modelo,
            'La venta que vuelve a la SPA tiene que traer el flag: sin el, al editarla no sabe de que estado parten los precios.'
        );
        $this->assertSame(1, (int) $modelo['iva_en_articulos_sin_iva']);
    }

    /**
     * 🔴 SPA vieja: el alta no manda la clave y la venta queda en 0, que es el comportamiento de
     * siempre (ningun articulo sin IVA recibio IVA).
     *
     * @test
     */
    public function el_alta_sin_el_flag_lo_deja_apagado()
    {
        $payload = $this->payload_venta();

        $this->assertArrayNotHasKey('iva_en_articulos_sin_iva', $payload, 'El escenario es el POST de una SPA vieja.');

        $modelo = $this->crear_venta_por_endpoint($payload);

        $this->assertSame(
            0,
            (int) Sale::find($modelo['id'])->iva_en_articulos_sin_iva,
            'Un alta sin la clave tiene que quedar en 0.'
        );
    }

    /**
     * El update que manda la clave la cambia, en los dos sentidos: el check se prende y se apaga en
     * cualquier momento (pedido de Lucas).
     *
     * @test
     */
    public function el_update_con_el_flag_lo_cambia_en_los_dos_sentidos()
    {
        $venta = $this->venta_en_base(['iva_en_articulos_sin_iva' => 0]);

        $this->putJson('api/sale/'.$venta->id, $this->payload_actualizar_venta($venta, [
            'iva_en_articulos_sin_iva' => 1,
        ]))->assertStatus(200);

        $this->assertSame(1, (int) Sale::find($venta->id)->iva_en_articulos_sin_iva, 'El PUT con 1 tiene que prenderlo.');

        $this->putJson('api/sale/'.$venta->id, $this->payload_actualizar_venta($venta, [
            'iva_en_articulos_sin_iva' => 0,
        ]))->assertStatus(200);

        $this->assertSame(0, (int) Sale::find($venta->id)->iva_en_articulos_sin_iva, 'El PUT con 0 tiene que apagarlo.');
    }

    /**
     * 🔴 SPA vieja editando una venta que tenia el check prendido: el PUT no trae la clave y la
     * venta se queda con lo guardado. Si cayera a 0, la SPA nueva leeria mal el estado de partida
     * de los precios guardados al reabrirla.
     *
     * @test
     */
    public function el_update_sin_el_flag_preserva_lo_guardado()
    {
        $venta = $this->venta_en_base(['iva_en_articulos_sin_iva' => 1]);

        $payload = $this->payload_actualizar_venta($venta);

        $this->assertArrayNotHasKey('iva_en_articulos_sin_iva', $payload, 'El escenario es el PUT de una SPA vieja.');

        $this->putJson('api/sale/'.$venta->id, $payload)->assertStatus(200);

        $this->assertSame(
            1,
            (int) Sale::find($venta->id)->iva_en_articulos_sin_iva,
            'Un PUT sin la clave le cambio el flag a la venta.'
        );
    }

    /**
     * La venta consolidada de facturacion toma el flag de la primera venta, igual que toma
     * `iva_aplicado`: los renglones se copian con sus precios.
     *
     * @test
     */
    public function la_venta_consolidada_toma_el_flag_de_la_primera_venta()
    {
        $cliente = $this->cliente();

        $afip_information = AfipInformation::where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

        $venta = $this->venta_en_base([
            'client_id'                  => $cliente->id,
            'omitir_en_cuenta_corriente' => 1,
            'iva_en_articulos_sin_iva'   => 1,
        ]);

        $consolidada = ConsolidarFacturacionHelper::consolidar(
            [$venta->id],
            $cliente->id,
            $this->comercio()->id,
            $afip_information->id,
            1,
            false,
            [],
            false
        );

        $this->assertSame(
            1,
            (int) Sale::find($consolidada->id)->iva_en_articulos_sin_iva,
            'La consolidada tiene que llevarse el flag de la venta que consolida.'
        );
    }
}
