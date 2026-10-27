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
        $this->assertEquals(40.64, $this->validador->validar('profundidad', $this->dato(16, 'inch', 'barra de 16"'), $this->paginas('barra de 16"'))['dato']['valor']);
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

    /**
     * Las lecturas posibles de un token siguen siendo dos cuando es ambiguo (el validador las marca).
     *
     * @group dimension-search
     * @test
     */
    public function las_lecturas_de_un_numero_con_separador()
    {
        $this->assertEqualsCanonicalizing([1.2, 1200.0], $this->validador->lecturas_del_numero('1.200'));
        $this->assertEqualsCanonicalizing([1.5, 1500.0], $this->validador->lecturas_del_numero('1,500'));
        $this->assertSame([0.082], $this->validador->lecturas_del_numero('0,082'));
        $this->assertSame([1234.5], $this->validador->lecturas_del_numero('1.234,5'));
        $this->assertSame([1234.5], $this->validador->lecturas_del_numero('1,234.5'));
        $this->assertSame([1234567.0], $this->validador->lecturas_del_numero('1.234.567'));
        $this->assertSame([], $this->validador->lecturas_del_numero('1.2.3'));
    }

    /**
     * B1 (bloqueante del revisor): «Peso: 1.200 g» con el modelo diciendo 1.2 g guardaba 0,0012 kg sin avisar.
     * Reemplaza al test anterior, que daba por bueno cualquier punto de miles.
     *
     * @group dimension-search
     * @test
     */
    public function un_punto_de_miles_que_puede_ser_decimal_se_marca_ambiguo_con_su_frase_y_se_guarda_la_lectura_del_modelo()
    {
        $pagina = $this->paginas('Peso: 1.200 g. Alto: 0,082 kg.');

        // El modelo leyó 1,2 g: se guarda esa lectura (0,0012 kg) pero marcada ambigua.
        $decimal = $this->validador->validar('peso', $this->dato(1.2, 'g', 'Peso: 1.200 g'), $pagina);
        $this->assertTrue($decimal['ok']);
        $this->assertEquals(0.0012, $decimal['dato']['valor']);
        $this->assertTrue($decimal['dato']['ambiguo']);
        $this->assertFalse($decimal['dato']['rango']);
        $this->assertSame('La página escribe «1.200 g»: puede ser 1,2 o 1.200', $decimal['dato']['aviso']);

        // El modelo leyó 1.200 g: se guarda esa (1,2 kg), también marcada.
        $miles = $this->validador->validar('peso', $this->dato(1200, 'g', 'Peso: 1.200 g'), $pagina);
        $this->assertTrue($miles['ok']);
        $this->assertEquals(1.2, $miles['dato']['valor']);
        $this->assertTrue($miles['dato']['ambiguo']);

        // Coma de miles en kg.
        $kg = $this->validador->validar('peso', $this->dato(1.5, 'kg', 'Pesa 1,500 kg'), $this->paginas('Pesa 1,500 kg'));
        $this->assertTrue($kg['dato']['ambiguo']);
        $this->assertSame('La página escribe «1,500 kg»: puede ser 1,5 o 1.500', $kg['dato']['aviso']);

        // «1.000 g»: la lectura decimal es 1.
        $mil = $this->validador->validar('peso', $this->dato(1000, 'g', 'Peso: 1.000 g'), $this->paginas('Peso: 1.000 g'));
        $this->assertSame('La página escribe «1.000 g»: puede ser 1 o 1.000', $mil['dato']['aviso']);
    }

    /**
     * Lo que NO es ambiguo: un decimal con cero entero, con dos decimales o sin separador, y los miles
     * con varios grupos.
     *
     * @group dimension-search
     * @test
     */
    public function lo_que_no_es_ambiguo_no_se_marca()
    {
        foreach ([[0.082, 'kg', 'Peso: 0,082 kg'], [2.5, 'kg', 'Peso: 2,5 kg'], [820, 'g', 'Peso: 820 g'], [1.25, 'kg', 'Peso: 1,25 kg'], [1234567, 'g', 'Peso: 1.234.567 g']] as $caso) { // el último: dos grupos de miles no pueden ser un decimal (1234,567 kg)
            $r = $this->validador->validar('peso', $this->dato($caso[0], $caso[1], $caso[2]), $this->paginas($caso[2]));

            $this->assertTrue($r['ok'], $caso[2]);
            $this->assertFalse($r['dato']['ambiguo'], $caso[2]);
            $this->assertNull($r['dato']['aviso']);
        }
    }

    /**
     * Si la cita trae también la lectura limpia, esa le gana a la ambigua.
     *
     * @group dimension-search
     * @test
     */
    public function entre_dos_lecturas_gana_la_que_no_es_ambigua()
    {
        $r = $this->validador->validar('peso', $this->dato(1.5, 'kg', 'Peso: 1.500 g (1,5 kg)'), $this->paginas('Peso: 1.500 g (1,5 kg)'));

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['dato']['ambiguo']);
        $this->assertEquals(1.5, $r['dato']['valor']);
    }

    /**
     * M2: «Alto: 10-20 cm» validaba el extremo como si fuera la medida.
     *
     * @group dimension-search
     * @test
     */
    public function un_rango_se_marca_y_no_vale_como_una_medida()
    {
        $casos = [
            ['Alto: 10-20 cm', 20, 'La página da un rango: 10-20 cm'],
            ['Alto: 10 a 20 cm', 10, 'La página da un rango: 10 a 20 cm'],
            ['Alto: entre 10 y 20 cm', 20, 'La página da un rango: 10 y 20 cm'],
            ['Alto: 10~20 cm', 10, 'La página da un rango: 10~20 cm'],
            ['Alto: 10 – 20 cm', 20, 'La página da un rango: 10 – 20 cm'],
            ['Alto: 10 cm a 20 cm', 10, 'La página da un rango: 10 cm a 20 cm'],
        ];

        foreach ($casos as $caso) {
            $r = $this->validador->validar('alto', $this->dato($caso[1], 'cm', $caso[0]), $this->paginas($caso[0]));

            $this->assertTrue($r['ok'], $caso[0]);
            $this->assertTrue($r['dato']['rango'], $caso[0]);
            $this->assertSame($caso[2], $r['dato']['aviso'], $caso[0]);
        }
    }

    /**
     * Lo que se parece a un rango pero no lo es.
     *
     * @group dimension-search
     * @test
     */
    public function dos_datos_distintos_en_la_misma_cita_no_son_un_rango()
    {
        $cita = 'Alto 100 mm - Peso 820 g';

        $alto = $this->validador->validar('alto', $this->dato(100, 'mm', $cita), $this->paginas($cita));
        $peso = $this->validador->validar('peso', $this->dato(820, 'g', $cita), $this->paginas($cita));

        $this->assertFalse($alto['dato']['rango']);
        $this->assertFalse($peso['dato']['rango']);

        // Y una medida suelta o una agrupación tampoco.
        $this->assertFalse($this->validador->validar('alto', $this->dato(25, 'cm', 'Alto: 25 cm'), $this->paginas('Alto: 25 cm'))['dato']['rango']);
        $this->assertFalse($this->validador->validar('alto', $this->dato(11, 'mm', 'Medidas 70 x 11 x 100 mm'), $this->paginas('Medidas 70 x 11 x 100 mm'))['dato']['rango']);
    }

    /**
     * Menor 2: «in» pelado no es pulgada; lo son «"», «pulg», «pulgadas», «inch» e «inches».
     *
     * @group dimension-search
     * @test
     */
    public function in_pelado_no_es_una_pulgada()
    {
        $pelado = $this->validador->validar('profundidad', $this->dato(16, 'in', 'Largo 16 in'), $this->paginas('Largo 16 in'));
        $this->assertFalse($pelado['ok']);
        $this->assertSame('unidad_invalida', $pelado['motivo']);

        // Y en la cita tampoco: «Modelo 12 in 1» no es una medida.
        $en_la_cita = $this->validador->validar('profundidad', $this->dato(30.48, 'cm', 'Largo 12 in'), $this->paginas('Largo 12 in'));
        $this->assertFalse($en_la_cita['ok']);

        foreach ([['Largo 16 inch', 'inch'], ['Largo 16 inches', 'inches'], ['Largo 16 pulg', 'pulg'], ['Largo 16"', 'inch']] as $caso) {
            $r = $this->validador->validar('profundidad', $this->dato(16, $caso[1], $caso[0]), $this->paginas($caso[0]));
            $this->assertTrue($r['ok'], $caso[0]);
            $this->assertEquals(40.64, $r['dato']['valor']);
        }
    }

    /**
     * Menor 2: una unidad seguida de «/» es otra magnitud (g/m2, kg/m, m/s).
     *
     * @group dimension-search
     * @test
     */
    public function una_unidad_seguida_de_barra_no_es_una_medida()
    {
        foreach ([['peso', 100, 'g', 'Peso 100 g/m2'], ['peso', 2, 'kg', 'Peso 2 kg/m'], ['profundidad', 5, 'm', 'Largo 5 m/s'], ['peso', 80, 'g', 'Gramaje 80 g/m²']] as $caso) {
            $r = $this->validador->validar($caso[0], $this->dato($caso[1], $caso[2], $caso[3]), $this->paginas($caso[3]));

            $this->assertFalse($r['ok'], $caso[3]);
        }
    }

    /**
     * M1(c): la cita tiene que decir de qué medida habla.
     *
     * @group dimension-search
     * @test
     */
    public function la_cita_trae_una_etiqueta_del_campo_o_es_una_agrupacion()
    {
        $con = [
            ['peso', 0.82, 'kg', 'Peso: 0,82 kg'],
            ['peso', 0.82, 'kg', 'Pesa 0,82 kg'],
            ['peso', 0.82, 'kg', 'Weight 0.82 kg'],
            ['peso', 0.82, 'kg', 'Peso del embalaje 0,82 kg'],
            ['alto', 25, 'cm', 'Altura: 25 cm'],
            ['alto', 25, 'cm', 'Height 25 cm'],
            ['ancho', 25, 'cm', 'Anchura 25 cm'],
            ['ancho', 25, 'cm', 'Width: 25 cm'],
            ['profundidad', 25, 'cm', 'Longitud 25 cm'],
            ['profundidad', 25, 'cm', 'Fondo: 25 cm'],
            ['profundidad', 25, 'cm', 'Depth 25 cm'],
            ['profundidad', 25, 'cm', 'Medidas del envase 25 cm'],
            ['alto', 25, 'cm', 'Tamaño 25 cm'],
            ['alto', 1.1, 'cm', '70 x 11 x 100 mm'],
        ];

        foreach ($con as $caso) {
            $r = $this->validador->validar($caso[0], $this->dato($caso[1], $caso[2], $caso[3]), $this->paginas($caso[3]));

            $this->assertTrue($r['ok'], $caso[3]);
            $this->assertTrue($r['dato']['etiquetada'], $caso[3]);
        }

        $sin = [
            ['alto', 25, 'cm', 'Mide 25 cm'],
            ['peso', 0.82, 'kg', '0,82 kg'],
            ['ancho', 25, 'cm', 'Diámetro 25 cm'],
            // La etiqueta de OTRO campo no vale: «Peso» no dice cuál es el alto.
            ['alto', 25, 'cm', 'Peso 3 kg, 25 cm'],
        ];

        foreach ($sin as $caso) {
            $r = $this->validador->validar($caso[0], $this->dato($caso[1], $caso[2], $caso[3]), $this->paginas($caso[3]));

            $this->assertTrue($r['ok'], $caso[3]);
            $this->assertFalse($r['dato']['etiquetada'], $caso[3]);
        }
    }
}
