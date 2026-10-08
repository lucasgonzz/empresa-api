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
