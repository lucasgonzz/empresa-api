<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\CotizacionEnvioGuardHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión truvari-envio-coherente v2 (CA3, CA4 y CA10): el filtro que saca de la respuesta del agente toda
 * cotización de envío o total. Es una función pura: no lee la base ni depende de ninguna marca del modelo.
 *
 * Dos lados que se prueban a propósito: lo que TIENE que derivar (un envío o un total que nadie confirmó) y lo
 * que NO se puede tocar (precios de catálogo, subtotales de producto, texto sin cotización: ese sale byte por
 * byte igual).
 */
class CotizacionEnvioGuardHelperTest extends TestCase
{
    /** Frase fija que reemplaza lo sacado. */
    const FRASE = 'El costo de envío y el total final los confirma el equipo en breve.';

    /**
     * Texto literal del mensaje 104 de `tr-t2.json` (diagnóstico agentes-config-reprueba).
     */
    const T104 = "Hola, claro. Tenemos Fernet Branca 750 ml a \$17.000, así que las 2 botellas serían \$34.000. El envío a La Calera tiene un costo de \$7.000, más \$6.000 de peaje, lo que da un total de \$47.000. Como supera los \$80.000, el envío sería sin cargo, pero el peaje de \$6.000 se suma igual: total \$40.000.\n\nSi le parece bien, indíqueme su nombre y coordinamos el pedido.";

    /**
     * @group whatsapp
     * @test
     */
    public function t104_conserva_el_precio_y_el_cierre_y_saca_todo_el_envio_y_el_total()
    {
        $r = CotizacionEnvioGuardHelper::revisar(self::T104);

        $this->assertTrue($r['derivo']);
        $this->assertSame(2, $r['frases']);
        $this->assertStringContainsString('$17.000', $r['body']);
        $this->assertStringContainsString('$34.000', $r['body']);
        $this->assertStringContainsString('coordinamos el pedido', $r['body']);
        foreach (['$7.000', '$6.000', '$47.000', '$40.000', '80.000', 'supera', 'peaje'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $r['body'], 'No tenía que quedar: ' . $prohibido);
        }
        $this->assertStringEndsWith("\n\n" . self::FRASE, $r['body']);
    }

    /**
     * Variantes de importe y de redacción que SÍ son una cotización.
     *
     * @group whatsapp
     * @test
     */
    public function deriva_las_variantes_de_cotizacion()
    {
        $casos = [
            'total con pesos'            => 'El total es $47.000.',
            'total sin signo'            => 'Total: 47.000',
            'total en mayúsculas'        => 'TOTAL $47.000,00',
            'envío con importe'          => 'El envío es de $7.000.',
            'envio sin tilde y espacio'  => 'El envio $ 7.000.',
            'costo de envío sin signo'   => 'El costo de envío 7.000.',
            'importe sin separador'      => 'El envío sale $7000.',
            'importe sin signo ni punto' => 'El envío sale 7000.',
            'importe en pesos'           => 'El envío son 7.000 pesos.',
            'importe en lucas'           => 'El envío son 7 lucas.',
            'importe con mil'            => 'El envío sale 7 mil.',
            'importe en palabras'        => 'El envío sale siete mil pesos.',
            'peaje'                      => 'Se suman $6.000 de peaje.',
            'flete'                      => 'El flete son $5.500.',
            'recargo'                    => 'Hay un recargo de $2.000.',
            'envío sin cargo'            => 'El envío es sin cargo.',
            'envío gratis'               => 'Tenés envío gratis.',
            'envío bonificado'           => 'El envío queda bonificado.',
            'umbral supera'              => 'Como supera los $80.000 ya no pagás nada.',
            'umbral más de con compra'   => 'En compras de más de $80.000 no cobramos nada extra.',
            'política no confirmada'     => 'Hacemos envíos gratis desde $80.000.',
            'total en total'             => 'Son $47.000 en total.',
        ];

        foreach ($casos as $nombre => $texto) {
            $r = CotizacionEnvioGuardHelper::revisar($texto);

            $this->assertTrue($r['derivo'], 'Tenía que derivar: ' . $nombre);
            $this->assertSame(self::FRASE, $r['body'], 'Solo queda la frase fija: ' . $nombre);
        }
    }

