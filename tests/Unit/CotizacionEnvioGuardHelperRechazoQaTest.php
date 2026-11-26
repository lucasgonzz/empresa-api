<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\CotizacionEnvioGuardHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión truvari-envio-coherente v2, rechazo del QA independiente: formas de cotizar que el filtro dejaba pasar.
 * Complementa (no reemplaza ni debilita) `CotizacionEnvioGuardHelperTest`, que es el commit de aceptación.
 *
 * La 1ª frase sale del corpus real `tr-t2-bis.json` (turno 2). Se prueba el efecto: lo que NO puede quedar es el
 * importe (o la afirmación de gratuidad) en el texto que llega al cliente; lo que NO se toca son los precios de
 * catálogo y los subtotales de producto.
 */
class CotizacionEnvioGuardHelperRechazoQaTest extends TestCase
{
    const FRASE = 'El costo de envío y el total final los confirma el equipo en breve.';

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public function cotizaciones_que_no_pueden_salir()
    {
        return [
            'corpus tr-t2-bis #112: total del pedido sin la palabra total' => [
                "El envío a La Calera le saldría \$13.000 en total: \$7.000 de envío más \$6.000 de peaje. Con las 2 botellas de Fernet Branca, el pedido quedaría en \$47.000.\n\nSi le parece bien, dígame su nombre y le dejo el pedido armado para confirmarlo.",
                ['$47.000', '$13.000', '$7.000', '$6.000'],
            ],
            'entrega a domicilio con importe sin separador de miles' => [
                'Tenemos Fernet a $17.000. La entrega a domicilio $7000 en La Calera.',
                ['$7000', 'entrega a domicilio'],
            ],
            'delivery' => [
                'Tenemos Fernet a $17.000. El delivery $47000 con todo.',
                ['$47000'],
            ],
            'traslado' => [
                'Tenemos Fernet a $17.000. El traslado $47000.',
                ['$47000'],
            ],
            'gratuidad: no tiene costo' => [
                'Tenemos Fernet a $17.000. El envío no tiene costo.',
                ['no tiene costo'],
            ],
            'gratuidad: envio sin tilde no tiene costo' => [
                'Tenemos Fernet a $17.000. El envio no tiene costo para usted.',
                ['no tiene costo'],
            ],
            'gratuidad: no le cobramos' => [
                'Tenemos Fernet a $17.000. No le cobramos el envío.',
                ['No le cobramos'],
            ],
            'gratuidad: corre por nuestra cuenta (sin la palabra envío)' => [
                'Tenemos Fernet a $17.000. Eso corre por nuestra cuenta.',
                ['por nuestra cuenta'],
            ],
            'viñeta que sólo trae un importe en negrita' => [
                "Le detallo:\n- Fernet Branca 750 ml a \$17.000\n- *\$47.000*\nQuedo atento.",
                ['$47.000'],
            ],
            'viñeta con importe solo sin formato' => [
                "Le detallo:\n• \$47.000\nQuedo atento.",
                ['$47.000'],
            ],
            'el pedido sale con importe' => [
                'Tenemos Fernet a $17.000. Su pedido sale $47.000.',
                ['$47.000'],
            ],
            'la compra suma' => [
                'Tenemos Fernet a $17.000. La compra suma $47.000.',
                ['$47.000'],
            ],
            'tendría que abonar' => [
                'Tenemos Fernet a $17.000. En total tendría que abonar $47.000.',
                ['$47.000'],
            ],
        ];
    }

    /**
     * @group whatsapp
     * @test
     * @dataProvider cotizaciones_que_no_pueden_salir
     */
    public function lo_que_cotiza_se_saca_y_no_queda_ningun_resto($texto, array $prohibidos)
    {
        $r = CotizacionEnvioGuardHelper::revisar($texto);

        $this->assertTrue($r['derivo'], 'Tenía que derivar: ' . $texto);
        $this->assertStringEndsWith(self::FRASE, $r['body']);
        foreach ($prohibidos as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $r['body'], 'No tenía que quedar: ' . $prohibido);
        }
    }

    /**
     * El precio de catálogo y el subtotal de producto siguen saliendo tal cual.
     *
     * @return array<string, array{0: string}>
     */
    public function textos_que_no_se_tocan()
    {
        return [
            'catálogo' => ['Fernet Branca 750 ml a $17.000.'],
            'subtotal de producto' => ['Las 2 botellas serían $34.000.'],
            'catálogo en viñeta con etiqueta' => ["Tenemos:\n- Fernet Branca 750 ml: \$17.000\n- Gancia 750 ml: \$9.500"],
            'entrega sin importe ni gratuidad' => ['La entrega a domicilio la coordinamos con usted.'],
            'delivery nombrado sin importe' => ['Tenemos delivery en la zona.'],
            'traslado nombrado sin importe' => ['No hacemos traslado de heladeras.'],
            'pedido sin importe' => ['Con gusto le armo el pedido.'],
        ];
    }

    /**
     * @group whatsapp
     * @test
     * @dataProvider textos_que_no_se_tocan
     */
    public function el_catalogo_y_los_subtotales_salen_byte_por_byte_iguales($texto)
    {
        $r = CotizacionEnvioGuardHelper::revisar($texto);

        $this->assertFalse($r['derivo'], 'No tenía que derivar: ' . $texto);
        $this->assertSame($texto, $r['body']);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_pedido_del_corpus_112_conserva_el_cierre_y_pierde_todo_importe()
    {
        $r = CotizacionEnvioGuardHelper::revisar(
            "El envío a La Calera le saldría \$13.000 en total: \$7.000 de envío más \$6.000 de peaje. Con las 2 botellas de Fernet Branca, el pedido quedaría en \$47.000.\n\nSi le parece bien, dígame su nombre y le dejo el pedido armado para confirmarlo."
        );

        $this->assertStringContainsString('dígame su nombre', $r['body']);
        $this->assertSame(0, preg_match('/\$\s*\d/', $r['body']), 'Quedó un importe: ' . $r['body']);
    }
}
