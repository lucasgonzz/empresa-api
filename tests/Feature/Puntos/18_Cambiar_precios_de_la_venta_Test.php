<?php

namespace Tests\Feature\Puntos;

use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 18 — `PUT api/sale/update-prices/{id}`, el camino que cambia los precios de los
 * renglones sin pasar por el update completo de la venta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA FAMILIA: EL MÓDULO NO PUEDE DEPENDER DEL REBOTE DE LA CUENTA CORRIENTE.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Es el mismo modo de falla que el del archivo 16 (restaurar de la papelera) y por eso vale
 *  la pena que estén escritos los dos: hay caminos que cambian el monto base de una venta y
 *  que no llaman al reconciliador. Cuando la venta es de CUENTA CORRIENTE zafan de rebote,
 *  porque tocan la cuenta y el enganche de `CurrentAcountPagoHelper::init()` los agarra de
 *  paso; cuando la venta es de MOSTRADOR no rebotan por ningún lado y los puntos quedan
 *  congelados en el monto viejo para siempre.
 *
 *  "Anda por rebote" no es que ande: es que anda en la mitad de los casos y nadie lo mide. Por
 *  eso el enganche va explícito en el controller, y por eso el test usa una venta de
 *  mostrador — que es la mitad que el rebote no cubre.
 *
 *  `updateItemsPrices()` solo toca `article_sale.price` y `price_sin_iva`; NO toca
 *  `sales.total`. O sea que después de bajarle el precio al renglón, la base de puntos cambia
 *  aunque el encabezado de la venta diga lo mismo: los puntos salen del NETO DEL RENGLÓN.
 */
class Cambiar_precios_de_la_venta_Test extends PuntosTestCase
{
    /** Total con IVA de la venta original. Neto al 21%: $100.000 -> 100 puntos. */
    const TOTAL_ORIGINAL = 121000;

    /** El precio nuevo del renglón. Neto al 21%: $50.000 -> 50 puntos. */
    const PRECIO_NUEVO = 60500;

    /**
     * 🔴 Bajarle el precio al renglón de una venta de mostrador tiene que recalcular sus puntos.
     *
     * @group puntos
     * @test
     */
    public function cambiar_los_precios_de_una_venta_de_mostrador_recalcula_sus_puntos()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente  = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $articulo = $this->articulo_de_puntos();

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_ORIGINAL, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote, 'La venta de mostrador tiene que haber otorgado su lote.');
        $this->assertEqualsWithDelta(100, (float) $lote->puntos, self::DELTA);
        $this->assertEqualsWithDelta(100000, (float) $lote->monto_base, self::DELTA);

        $this->putJson('api/sale/update-prices/'.$venta->id, [
            'items' => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => self::PRECIO_NUEVO,
                ],
            ],
        ])->assertStatus(200);

        $lote->refresh();

        $this->assertEqualsWithDelta(
            50000,
            (float) $lote->monto_base,
            self::DELTA,
            'El monto base tiene que seguir al precio nuevo del renglón, no quedarse en el viejo.'
        );
        $this->assertEqualsWithDelta(
            50,
            (float) $lote->puntos,
            self::DELTA,
            'Con la base a la mitad, los puntos tienen que ser la mitad: si siguen en 100, el módulo se quedó con el precio viejo.'
        );

        $this->assertEqualsWithDelta(
            50,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Y el saldo del cliente acompaña: es el mismo lote, actualizado en el lugar.'
        );
    }
}
