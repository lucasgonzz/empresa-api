<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\CotizacionEnvioGuardHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión truvari-envio-coherente v2, 2º rechazo del QA independiente (ronda 2). Complementa (no reemplaza ni
 * debilita) `CotizacionEnvioGuardHelperTest` (aceptación) y `CotizacionEnvioGuardHelperRechazoQaTest` (ronda 1).
 *
 * Tres familias de hueco:
 *  1. Segmentación: un punto seguido de espacio dentro de una abreviatura («Av. Colón», «Gral. Belgrano», «aprox.
 *     7.000») cortaba la oración en dos y el importe quedaba sin la palabra de envío.
 *  2. Rótulo y valor en líneas distintas («Costo de envío:\n$7.000 (La Calera)»).
 *  3. Palabras de cotización que faltaban: entrega, «precio final», suma/igualdad de importes, «$X más».
 *
 * Se prueba el efecto: lo que NO puede quedar es el importe en el texto que llega al cliente. Además se fija que
 * los precios de catálogo y las abreviaturas inocentes no se toquen.
 */
class CotizacionEnvioGuardHelperRechazoQa2Test extends TestCase
{
    const FRASE = 'El costo de envío y el total final los confirma el equipo en breve.';

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public function cotizaciones_que_no_pueden_salir()
    {
        return [
            'segmentación: Av. + número' => [
                'El envío a Av. Colón 1200 le sale $7.000.',
                ['$7.000'],
            ],
            'segmentación: Gral. dentro del nombre de la localidad' => [
                'El envío a Villa Gral. Belgrano sale $9.000.',
                ['$9.000'],
            ],
            'segmentación: Sta. dentro del nombre de la localidad' => [
                'El envío a Sta. Rosa de Calamuchita sale $12.000.',
                ['$12.000'],
            ],
            'segmentación: aprox. antes del número' => [
                'El envío sale aprox. 7.000 pesos a La Calera.',
                ['7.000'],
            ],
            'segmentación: Bv. en una oración vecina del catálogo' => [
                'Tenemos Fernet a $17.000. El flete a Bv. Illia 300 cuesta $8.000.',
                ['$8.000'],
            ],
            'segmentación: iniciales sueltas (J. M. Paz)' => [
                'El envío a J. M. Paz 450 cuesta $6.500.',
                ['$6.500'],
            ],
            'rótulo y valor en líneas distintas' => [
                "Costo de envío:\n\$7.000 (La Calera)",
                ['$7.000'],
            ],
            'rótulo y valor, con texto antes y después' => [
                "Tenemos Fernet a \$17.000.\nCosto de envío:\n\$7.000 (La Calera)\nQuedo atento.",
                ['$7.000'],
            ],
            'rótulo de envío con varias viñetas' => [
                "Opciones de envío:\n- Retiro: sin cargo\n- Domicilio: \$7.000\n- Express: \$9.000\nQuedo atento.",
                ['$7.000', '$9.000'],
            ],
            'entrega: el costo de entrega' => [
                'El costo de entrega es $7.000.',
                ['$7.000'],
            ],
            'entrega: rótulo con importe' => [
                'Entrega: $7.000',
                ['$7.000'],
            ],
            'entrega: gastos de entrega' => [
                'Los gastos de entrega son $7.000.',
                ['$7.000'],
            ],
            'precio final' => [
                'Precio final: $47.000',
                ['$47.000'],
            ],
            'suma de importes con igual' => [
                '$34.000 + $13.000 = $47.000',
                ['$47.000', '$13.000'],
            ],
            'importe con «más» al final (adicional)' => [
                'Para La Calera son $7.000 más.',
                ['$7.000'],
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
     * Lo que no se toca: catálogo, subtotales y abreviaturas que no cotizan nada.
     *
     * @return array<string, array{0: string}>
     */
    public function textos_que_no_se_tocan()
    {
        return [
            'catálogo con abreviatura' => ['Tenemos el Fernet Branca 750 ml (aprox. 17.000 pesos la unidad).'],
            'catálogo en dos oraciones' => ['Fernet Branca 750 ml a $17.000. Gancia 750 ml a $9.500.'],
            'dirección del local sin importe' => ['Estamos en Av. Colón 1200, de lunes a viernes.'],
            'rótulo sin envío con precio de catálogo' => ["Precios de hoy:\n- Fernet Branca 750 ml \$17.000"],
            'precio con IVA aparte' => ['El Fernet Branca está $17.000 más IVA.'],
            'entrega sin importe' => ['La entrega la coordinamos con usted.'],
            'plazo de entrega' => ['La entrega demora 3 días hábiles.'],
        ];
    }

    /**
     * @group whatsapp
     * @test
     * @dataProvider textos_que_no_se_tocan
     */
    public function el_catalogo_y_las_abreviaturas_inocentes_salen_byte_por_byte_iguales($texto)
    {
        $r = CotizacionEnvioGuardHelper::revisar($texto);

        $this->assertFalse($r['derivo'], 'No tenía que derivar: ' . $texto);
        $this->assertSame($texto, $r['body']);
    }

    /**
     * La oración que cotiza se saca entera (con su abreviatura) y el catálogo vecino se conserva.
     *
     * @group whatsapp
     * @test
     */
    public function saca_la_cotizacion_con_abreviatura_y_conserva_el_catalogo_vecino()
    {
        $r = CotizacionEnvioGuardHelper::revisar('Tenemos Fernet a $17.000. El envío a Av. Colón 1200 le sale $7.000. Quedo atento.');

        $this->assertStringContainsString('Fernet a $17.000.', $r['body']);
        $this->assertStringContainsString('Quedo atento.', $r['body']);
        $this->assertStringNotContainsString('$7.000', $r['body']);
        $this->assertStringNotContainsString('Colón', $r['body']);
    }
}
