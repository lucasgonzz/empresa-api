<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\CotizacionEnvioGuardHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión asistente-diario-20261128, P8 (CA15 y CA17 unit) — TESTS DE ACEPTACIÓN del filtro de cotizaciones de envío.
 *
 * EL CASO REAL (Truvari, 27/11/2026, mensajes 271, 275 y 290): el modelo ya había dicho «el costo de envío y el total
 * los confirma el equipo» y el filtro, al sacar una oración con un importe, sumaba además su FRASE_FIJA: el cliente
 * recibía la misma idea dos veces. Y cuando fue el COMERCIO quien dijo «no tiene costo de envío» (respuesta manual,
 * mensajes 285 y 288), el filtro sacaba esa misma afirmación cuando la repetía el modelo.
 *
 * Qué fija este archivo (función pura, sin Laravel):
 *   CA15 la frase fija no se agrega si lo que quedó ya dice «lo confirma el equipo» sobre el envío o el total;
 *   CA17 `revisar($cuerpo, $dichos_del_negocio)` conserva lo que el comercio ya dijo (gratuidad o un importe
 *        idéntico) y sigue derivando un importe distinto.
 *
 * 🔴 Estado esperado en el commit de tests (develop, filtro de un solo argumento):
 *   - ROJOS: `la_frase_no_se_repite_con_los_textos_271_275_290` y `lo_que_dijo_el_comercio_no_se_deriva`.
 *   - GUARDAS que ya pasan hoy: `sin_frase_propia_sale_la_fija` (CA15, «hoy igual») y `un_importe_distinto_al_del_comercio_se_deriva`
 *     (CA17, el segundo argumento no puede abrir un hueco).
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CotizacionEnvioGuardHelperFraseYDichosTest extends TestCase
{
    /** Frase fija que el filtro agrega cuando saca una oración. */
    const FRASE = 'El costo de envío y el total final los confirma el equipo en breve.';

    /** Una oración con el importe de un envío: el filtro tiene que sacarla siempre. */
    const ENVIO_CON_IMPORTE = 'El envío sale $7.000.';

    /**
     * Cuántas veces el texto dice alguna forma de «confirma» (la palabra que lleva la idea «lo confirma el equipo»).
     *
     * @param  string  $texto
     * @return int
     */
    protected function veces_que_confirma($texto)
    {
        return (int) preg_match_all('/\bconfirm\w*/iu', $texto);
    }

    /**
     * 🔴 CA15 — Los cuerpos de los mensajes 271, 275 y 290 (con su oración «el equipo lo confirma» y una oración con
     * el importe del envío): el filtro saca el importe, deriva y NO suma la frase fija; la idea aparece una sola vez.
     *
     * @group whatsapp
     * @test
     */
    public function la_frase_no_se_repite_con_los_textos_271_275_290()
    {
        $cuerpos = [
            '271' => "Gracias, Fernando. El costo de envío a La Calera y el total final los confirma el equipo.\n\nQueda armado el pedido a su nombre con envío a La Calera. " . self::ENVIO_CON_IMPORTE,
            '275' => "Hola. No, no tenemos local a la calle.\n\nSobre su pedido, el equipo le confirma la fecha de entrega junto con el costo de envío y el total. " . self::ENVIO_CON_IMPORTE,
            '290' => "Buenas tardes. Le aclaro: las entregas en Córdoba capital las hacemos, y el costo de envío y el total final los confirma el equipo. " . self::ENVIO_CON_IMPORTE,
        ];

        foreach ($cuerpos as $mensaje => $cuerpo) {

            $r = CotizacionEnvioGuardHelper::revisar($cuerpo);

            $this->assertTrue($r['derivo'], '[' . $mensaje . '] sacó una oración con importe: sigue derivando (el chat queda marcado).');
            $this->assertStringNotContainsString('$7.000', $r['body'], '[' . $mensaje . '] el importe no puede quedar.');
            $this->assertStringNotContainsString(self::FRASE, $r['body'], '[' . $mensaje . '] la frase fija no se suma: el texto ya dice lo mismo.');
            $this->assertSame(1, $this->veces_que_confirma($r['body']), '[' . $mensaje . '] «lo confirma el equipo» aparece una sola vez.');
        }

        // «Los confirma el equipo» exacto en los cuerpos que lo traen (271 y 290).
        $r = CotizacionEnvioGuardHelper::revisar($cuerpos['271']);
        $this->assertSame(1, substr_count($r['body'], 'los confirma el equipo'));
    }

    /**
     * CA15 — Guarda («hoy igual»): un cuerpo sin frase propia —sólo el importe del envío— sale con la frase fija,
     * y un cuerpo con otra oración que no habla de la confirmación la conserva y suma la fija al final.
     *
     * @group whatsapp
     * @test
     */
    public function sin_frase_propia_sale_la_fija()
    {
        $r = CotizacionEnvioGuardHelper::revisar(self::ENVIO_CON_IMPORTE);

        $this->assertTrue($r['derivo']);
        $this->assertSame(self::FRASE, $r['body']);

        $r = CotizacionEnvioGuardHelper::revisar('Hola, tenemos stock del Malbec. ' . self::ENVIO_CON_IMPORTE);

        $this->assertTrue($r['derivo']);
        $this->assertSame('Hola, tenemos stock del Malbec.' . "\n\n" . self::FRASE, $r['body']);
    }

    /**
     * 🔴 CA17 — Lo que el COMERCIO ya dijo no se deriva: la gratuidad que repite el modelo y el importe idéntico al
     * que dijo una persona del negocio salen intactos y sin marcar el chat. Sin los dichos, el filtro es el de hoy.
     *
     * @group whatsapp
     * @test
     */
    public function lo_que_dijo_el_comercio_no_se_deriva()
    {
        // Gratuidad dicha por el comercio y repetida por el modelo.
        $cuerpo = 'En Córdoba capital no tiene costo de envío.';
        $r = CotizacionEnvioGuardHelper::revisar($cuerpo, ['Para su zona no tiene costo de envio']);

        $this->assertFalse($r['derivo'], 'Lo que dijo el comercio no se saca.');
        $this->assertSame($cuerpo, $r['body'], 'El cuerpo vuelve intacto.');
        $this->assertSame(0, $r['frases']);

        // Importe idéntico al que dijo el comercio.
        $cuerpo = 'El envío sale $7.000.';
        $r = CotizacionEnvioGuardHelper::revisar($cuerpo, ['el costo de envio es $7.000']);

        $this->assertFalse($r['derivo']);
        $this->assertSame($cuerpo, $r['body']);

        // Sin dichos del negocio (o con el segundo argumento vacío) el filtro es el de siempre.
        $this->assertTrue(CotizacionEnvioGuardHelper::revisar('En Córdoba capital no tiene costo de envío.')['derivo']);
        $this->assertTrue(CotizacionEnvioGuardHelper::revisar('En Córdoba capital no tiene costo de envío.', [])['derivo']);
    }

    /**
     * CA17 — Guarda: un importe DISTINTO al que dijo el comercio sigue derivándose (el modelo no puede inventar otra
     * cifra), y un dicho del comercio que no habla de envío no rescata nada. Pasa hoy porque el segundo argumento se
     * ignora: tiene que seguir pasando cuando se lo lea.
     *
     * @group whatsapp
     * @test
     */
    public function un_importe_distinto_al_del_comercio_se_deriva()
    {
        $r = CotizacionEnvioGuardHelper::revisar('El envío sale $9.000.', ['el costo de envio es $7.000']);

        $this->assertTrue($r['derivo']);
        $this->assertStringNotContainsString('$9.000', $r['body']);

        // Un dicho del comercio que no habla de envío ni de gratuidad no rescata la afirmación del modelo.
        $r = CotizacionEnvioGuardHelper::revisar('El envío sale $7.000.', ['Hola buenas tardes! Díganos en que podemos ayudarlo?']);

        $this->assertTrue($r['derivo']);
        $this->assertStringNotContainsString('$7.000', $r['body']);

        // Una gratuidad del modelo con un dicho del comercio que fija un importe (no gratuidad) tampoco se rescata.
        $r = CotizacionEnvioGuardHelper::revisar('En Córdoba capital no tiene costo de envío.', ['el costo de envio es $7.000']);

        $this->assertTrue($r['derivo']);
    }
}
