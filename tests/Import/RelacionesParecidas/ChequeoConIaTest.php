<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasChequeo;
use App\Models\AiTokenUsage;
use Illuminate\Support\Facades\Http;

/**
 * El chequeo de nombres parecidos de artículos con la IA falseada (misión
 * importacion-relaciones-parecidas, 8/10/2026): qué ve el usuario según lo que contesta la IA.
 *
 * Escenario (sobre 33_relaciones_parecidas_articulos.xlsx):
 *   - proveedores "Acme" y "Ferretería Del Norte"; el Excel trae "ACME" (misma escritura),
 *     "Acme" (idéntico, no se pregunta) y "Ferret. del Norte" (parecido: lo juzga la IA);
 *   - categorías "DEL NORTE" (con la subcategoría "Martillos") y "Cañería"; el Excel trae
 *     "Del norte" y "CAÑERÍA" (misma escritura), "Pinturería"/"Pintureria" (nuevas, entre sí) y la
 *     subcategoría "Martillo" bajo "Del norte" (parecido DENTRO de "DEL NORTE");
 *   - la marca "Bosch"; el Excel trae "Bosh" (parecido) y "BOSCH" (misma escritura).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ChequeoConIaTest extends RelacionesParecidasTestCase
{
    /** @var array */
    protected $sembrado = [];

    protected function sembrar()
    {
        $del_norte = $this->crear_categoria('DEL NORTE');

        $this->sembrado = [
            'acme'      => $this->crear_proveedor('Acme'),
            'ferreteria'=> $this->crear_proveedor('Ferretería Del Norte'),
            'del_norte' => $del_norte,
            'caneria'   => $this->crear_categoria('Cañería'),
            'martillos' => $this->crear_sub('Martillos', $del_norte),
            'bosch'     => $this->crear_marca('Bosch'),
        ];
    }

    /**
     * @param  array|null $columnas
     * @return array relaciones_parecidas
     */
    protected function chequear(array $columnas = null)
    {
        $relativa = $this->copiar_a_storage(self::ARTICULOS);

        return RelacionesParecidasChequeo::para_articulos($this->tenant->id, storage_path('app/' . $relativa), [
            'provider_id'    => null,
            'column_mapping' => $this->column_mapping(is_null($columnas) ? $this->columnas_articulos() : $columnas),
            'hoja'           => 0,
            'header_row'     => null,
        ]);
    }

    /**
     * Las claves que salen sin IA, en cualquier caso.
     *
     * @return string[]
     */
    protected function claves_deterministas()
    {
        return [
            'categoria|CAÑERÍA',
            'categoria|Del norte',
            'categoria|Pintureria',
            'marca|BOSCH',
            'proveedor|ACME',
            /* "Latex" bajo "Pinturería" se compara con "Látex" bajo "Pintureria": las dos categorías se unen. */
            'sub_categoria|Pinturería|Latex',
        ];
    }

    public function test_respuesta_valida_trae_los_parecidos_con_el_candidato_elegido()
    {
        $this->sembrar();

        $this->falsear_ia($this->elegir_por_nombre([
            'Ferret. del Norte' => 'Ferretería Del Norte',
            'Martillo'          => 'Martillos',
            'Bosh'              => 'Bosch',
        ]));

        $relaciones = $this->chequear();

        $this->assertTrue($relaciones['ia_consultada']);
        $this->assertCount(1, $this->llamadas_relaciones, 'UNA llamada por corrida.');
        $this->assertCount(3, $this->llamadas_relaciones[0], 'A la IA van solo los tres valores con candidatos.');

        $esperadas = array_merge($this->claves_deterministas(), [
            'marca|Bosh',
            'proveedor|Ferret. del Norte',
            'sub_categoria|Del norte|Martillo',
        ]);
        sort($esperadas);

        $this->assertSame($esperadas, $this->claves($relaciones));

        $proveedor = $this->item($relaciones, 'proveedor|Ferret. del Norte');
        $this->assertSame('parecido', $proveedor['motivo']);
        $this->assertSame(['accion' => 'usar', 'id' => (int) $this->sembrado['ferreteria']->id, 'nombre' => 'Ferretería Del Norte'], $proveedor['opciones'][$proveedor['sugerida']]);

        $sub = $this->item($relaciones, 'sub_categoria|Del norte|Martillo');
        $this->assertSame('Del norte', $sub['padre']);
        $this->assertSame((int) $this->sembrado['del_norte']->id, $sub['padre_id'], 'La sub se comparó dentro de la categoría que resolvería la fila.');
        $this->assertSame((int) $this->sembrado['martillos']->id, $sub['opciones'][$sub['sugerida']]['id']);

        $misma = $this->item($relaciones, 'categoria|Del norte');
        $this->assertSame('misma_escritura', $misma['motivo']);
        $this->assertSame(2, $misma['filas']);
        $this->assertSame([
            ['accion' => 'usar', 'id' => (int) $this->sembrado['del_norte']->id, 'nombre' => 'DEL NORTE'],
            ['accion' => 'crear'],
        ], $misma['opciones']);
        $this->assertSame(0, $misma['sugerida']);

        $nuevos = $this->item($relaciones, 'categoria|Pintureria');
        $this->assertSame('entre_nuevos', $nuevos['motivo']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Pinturería'], ['accion' => 'crear']], $nuevos['opciones']);

        $this->assertNull($this->item($relaciones, 'proveedor|Acme'), 'Un valor idéntico a un existente no se pregunta nunca.');

        $this->assertSame(1, AiTokenUsage::where('user_id', $this->tenant->id)->where('proceso', 'import_excel_relaciones')->count());
    }

    /**
     * `seguro: false`: la IA cree que puede ser el mismo pero no está segura. Se pregunta igual
     * (antes, "ante la duda null" hacía que no se preguntara y se creara el duplicado en silencio),
     * pero con "crear" marcado: un clic distraído no une dos cosas distintas.
     */
    public function test_seguro_false_es_posible_con_crear_sugerida()
    {
        $this->sembrar();

        $this->falsear_ia($this->elegir_por_nombre([
            'Ferret. del Norte' => 'Ferretería Del Norte',
            'Martillo'          => 'Martillos',
            'Bosh'              => 'Bosch',
        ], false));

        $this->assertDudosos($this->chequear());
    }

    /**
     * Sin `seguro` (o con algo que no es el booleano true) cuenta como false.
     */
    public function test_seguro_ausente_o_no_booleano_es_igual_que_false()
    {
        $this->sembrar();

        $mapa = [
            'Ferret. del Norte' => 'Ferretería Del Norte',
            'Martillo'          => 'Martillos',
            'Bosh'              => 'Bosch',
        ];

        $this->falsear_ia($this->elegir_por_nombre($mapa, self::ABSENT));
        $this->assertDudosos($this->chequear());

        $this->falsear_ia($this->elegir_por_nombre($mapa, 'true'));
        $this->assertDudosos($this->chequear());
    }

    /**
     * @param  array $relaciones
     * @return void
     */
    protected function assertDudosos(array $relaciones)
    {
        $this->assertTrue($relaciones['ia_consultada']);

        $esperadas = array_merge($this->claves_deterministas(), [
            'marca|Bosh',
            'proveedor|Ferret. del Norte',
            'sub_categoria|Del norte|Martillo',
        ]);
        sort($esperadas);

        $this->assertSame($esperadas, $this->claves($relaciones));

        $nombres = [
            'proveedor|Ferret. del Norte'      => 'Ferretería Del Norte',
            'sub_categoria|Del norte|Martillo' => 'Martillos',
            'marca|Bosh'                       => 'Bosch',
        ];

        foreach ($nombres as $clave => $nombre) {
            $item = $this->item($relaciones, $clave);

            $this->assertSame('posible', $item['motivo'], $clave);
            $this->assertSame(['accion' => 'crear'], $item['opciones'][$item['sugerida']], $clave . ': dudoso = "crear" marcado.');
            $this->assertSame('Podría ser «' . $nombre . '» escrito de otra forma; si es el mismo, elegilo', $item['explicacion']);
            $this->assertSame('usar', $item['opciones'][0]['accion'], $clave . ': el candidato se ofrece igual.');
            $this->assertSame($nombre, $item['opciones'][0]['nombre']);
        }
    }

    /**
     * Las subcategorías de "Del norte" y de "DEL NORTE" se comparan juntas (las dos escrituras van
     * a la misma categoría), y lo mismo las de "Pinturería" y "Pintureria" aunque las dos sean
     * nuevas. El ítem conserva el padre como vino en SU fila.
     */
    public function test_los_hijos_de_las_variantes_de_un_padre_se_comparan_juntos()
    {
        $this->falsear_ia($this->elegir_por_nombre(['Martillo' => 'Martillos']));

        $relaciones = $this->chequear([
            'codigo_de_barras'    => 0,
            'codigo_de_proveedor' => 2,
            'nombre'              => 3,
            'categoria'           => 9,
            'sub_categoria'       => 10,
        ]);

        $latex = $this->item($relaciones, 'sub_categoria|Pinturería|Latex');
        $this->assertNotNull($latex, json_encode($this->claves($relaciones)));
        $this->assertSame('entre_nuevos', $latex['motivo']);
        $this->assertSame('Pinturería', $latex['padre']);
        $this->assertNull($latex['padre_id']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Látex'], ['accion' => 'crear']], $latex['opciones']);
        $this->assertSame(0, $latex['sugerida']);

        $martillo = $this->item($relaciones, 'sub_categoria|Del norte|Martillo');
        $this->assertNotNull($martillo, 'Martillo (bajo "Del norte") y Martillos (bajo "DEL NORTE") se juzgaron juntos.');
        $this->assertSame('entre_nuevos', $martillo['motivo']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Martillos'], ['accion' => 'crear']], $martillo['opciones']);

        $this->assertNull($this->item($relaciones, 'sub_categoria|Pintureria|Látex'), 'La canónica no se pregunta.');
    }

    /**
     * Lo mismo para localidad dentro de provincia: "Villa Gdor Galvez" bajo "Santa Fe." se compara
     * con "Villa Gdor. Galvez" bajo "Santa Fe".
     */
    public function test_localidades_bajo_variantes_de_la_misma_provincia_se_comparan_juntas()
    {
        $this->falsear_ia(function () {
            return null;
        });

        $relativa = $this->copiar_a_storage(self::LOCALIDADES);

        $relaciones = RelacionesParecidasChequeo::para_clientes($this->tenant->id, storage_path('app/' . $relativa), [
            'column_mapping' => $this->column_mapping(['nombre' => 0, 'provincia' => 1, 'localidad' => 2]),
            'hoja'           => 0,
            'header_row'     => null,
        ]);

        $this->assertSame(['localidad|Santa Fe.|Villa Gdor Galvez', 'provincia|Santa Fe.'], $this->claves($relaciones));

        $localidad = $this->item($relaciones, 'localidad|Santa Fe.|Villa Gdor Galvez');
        $this->assertSame('entre_nuevos', $localidad['motivo']);
        $this->assertSame('Santa Fe.', $localidad['padre']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Villa Gdor. Galvez'], ['accion' => 'crear']], $localidad['opciones']);

        Http::assertNothingSent();
    }

    public function test_lo_que_la_ia_dice_que_no_coincide_no_se_muestra()
    {
        $this->sembrar();

        $this->falsear_ia(function () {
            return null;
        });

        $relaciones = $this->chequear();

        $this->assertTrue($relaciones['ia_consultada']);

        $esperadas = $this->claves_deterministas();
        sort($esperadas);

        $this->assertSame($esperadas, $this->claves($relaciones));
    }

    public function test_un_500_cae_al_fallback_solo_con_los_de_puntaje_alto_y_crear_marcado()
    {
        $this->sembrar();

        $this->falsear_ia(null, 500);

        $this->assertFallback($this->chequear());
    }

    public function test_un_json_roto_cae_al_fallback()
    {
        $this->sembrar();

        $this->falsear_ia(null, 200, 'Revisé los valores y {"respuestas": [ esto no cierra');

        $this->assertFallback($this->chequear());
    }

    /**
     * "Ferret. del Norte" (0,92) y "Martillo" (0,89) pasan el 0,85; "Bosh" (0,80) no.
     *
     * @param  array $relaciones
     * @return void
     */
    protected function assertFallback(array $relaciones)
    {
        $this->assertFalse($relaciones['ia_consultada']);
        $this->assertCount(1, $this->llamadas_relaciones);

        $esperadas = array_merge($this->claves_deterministas(), [
            'proveedor|Ferret. del Norte',
            'sub_categoria|Del norte|Martillo',
        ]);
        sort($esperadas);

        $this->assertSame($esperadas, $this->claves($relaciones));

        foreach (['proveedor|Ferret. del Norte', 'sub_categoria|Del norte|Martillo'] as $clave) {
            $item = $this->item($relaciones, $clave);

            $this->assertSame('posible', $item['motivo'], $clave);
            $this->assertSame(['accion' => 'crear'], $item['opciones'][$item['sugerida']], $clave . ': con la IA caída se preselecciona lo que pasaría hoy.');
            $this->assertSame('No se pudo consultar a la IA; revisá estos nombres parecidos', $item['explicacion']);
        }

        /* Lo determinista sale igual, con "es el existente" marcado. */
        $misma = $this->item($relaciones, 'categoria|CAÑERÍA');
        $this->assertSame('usar', $misma['opciones'][$misma['sugerida']]['accion']);
    }

    /**
     * Sin candidatos parecidos no hay llamada a la IA: CERO requests (es también lo que mantiene
     * intactos los tests que cuentan requests con Http::fake).
     */
    public function test_sin_candidatos_no_hay_llamada()
    {
        $this->falsear_ia(function () {
            return null;
        });

        $relaciones = $this->chequear([
            'codigo_de_barras'    => 0,
            'codigo_de_proveedor' => 2,
            'nombre'              => 3,
            'categoria'           => 9,
        ]);

        Http::assertNothingSent();

        $this->assertTrue($relaciones['ia_consultada'], 'Sin consulta no hay nada que avisar.');
        $this->assertSame(['categoria|DEL NORTE', 'categoria|Pintureria'], $this->claves($relaciones));

        $item = $this->item($relaciones, 'categoria|DEL NORTE');
        $this->assertSame('entre_nuevos', $item['motivo']);
        $this->assertSame([['accion' => 'unir', 'con' => 'Del norte'], ['accion' => 'crear']], $item['opciones'], '"Del norte" aparece en dos filas: es la canónica.');
    }
}
