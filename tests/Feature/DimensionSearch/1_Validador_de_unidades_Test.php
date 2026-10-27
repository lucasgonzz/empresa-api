<?php

namespace Tests\Feature\DimensionSearch;

use App\Services\DimensionSearch\DimensionValueValidator;
use PHPUnit\Framework\TestCase;

/**
 * El validador de valores de la búsqueda masiva de peso y medidas (misión medidas-masivas-catalogo,
 * 27/10/2026): parsea la cita por su cuenta y convierte a kg / cm, sin confiar en la conversión del modelo.
 *
 * Los dos casos reales salen de la prueba con 10 artículos de Masquito (27/10/2026): «Ancho: 5,5 cm» y
 * «Largo total: 50.5 cm» de venrol.com.ar; y los de mm y g que Lucas pidió que sirvan: «70 mm» → 7 cm y
 * «820 g» → 0,82 kg.
 *
 * Puro texto y números: no toca la base ni la red, por eso extiende el TestCase de PHPUnit pelado.
 */
class Validador_de_unidades_Test extends TestCase
{
    /** La página de prueba: el texto que se le habría pasado a la IA. */
    const URL = 'https://tienda.test/producto';

    /** @var \App\Services\DimensionSearch\DimensionValueValidator */
    protected $validador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validador = new DimensionValueValidator();
    }

    /**
     * @param  string $texto
     * @return array  url => texto
     */
    protected function paginas($texto)
    {
        return [self::URL => $texto];
    }

    /**
     * El dato tal como lo devuelve la IA.
     *
     * @param  mixed  $valor
     * @param  string $unidad
     * @param  string $cita
     * @param  string $alcance
     * @return array
     */
    protected function dato($valor, $unidad, $cita, $alcance = 'producto')
    {
        return ['value' => $valor, 'unit' => $unidad, 'evidence' => $cita, 'source_url' => self::URL, 'scope' => $alcance];
    }

    /**
     * @group dimension-search
     * @test
     */
    public function setenta_milimetros_se_guardan_como_siete_centimetros_diga_lo_que_diga_el_modelo()
    {
        $texto = 'Cuchilla redonda. Ancho: 70 mm. Largo total: 50.5 cm.';

        // El modelo convirtió él: 7 cm.
        $a = $this->validador->validar('ancho', $this->dato(7, 'cm', 'Ancho: 70 mm'), $this->paginas($texto));
        // El modelo no convirtió: 70 mm.
        $b = $this->validador->validar('ancho', $this->dato(70, 'mm', 'Ancho: 70 mm'), $this->paginas($texto));

        foreach ([$a, $b] as $r) {
            $this->assertTrue($r['ok'], 'Debería valer: '.json_encode($r));
            $this->assertEquals(7.0, $r['dato']['valor']);
            $this->assertSame('cm', $r['dato']['unidad']);
            $this->assertTrue($r['dato']['convertido']);
            $this->assertEquals(70, $r['dato']['valor_original']);
            $this->assertSame('mm', $r['dato']['unidad_original']);
            $this->assertSame('Ancho: 70 mm', $r['dato']['cita']);
            $this->assertSame(self::URL, $r['dato']['url']);
        }
    }

    /**
     * @group dimension-search
     * @test
     */
    public function ochocientos_veinte_gramos_se_guardan_como_cero_coma_ochenta_y_dos_kilos()
    {
        $texto = 'Motosierra. Peso: 820 g (sin cadena).';

        foreach ([[0.82, 'kg'], [820, 'g'], ['0,82', 'kg'], [820, 'gramos'], [820, 'Gr.']] as $caso) {
            $r = $this->validador->validar('peso', $this->dato($caso[0], $caso[1], 'Peso: 820 g'), $this->paginas($texto));

            $this->assertTrue($r['ok'], 'Debería valer con '.json_encode($caso).': '.json_encode($r));
            $this->assertEquals(0.82, $r['dato']['valor']);
            $this->assertSame('kg', $r['dato']['unidad']);
            $this->assertTrue($r['dato']['convertido']);
            $this->assertEquals(820, $r['dato']['valor_original']);
            $this->assertSame('g', $r['dato']['unidad_original']);
        }
    }

    /**
     * @group dimension-search
     * @test
     */
    public function un_valor_que_ya_viene_en_la_unidad_de_la_base_no_se_marca_convertido()
    {
        $r = $this->validador->validar('profundidad', $this->dato(50.5, 'cm', 'Largo total: 50.5 cm'), $this->paginas('Largo total: 50.5 cm'));

        $this->assertTrue($r['ok']);
        $this->assertEquals(50.5, $r['dato']['valor']);
        $this->assertFalse($r['dato']['convertido']);
        $this->assertSame('cm', $r['dato']['unidad_original']);

        // «0,0109 kg» (el peso de un repuesto chico) también vale, con coma decimal.
        $p = $this->validador->validar('peso', $this->dato(0.0109, 'kg', 'Peso: 0,0109 kg'), $this->paginas('Peso: 0,0109 kg'));

        $this->assertTrue($p['ok']);
        $this->assertEquals(0.0109, $p['dato']['valor']);
        $this->assertFalse($p['dato']['convertido']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_agrupacion_de_medidas_con_una_sola_unidad_al_final_vale_para_cada_numero()
    {
        $texto = 'Rodamiento 6202. Medidas: 70 x 11 x 100 mm. Fin.';
        $cita  = '70 x 11 x 100 mm';

        // El alto es 11 mm → 1,1 cm; el ancho 70 mm → 7 cm; el largo 100 mm → 10 cm.
        $alto = $this->validador->validar('alto', $this->dato(1.1, 'cm', $cita), $this->paginas($texto));
        $ancho = $this->validador->validar('ancho', $this->dato(70, 'mm', $cita), $this->paginas($texto));
        $largo = $this->validador->validar('profundidad', $this->dato(10, 'cm', $cita), $this->paginas($texto));

        $this->assertTrue($alto['ok']);
        $this->assertEquals(1.1, $alto['dato']['valor']);
        $this->assertTrue($ancho['ok']);
        $this->assertEquals(7.0, $ancho['dato']['valor']);
        $this->assertTrue($largo['ok']);
        $this->assertEquals(10.0, $largo['dato']['valor']);

        // Con la × y con «por».
        $this->assertTrue($this->validador->validar('alto', $this->dato(11, 'mm', '70×11×100 mm'), $this->paginas('70×11×100 mm'))['ok']);
        $this->assertTrue($this->validador->validar('alto', $this->dato(11, 'cm', '20 por 11 por 30 cm'), $this->paginas('20 por 11 por 30 cm'))['ok']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function acepta_metros_pulgadas_libras_onzas_y_toneladas()
    {
        $this->assertEquals(120.0, $this->validador->validar('profundidad', $this->dato(1.2, 'm', 'Largo 1,2 m'), $this->paginas('Largo 1,2 m'))['dato']['valor']);
        $this->assertEquals(40.64, $this->validador->validar('profundidad', $this->dato(16, 'in', 'barra de 16"'), $this->paginas('barra de 16"'))['dato']['valor']);
        $this->assertEquals(40.64, $this->validador->validar('profundidad', $this->dato(16, 'pulgadas', 'barra de 16 pulgadas'), $this->paginas('barra de 16 pulgadas'))['dato']['valor']);
        $this->assertEquals(0.4536, $this->validador->validar('peso', $this->dato(1, 'lb', 'Peso: 1 lb'), $this->paginas('Peso: 1 lb'))['dato']['valor']);
        $this->assertEquals(0.2835, $this->validador->validar('peso', $this->dato(10, 'oz', 'Peso: 10 oz'), $this->paginas('Peso: 10 oz'))['dato']['valor']);
        $this->assertEquals(1000.0, $this->validador->validar('peso', $this->dato(1, 't', 'Peso: 1 t'), $this->paginas('Peso: 1 t'))['dato']['valor']);
        // Con tildes en la cita.
        $this->assertEquals(5.5, $this->validador->validar('ancho', $this->dato(5.5, 'cm', 'Ancho: 5,5 centímetros'), $this->paginas('Ancho: 5,5 centímetros'))['dato']['valor']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function un_numero_con_punto_de_miles_se_lee_de_las_dos_formas_y_vale_la_que_coincide()
    {
        $this->assertEqualsCanonicalizing([1.5, 1500.0], $this->validador->lecturas_del_numero('1.500'));
        $this->assertSame([0.082], $this->validador->lecturas_del_numero('0,082'));
        $this->assertSame([1234.5], $this->validador->lecturas_del_numero('1.234,5'));
        $this->assertSame([1234.5], $this->validador->lecturas_del_numero('1,234.5'));
        $this->assertSame([1234567.0], $this->validador->lecturas_del_numero('1.234.567'));
        $this->assertSame([], $this->validador->lecturas_del_numero('1.2.3'));

        // «Pesa 1.500 g» es 1,5 kg.
        $r = $this->validador->validar('peso', $this->dato(1.5, 'kg', 'Pesa 1.500 g'), $this->paginas('Pesa 1.500 g'));
        $this->assertTrue($r['ok']);
        $this->assertEquals(1.5, $r['dato']['valor']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function rechaza_al_modelo_que_dice_setenta_centimetros_cuando_la_cita_dice_setenta_milimetros()
    {
        $r = $this->validador->validar('ancho', $this->dato(70, 'cm', 'Ancho: 70 mm'), $this->paginas('Ancho: 70 mm'));

        $this->assertFalse($r['ok']);
        $this->assertSame('cita_no_coincide_con_el_valor', $r['motivo']);
        $this->assertNull($r['dato']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function rechaza_una_cita_que_no_esta_en_la_pagina_o_una_fuente_que_no_se_leyo()
    {
        $texto = 'Peso: 820 g';

        $inventada = $this->validador->validar('peso', $this->dato(0.9, 'kg', 'Peso neto: 900 g'), $this->paginas($texto));
        $this->assertFalse($inventada['ok']);
        $this->assertSame('cita_no_esta_en_la_pagina', $inventada['motivo']);

        $dato = $this->dato(0.82, 'kg', 'Peso: 820 g');
        $dato['source_url'] = 'https://otra.test/pagina';
        $ajena = $this->validador->validar('peso', $dato, $this->paginas($texto));
        $this->assertFalse($ajena['ok']);
        $this->assertSame('fuente_desconocida', $ajena['motivo']);

        $sin_cita = $this->validador->validar('peso', $this->dato(0.82, 'kg', ''), $this->paginas($texto));
        $this->assertFalse($sin_cita['ok']);
        $this->assertSame('sin_cita', $sin_cita['motivo']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function rechaza_un_numero_que_no_aparece_en_la_cita()
    {
        // La cita está en la página pero el número del modelo no es ninguno de los de la cita.
        $r = $this->validador->validar('peso', $this->dato(2, 'kg', 'Peso: 820 g'), $this->paginas('Peso: 820 g'));

        $this->assertFalse($r['ok']);
        $this->assertSame('cita_no_coincide_con_el_valor', $r['motivo']);

        // Una cita sin ningún par número + unidad.
        $sin_numero = $this->validador->validar('peso', $this->dato(1, 'kg', 'Muy liviano'), $this->paginas('Muy liviano'));
        $this->assertFalse($sin_numero['ok']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function rechaza_unidades_invalidas_o_de_otra_magnitud()
    {
        $texto = 'Peso: 820 g. Ancho: 70 mm.';

        $inventada = $this->validador->validar('peso', $this->dato(820, 'quintales', 'Peso: 820 g'), $this->paginas($texto));
        $this->assertSame('unidad_invalida', $inventada['motivo']);

        // Un peso en cm, o una medida en kg.
        $peso_en_cm = $this->validador->validar('peso', $this->dato(7, 'cm', 'Ancho: 70 mm'), $this->paginas($texto));
        $this->assertSame('unidad_invalida', $peso_en_cm['motivo']);

        $medida_en_kg = $this->validador->validar('ancho', $this->dato(0.82, 'kg', 'Peso: 820 g'), $this->paginas($texto));
        $this->assertSame('unidad_invalida', $medida_en_kg['motivo']);

        // Sin unidad, y valor no numérico o negativo.
        $this->assertSame('unidad_invalida', $this->validador->validar('peso', $this->dato(820, null, 'Peso: 820 g'), $this->paginas($texto))['motivo']);
        $this->assertSame('valor_invalido', $this->validador->validar('peso', $this->dato('mucho', 'g', 'Peso: 820 g'), $this->paginas($texto))['motivo']);
        $this->assertSame('valor_invalido', $this->validador->validar('peso', $this->dato(-820, 'g', 'Peso: 820 g'), $this->paginas($texto))['motivo']);
        $this->assertSame('valor_invalido', $this->validador->validar('peso', $this->dato(0, 'g', 'Peso: 0 g'), $this->paginas('Peso: 0 g'))['motivo']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function rechaza_lo_que_esta_fuera_de_los_topes_de_cordura()
    {
        // 3 toneladas y 25 metros: valores que pueden salir de una cita bien parseada pero no son de un artículo.
        $peso = $this->validador->validar('peso', $this->dato(3000, 'kg', 'Peso: 3000 kg'), $this->paginas('Peso: 3000 kg'));
        $this->assertSame('fuera_de_rango', $peso['motivo']);

        $largo = $this->validador->validar('profundidad', $this->dato(25, 'm', 'Largo: 25 m'), $this->paginas('Largo: 25 m'));
        $this->assertSame('fuera_de_rango', $largo['motivo']);

        // En el límite sí: 2000 kg y 10 m (1000 cm).
        $this->assertTrue($this->validador->validar('peso', $this->dato(2000, 'kg', 'Peso: 2000 kg'), $this->paginas('Peso: 2000 kg'))['ok']);
        $this->assertTrue($this->validador->validar('profundidad', $this->dato(10, 'm', 'Largo: 10 m'), $this->paginas('Largo: 10 m'))['ok']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function la_comparacion_con_la_pagina_ignora_mayusculas_y_espacios_y_acepta_citas_unidas_con_barra()
    {
        $texto = "PESO:\n   820   G  \n y además   ANCHO: 70 MM";

        $r = $this->validador->validar('peso', $this->dato(0.82, 'kg', 'peso: 820 g'), $this->paginas($texto));
        $this->assertTrue($r['ok'], json_encode($r));

        // Dos renglones que el modelo unió con « / »: cada parte está en la página.
        $unida = $this->validador->validar('ancho', $this->dato(7, 'cm', 'peso: 820 g / ancho: 70 mm'), $this->paginas($texto));
        $this->assertTrue($unida['ok'], json_encode($unida));

        // Si una de las partes no está, no vale.
        $mala = $this->validador->validar('ancho', $this->dato(7, 'cm', 'peso: 999 g / ancho: 70 mm'), $this->paginas($texto));
        $this->assertFalse($mala['ok']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_medida_en_metros_cuadrados_o_centimetros_cubicos_no_se_confunde_con_un_largo()
    {
        // «m2» y «cm3» no son largos: el número no tiene que leerse como metros ni centímetros.
        $r = $this->validador->validar('profundidad', $this->dato(3, 'm', 'Cubre 3 m2'), $this->paginas('Cubre 3 m2'));
        $this->assertFalse($r['ok']);

        $c = $this->validador->validar('ancho', $this->dato(50, 'cm', 'Cilindrada 50 cm3'), $this->paginas('Cilindrada 50 cm3'));
        $this->assertFalse($c['ok']);
    }

    /**
     * El alcance que no es uno de los dos conocidos se guarda como «producto»; el campo sin dato no es un error.
     *
     * @group dimension-search
     * @test
     */
    public function el_alcance_y_el_dato_vacio()
    {
        $envio = $this->validador->validar('peso', $this->dato(0.82, 'kg', 'Peso bruto: 820 g', 'envio'), $this->paginas('Peso bruto: 820 g'));
        $this->assertSame('envio', $envio['dato']['alcance']);

        $raro = $this->validador->validar('peso', $this->dato(0.82, 'kg', 'Peso: 820 g', 'cualquiera'), $this->paginas('Peso: 820 g'));
        $this->assertSame('producto', $raro['dato']['alcance']);

        $nulo = $this->validador->validar('peso', null, $this->paginas('Peso: 820 g'));
        $this->assertFalse($nulo['ok']);
        $this->assertSame('no_informado', $nulo['motivo']);
    }
}