    /**
     * Lo que NO se puede tocar: precios de catálogo, subtotales de producto y texto sin cotización. Cuando no se
     * deriva, el texto sale byte por byte igual.
     *
     * @group whatsapp
     * @test
     */
    public function no_toca_precios_de_catalogo_ni_texto_sin_cotizacion()
    {
        $casos = [
            'precio de catálogo'              => 'Fernet Branca 750 ml a $17.000.',
            'subtotal de producto'            => 'Las 2 botellas serían $34.000.',
            'precio desde (catálogo)'         => 'Tenemos taladros desde $45.000.',
            'precio de más de (catálogo)'     => 'Ese modelo sale más de $45.000 por la batería.',
            'zonas sin importe ni gratuidad'  => 'Hacemos envíos a Córdoba capital y La Calera.',
            'ofrecer cotizar'                 => '¿Le cotizo el envío?',
            'forma de pago'                   => 'Abona al recibir, en efectivo o transferencia.',
            'presupuesto sin cargo'           => 'El presupuesto es sin cargo.',
            'lista de precios'                => "Tenemos:\n- Martillo \$12.500\n- Destornillador \$3.200\n- Pinza \$8.900",
            'medidas y cantidades'            => 'Viene en cajas de 1000 unidades de 2,5 mm.',
            'horario'                         => 'Atendemos de lunes a viernes de 9 a 18.',
            'texto vacío'                     => '',
            'saludo con espacios raros'       => "  Hola!  \n\n  ¿En qué te ayudo?  ",
        ];

        foreach ($casos as $nombre => $texto) {
            $r = CotizacionEnvioGuardHelper::revisar($texto);

            $this->assertFalse($r['derivo'], 'No tenía que derivar: ' . $nombre);
            $this->assertSame(0, $r['frases'], $nombre);
            $this->assertSame($texto, $r['body'], 'Tenía que salir byte por byte igual: ' . $nombre);
        }
    }

    /**
     * CA4: si la respuesta entera es cotización, el cuerpo es la frase fija y nunca queda vacío.
     *
     * @group whatsapp
     * @test
     */
    public function una_respuesta_que_es_solo_cotizacion_queda_con_la_frase_fija()
    {
        $r = CotizacionEnvioGuardHelper::revisar('El envío es de $7.000. Total: $41.000.');

        $this->assertTrue($r['derivo']);
        $this->assertSame(2, $r['frases']);
        $this->assertSame(self::FRASE, $r['body']);
    }

    /**
     * Cada oración se juzga sola: una cotización en el medio no se lleva las vecinas, y los cortes de línea y de
     * párrafo se conservan sin dejar huecos.
     *
     * @group whatsapp
     * @test
     */
    public function saca_solo_las_oraciones_de_cotizacion_y_deja_el_resto_prolijo()
    {
        $r = CotizacionEnvioGuardHelper::revisar("Tenemos el taladro a \$50.000. El envío cuesta \$7.000. ¿Te lo reservo?\nAtendemos hasta las 18.");

        $this->assertSame("Tenemos el taladro a \$50.000. ¿Te lo reservo?\nAtendemos hasta las 18.\n\n" . self::FRASE, $r['body']);
        $this->assertSame(1, $r['frases']);

        $r = CotizacionEnvioGuardHelper::revisar("Hola!\nEl total es \$10.000.\nGracias.");

        $this->assertSame("Hola!\nGracias.\n\n" . self::FRASE, $r['body']);
    }

    /**
     * Aplicar el filtro dos veces no cambia nada la segunda: la frase fija no es una cotización.
     *
     * @group whatsapp
     * @test
     */
    public function es_idempotente()
    {
        $una = CotizacionEnvioGuardHelper::revisar(self::T104);
        $dos = CotizacionEnvioGuardHelper::revisar($una['body']);

        $this->assertFalse($dos['derivo']);
        $this->assertSame($una['body'], $dos['body']);
    }

    /**
     * CA10: el helper no lleva cifras ni nombres de negocio.
     *
     * @group whatsapp
     * @test
     */
    public function el_helper_no_trae_cifras_ni_politicas_de_ningun_negocio()
    {
        $fuente = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/Helpers/CotizacionEnvioGuardHelper.php');

        $this->assertNotSame('', $fuente, 'El helper no existe.');
        $this->assertSame(0, preg_match('/80000|80\.000|7000|7\.000|6000|6\.000|Truvari|Calera/', $fuente));
    }
}
