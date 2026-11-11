<?php

namespace Tests\Feature\DimensionSearch;

use App\Models\DimensionSearchItem;
use App\Services\DimensionSearch\DimensionPageText;
use App\Services\DimensionSearch\DimensionValueValidator;

/**
 * Las fichas en alemán, italiano e inglés de los sitios de referencia de Masquito (misión
 * medidas-sitios-de-referencia, 11/11/2026): el validador reconoce sus etiquetas («Gewicht», «Höhe», «Altezza»,
 * «Shipping Weight») y el extracto de la página abre ventanas con ellas. Antes solo con las castellanas: una cita
 * correcta en alemán quedaba `cita_sin_etiqueta` y, si la página no traía ninguna palabra en castellano, el texto
 * con los datos ni siquiera llegaba a la IA.
 *
 * Los textos son los de las fichas reales (recortados): OBI, Verdefacile, Carbydoctor.
 */
class Fichas_en_otros_idiomas_Test extends DimensionSearchTestCase
{
    const URL = 'https://tienda.test/ficha';

    /**
     * Valida un campo contra un texto y devuelve el dato (o falla).
     *
     * @param  string $campo
     * @param  float  $valor
     * @param  string $unidad
     * @param  string $cita
     * @param  string $texto
     * @return array
     */
    protected function validado($campo, $valor, $unidad, $cita, $texto)
    {
        $r = (new DimensionValueValidator())->validar($campo, $this->dato($valor, $unidad, $cita, self::URL), [self::URL => $texto]);

        $this->assertTrue($r['ok'], 'No validó «'.$cita.'»: '.$r['motivo']);

        return $r['dato'];
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_validador_reconoce_las_etiquetas_en_aleman_italiano_e_ingles()
    {
        $obi = 'Maße & Gewicht Gewicht 2,70 kg Höhe 9,0 cm Breite 38,0 cm Tiefe 38,0 cm';

        $this->assertTrue($this->validado('peso', 2.7, 'kg', 'Gewicht 2,70 kg', $obi)['etiquetada']);
        $this->assertTrue($this->validado('alto', 9, 'cm', 'Höhe 9,0 cm', $obi)['etiquetada']);
        $this->assertTrue($this->validado('ancho', 38, 'cm', 'Breite 38,0 cm', $obi)['etiquetada']);
        $this->assertTrue($this->validado('profundidad', 38, 'cm', 'Tiefe 38,0 cm', $obi)['etiquetada']);
        // La IA a veces copia sin la diéresis.
        $this->assertTrue($this->validado('alto', 9, 'cm', 'Hohe 9,0 cm', 'Hohe 9,0 cm')['etiquetada']);

        $verde = 'Riferimento 42550194950 Peso: 4,5 Kg Altezza 12 cm Larghezza 3 cm Profondità 40 cm';
        $this->assertTrue($this->validado('peso', 4.5, 'kg', 'Peso: 4,5 Kg', $verde)['etiquetada']);
        $this->assertTrue($this->validado('alto', 12, 'cm', 'Altezza 12 cm', $verde)['etiquetada']);
        $this->assertTrue($this->validado('ancho', 3, 'cm', 'Larghezza 3 cm', $verde)['etiquetada']);
        $this->assertTrue($this->validado('profundidad', 40, 'cm', 'Profondità 40 cm', $verde)['etiquetada']);

        $carby = 'OEM REPLACEMENT: 32 853 63-S Shipping Weight 0.7500kg Shipping Width 0.180m Shipping Height 0.230m Shipping Length 0.130m';
        $this->assertTrue($this->validado('peso', 0.75, 'kg', 'Shipping Weight 0.7500kg', $carby)['etiquetada']);
        $this->assertEquals(18, $this->validado('ancho', 0.18, 'm', 'Shipping Width 0.180m', $carby)['valor']);

        // Una genérica en alemán vale para cualquier medida; una cita pelada sigue sin etiqueta.
        $this->assertTrue($this->validado('alto', 9, 'cm', 'Abmessungen: 9 cm', 'Abmessungen: 9 cm')['etiquetada']);
        $this->assertFalse($this->validado('alto', 9, 'cm', '9,0 cm', $obi)['etiquetada']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_extracto_abre_ventanas_con_las_palabras_y_los_encabezados_de_otros_idiomas()
    {
        $relleno = str_repeat('Lorem ipsum dolor sit amet. ', 400);

        foreach ([
            'Maße & Gewicht Gewicht 2,70 kg Höhe 9,0 cm',
            'Technische Daten Netto 2,70 kg',
            'Dati tecnici Peso netto 4,5 Kg',
            'Item specifics Shipping Weight 0.7500kg',
            'Scheda tecnica Altezza 12 cm',
        ] as $ficha) {
            $html = '<html><body><p>'.$relleno.'</p><p>'.$ficha.'</p><p>'.$relleno.'</p></body></html>';

            $this->assertStringContainsString($ficha, DimensionPageText::recortar($html), 'El extracto no trajo «'.$ficha.'».');
        }
    }

    /**
     * De punta a punta: la ficha de OBI en alemán, sin JSON-LD, con el EAN en la página, se asigna sola.
     *
     * @group dimension-search
     * @test
     */
    public function una_ficha_en_aleman_con_el_codigo_y_los_cuatro_datos_se_asigna_sola()
    {
        $gtin     = '4078500001694';
        $url      = 'https://www.obi.de/gartenschlauch/p/1234567';
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', $gtin);
        $relleno  = str_repeat('Lorem ipsum dolor sit amet. ', 200);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'GARDENA Classic Schlauch')]],
            [$url => $this->pagina('GARDENA Classic Schlauch', $relleno.' EAN '.$gtin.' '.$relleno.' Maße &amp; Gewicht Gewicht 2,70 kg Höhe 9,0 cm Breite 38,0 cm Tiefe 38,0 cm '.$relleno)],
            $this->ficha('exacto', [
                'peso'  => $this->dato(2.7, 'kg', 'Gewicht 2,70 kg', $url),
                'alto'  => $this->dato(9, 'cm', 'Höhe 9,0 cm', $url),
                'ancho' => $this->dato(38, 'cm', 'Breite 38,0 cm', $url),
                'largo' => $this->dato(38, 'cm', 'Tiefe 38,0 cm', $url),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);
        $this->assertSame('codigo_exacto', $item->motivo);
        $this->assertEquals(2.7, (float) $articulo->fresh()->peso);
        $this->assertEquals(38, (float) $articulo->fresh()->profundidad);
    }
}
