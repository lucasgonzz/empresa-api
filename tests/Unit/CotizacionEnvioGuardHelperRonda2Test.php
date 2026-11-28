<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\CotizacionEnvioGuardHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión asistente-diario-20261128, ronda 2 (revisión A) — TESTS: el filtro de cotizaciones sólo conserva una
 * oración del modelo si repite lo que el comercio dijo en UNA misma oración suya.
 *
 * EL CASO REAL ([285], Truvari): el comercio explicó la política de envíos con un mínimo de compra de $80.000 para
 * envío sin cargo, el peaje en ciertas localidades y un costo de envío de $7.000 para pedidos menores. El filtro
 * dejaba pasar «Para La Calera el envío es sin cargo», «El envío a La Calera sale $7.000» y «El envío te sale
 * $80.000» (el mínimo de compra tomado como costo).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CotizacionEnvioGuardHelperRonda2Test extends TestCase
{
    /** Lo que escribió el comercio en [285] (recortado a la política de envíos). */
    const DICHO_285 = 'Hola! Realizamos envíos en Cordoba Capital y alrededores con un minimo de compra de $80.000 para envios sin cargo y en zonas como La Calera, Villa Allende y Carlos Paz, se cobra el costo de peaje vigente (ida y vuelta). Si el pedido no supera los $80.000 el costo de envio es $7.000. Para Envios al interior de Cordoba u otras provincias, lo realizamos via Andreani, cotizando el costo del mismo.';

    /**
     * Las tres afirmaciones del modelo que NO repiten lo que dijo el comercio: se derivan.
     *
     * @group whatsapp
     * @test
     */
    public function lo_que_el_comercio_no_dijo_se_deriva_aunque_se_parezca()
    {
        $frases = [
            'Para La Calera el envío es sin cargo.',
            'El envío a La Calera sale $7.000.',
            'El envío te sale $80.000.',
        ];

        foreach ($frases as $frase) {

            $r = CotizacionEnvioGuardHelper::revisar($frase, [self::DICHO_285]);

            $this->assertTrue($r['derivo'], 'Tiene que derivarse: ' . $frase);
            $this->assertNotSame($frase, $r['body'], 'Tiene que salir con la frase fija: ' . $frase);
        }
    }

    /**
     * La guarda del otro lado: lo que sí repite lo que el comercio dijo en esa oración no se deriva.
     *
     * @group whatsapp
     * @test
     */
    public function lo_que_repite_una_oracion_del_comercio_no_se_deriva()
    {
        $frase = 'El envío sale $7.000.';

        $r = CotizacionEnvioGuardHelper::revisar($frase, [self::DICHO_285]);

        $this->assertFalse($r['derivo']);
        $this->assertSame($frase, $r['body']);

        // El mínimo de compra dicho como mínimo de compra tampoco es una cotización inventada.
        $frase = 'Si el pedido no supera los $80.000 el costo de envío es $7.000.';

        $r = CotizacionEnvioGuardHelper::revisar($frase, [self::DICHO_285]);

        $this->assertFalse($r['derivo']);
    }

    /**
     * Revisión 2 C: una oración del MODELO no se clasifica: todo importe suyo es costo de envío. Con palabras como
     * pedido, compra, hasta o total en la frase, el $80.000 del mínimo de compra del comercio no la rescata.
     *
     * @group whatsapp
     * @test
     */
    public function un_importe_del_modelo_es_costo_aunque_hable_del_pedido_o_la_compra()
    {
        $frases = [
            'El envío de tu pedido sale $80.000.',
            'El envío de tu compra sale $80.000.',
            'Para tu pedido, el envío sale $80.000.',
            'El envío hasta tu casa sale $80.000.',
            'El envío del pedido es de $80.000.',
            'Como tu pedido supera $80.000 el envío es sin cargo.',
        ];

        foreach ($frases as $frase) {

            $r = CotizacionEnvioGuardHelper::revisar($frase, [self::DICHO_285]);

            $this->assertTrue($r['derivo'], 'Tiene que derivarse: ' . $frase);
            $this->assertStringNotContainsString('$80.000', $r['body'], 'El importe no puede salir: ' . $frase);
        }
    }
}
