<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\CotizacionEnvioGuardHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión truvari-envio-coherente v2, ronda QA3. Complementa (no reemplaza ni debilita) los tests de aceptación y
 * de las rondas anteriores.
 *
 * Hueco: el contexto de envío no cruzaba oraciones ni líneas. «Hacemos envíos. Sale $7.000.» pasaba porque la
 * segunda oración no tiene la palabra de envío, y un rótulo de envío o de total separado de su valor por una
 * línea en blanco («**Costo de envío:**\n\nLa Calera: $7.000») también, porque el rótulo en negrita no terminaba
 * en «:» y el valor traía texto propio.
 *
 * Se prueba el efecto (el importe no llega al cliente) y que los precios de catálogo de la oración o la línea
 * siguiente, con sujeto propio, sigan saliendo byte por byte.
 */
class CotizacionEnvioGuardHelperRechazoQa3Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public function cotizaciones_en_la_oracion_o_linea_siguiente()
    {
        return [
            'oración siguiente sin sujeto' => ['Hacemos envíos. Sale $7.000.', ['$7.000']],
            'salto de línea entre las dos' => ["Hacemos envíos.\nSale \$7.000.", ['$7.000']],
            'línea en blanco entre las dos' => ["Hacemos envíos.\n\nSale \$7.000 a La Calera.", ['$7.000']],
            'envío a domicilio + cuesta' => ['Hacemos envíos a domicilio. Cuesta $7.000.', ['$7.000']],
            'sujeto genérico «el costo»' => ['Hacemos envíos. El costo es $7.000.', ['$7.000']],
            'pronombre + verbo + localidad' => ['Hacemos envíos. Te sale $7.000 a Río Ceballos.', ['$7.000']],
            'catálogo antes, envío después' => ['Tenemos Fernet a $17.000. Hacemos envíos. Sale $7.000.', ['$7.000']],
            'rótulo en negrita con ":" dentro y valor con localidad' => ["**Costo de envío:**\n\nLa Calera: \$7.000", ['$7.000']],
            'rótulo sin ":" y valor con localidad' => ["Costo de envío\n\nLa Calera: \$7.000", ['$7.000']],
            'rótulo en negrita y valor con verbo' => ["**Envío**\n\nSale \$7.000 a La Calera", ['$7.000']],
            'rótulo de envío y valor con verbo' => ["Envío\n\nCuesta \$7.000", ['$7.000']],
            'rótulo del total y valor con verbo' => ["Total del pedido\n\nQueda en \$47.000", ['$47.000']],
            'rótulo del total en negrita y valor con verbo' => ["**Total:**\n\nSon \$47.000 con todo", ['$47.000']],
        ];
    }

    /**
     * @group whatsapp
     * @test
     * @dataProvider cotizaciones_en_la_oracion_o_linea_siguiente
     */
    public function el_contexto_de_envio_cruza_oraciones_y_lineas($texto, array $importes)
    {
        $r = CotizacionEnvioGuardHelper::revisar($texto);

        $this->assertTrue($r['derivo'], 'Tenía que derivar: ' . $texto);
        foreach ($importes as $importe) {
            $this->assertStringNotContainsString($importe, $r['body'], 'Quedó un importe en: ' . $r['body']);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function textos_que_no_se_tocan()
    {
        return [
            'catálogo con sujeto propio' => ['Hacemos envíos. El Fernet sale $17.000.'],
            'catálogo en párrafo aparte' => ["Hacemos envíos.\n\nEl Fernet Branca 750 cc cuesta \$17.000."],
            'cada botella' => ['Hacemos envíos. Cada botella sale $17.000.'],
            'desde en catálogo' => ['Hacemos envíos. Tenemos taladros desde $45.000.'],
            'rótulo de envío sin costo y lista de catálogo' => ["Envíos a todo el país\n\nFernet: \$17.000"],
            'rótulo de envío y catálogo con sujeto' => ["Envío a Río Ceballos\n\nEl Fernet cuesta \$17.000"],
            'sin contexto de envío, oración sin sujeto' => ['Tenemos Fernet. Sale $17.000.'],
        ];
    }

    /**
     * @group whatsapp
     * @test
     * @dataProvider textos_que_no_se_tocan
     */
    public function el_catalogo_vecino_de_un_envio_nombrado_sale_byte_por_byte_igual($texto)
    {
        $r = CotizacionEnvioGuardHelper::revisar($texto);

        $this->assertFalse($r['derivo'], 'No tenía que derivar: ' . $texto);
        $this->assertSame($texto, $r['body']);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function saca_el_importe_del_envio_y_conserva_el_catalogo_vecino()
    {
        $r = CotizacionEnvioGuardHelper::revisar('Tenemos Fernet a $17.000. Hacemos envíos. Sale $7.000. Quedo atento.');

        $this->assertStringContainsString('Fernet a $17.000.', $r['body']);
        $this->assertStringContainsString('Quedo atento.', $r['body']);
        $this->assertStringNotContainsString('$7.000', $r['body']);
    }
}
