<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportConflict;
use App\Models\Provider;

/**
 * En la importacion de Excel gana la columna de precio que el articulo YA tenia (mision 44).
 *
 * Regla de Lucas (12/8/2026): "que en la importacion de Excel gane la columna que ya estaba
 * antes. Si ya tenia indicado un margen de ganancia el articulo en el sistema y en el Excel
 * viene seteado el precio manual, se saltea el precio manual. Y viceversa."
 *
 * Antes de esta mision la importacion no ignoraba nada: pisaba. Si el Excel traia margen
 * sobre un articulo con precio manual, se escribia el margen y despues setFinalPrice() ponia
 * price = null; el precio manual desaparecia sin ningun registro y sin ningun aviso. Al reves
 * pasaba lo mismo: el precio se escribia y se revertia en la misma corrida.
 *
 * El escenario son seis articulos propios (P1..P6, bar_codes 7790101 a 7790106) que crea este
 * test ademas del que siembra ImportTestSeeder, cada uno en un estado de precio distinto. El
 * fixture 11_precio_vs_margen.xlsx es el unico con columna margen_de_ganancia, asi que este
 * test pasa su propio mapa de columnas.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class PrecioManualVsMargenTest extends ImportTestCase
{
    const ARCHIVO = '11_precio_vs_margen.xlsx';

    /** @var array ['P1' => Article, ... 'P6' => Article] */
    protected $articulos = [];

    /** @var \App\Models\Provider Proveedor con margen de ganancia cargado. */
    protected $provider_con_margen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider_con_margen = new Provider();
        $this->provider_con_margen->name            = 'Proveedor con margen Test';
        $this->provider_con_margen->user_id         = $this->tenant->id;
        $this->provider_con_margen->status          = 'active';
        $this->provider_con_margen->percentage_gain = 15;
        $this->provider_con_margen->save();

        /* P1: se maneja por su propio margen. El Excel le trae un precio manual. */
        $this->articulos['P1'] = $this->crear_articulo([
            'name'            => 'Art margen propio',
            'bar_code'        => '7790101',
            'cost'            => 100,
            'percentage_gain' => 25,
            'price'           => null,
            'provider_id'     => $this->providers['A']->id,
        ]);

        /* P2: se maneja por precio manual. El Excel le trae un margen. */
        $this->articulos['P2'] = $this->crear_articulo([
            'name'            => 'Art precio manual',
            'bar_code'        => '7790102',
            'cost'            => null,
            'percentage_gain' => null,
            'price'           => 500,
            'provider_id'     => $this->providers['A']->id,
        ]);

        /* P3: margen del proveedor aplicable (proveedor con margen, flag prendido y costo). */
        $this->articulos['P3'] = $this->crear_articulo([
            'name'                           => 'Art margen proveedor',
            'bar_code'                       => '7790103',
            'cost'                           => 100,
            'percentage_gain'                => null,
            'price'                          => null,
            'provider_id'                    => $this->provider_con_margen->id,
            'apply_provider_percentage_gain' => 1,
        ]);

        /* P4: el mismo caso que P3 pero SIN costo, asi que el margen del proveedor no aplica. */
        $this->articulos['P4'] = $this->crear_articulo([
            'name'                           => 'Art proveedor sin costo',
            'bar_code'                       => '7790104',
            'cost'                           => null,
            'percentage_gain'                => null,
            'price'                          => null,
            'provider_id'                    => $this->provider_con_margen->id,
            'apply_provider_percentage_gain' => 1,
        ]);

        /* P5: no tiene ninguno de los dos. El Excel le trae los dos a la vez. */
        $this->articulos['P5'] = $this->crear_articulo([
            'name'            => 'Art sin criterio previo',
            'bar_code'        => '7790105',
            'cost'            => 100,
            'percentage_gain' => null,
            'price'           => null,
            'provider_id'     => $this->providers['A']->id,
        ]);

        /*
         * P6: tiene margen propio 20 y el Excel le trae un margen en CERO. El cero tiene
         * que quedar descartado: escribirlo es exactamente producir el estado sucio que
         * esta mision elimina (margen 0, que traba la ficha del articulo).
         *
         * El margen previo NO puede ser null: con null en base, get_modified_fields() ya
         * descarta el cero por su cuenta (null == 0.0 en PHP), y el test pasaria aunque el
         * descarte de esta mision no existiera -- o sea, no mediria nada.
         */
        $this->articulos['P6'] = $this->crear_articulo([
            'name'            => 'Art margen cero del excel',
            'bar_code'        => '7790106',
            'cost'            => 100,
            'percentage_gain' => 20,
            'price'           => null,
            'provider_id'     => $this->providers['A']->id,
        ]);

        /*
         * P7: no tiene ninguno de los dos, y el Excel lo toca DOS VECES -- una fila con
         * margen y otra con precio. La segunda fila relee el articulo de la base, donde
         * todavia no esta el margen que encolo la primera.
         */
        $this->articulos['P7'] = $this->crear_articulo([
            'name'            => 'Art dos filas mismo art',
            'bar_code'        => '7790107',
            'cost'            => 100,
            'percentage_gain' => null,
            'price'           => null,
            'provider_id'     => $this->providers['A']->id,
        ]);
    }

    /**
     * Mapa de columnas del fixture 11, que agrega margen_de_ganancia (posicion 7) a las ocho
     * de siempre. Pisa el mapa por defecto de ImportTestCase::columnas().
     *
     * @return array
     */
    protected function columnas_del_fixture()
    {
        return [
            'prop_codigo_de_barras'    => 1,
            'prop_sku'                 => 2,
            'prop_codigo_de_proveedor' => 3,
            'prop_nombre'              => 4,
            'prop_costo'               => 5,
            'prop_precio'              => 6,
            'prop_margen_de_ganancia'  => 7,
            'prop_stock_actual'        => 8,
            'prop_iva'                 => 9,
        ];
    }

    /**
     * Importa el fixture de esta suite con su mapa de columnas.
     *
     * @return \App\Models\ImportHistory
     */
    protected function importar_fixture()
    {
        return $this->importar(self::ARCHIVO, $this->columnas_del_fixture());
    }

    /**
     * @param  array $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props)
    {
        $article = new Article();

        $article->user_id = $this->tenant->id;
        $article->iva_id  = 2;
        $article->status  = 'active';
        $article->stock   = 0;

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        ImportTestSeeder::derivar_precio($article, $this->tenant->id);

        return Article::find($article->id);
    }

    /**
     * @param  string $clave  P1..P6
     * @return \App\Models\Article
     */
    protected function recargar_articulo($clave)
    {
        return Article::find($this->articulos[$clave]->id);
    }

    /**
     * Conflictos de tipo columna_de_precio_ignorada de una importacion, por campo.
     *
     * @param  \App\Models\ImportHistory $import
     * @param  string|null               $campo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function columnas_ignoradas($import, $campo = null)
    {
        $query = ImportConflict::where('import_history_id', $import->id)
                                ->where('tipo', 'columna_de_precio_ignorada');

        if (!is_null($campo)) {
            $query->where('campo', $campo);
        }

        return $query->orderBy('fila')->get();
    }

    /**
     * Caso 1: articulo con margen propio, Excel con precio manual.
     *
     * @return void
     */
    public function test_articulo_con_margen_propio_no_recibe_el_precio_del_excel()
    {
        $import = $this->importar_fixture();

        $p1 = $this->recargar_articulo('P1');

        $this->assertDecimal(25, $p1->percentage_gain, 'P1 tiene que conservar su margen propio.');
        $this->assertNull($p1->price, 'P1 no tiene que recibir el precio manual del Excel.');

        $conflictos = $this->columnas_ignoradas($import, 'price');

        $this->assertGreaterThan(0, $conflictos->count(), 'Tiene que quedar constancia del precio salteado.');

        $del_p1 = $conflictos->firstWhere('nombre_excel', 'Art margen propio');

        $this->assertNotNull($del_p1, 'Falta el conflicto de la fila de P1.');
        $this->assertEquals(999, (float) $del_p1->valor, 'El conflicto tiene que guardar el valor que traia el Excel.');
    }

    /**
     * Caso 2: articulo con precio manual, Excel con margen.
     *
     * @return void
     */
    public function test_articulo_con_precio_manual_no_recibe_el_margen_del_excel()
    {
        $import = $this->importar_fixture();

        $p2 = $this->recargar_articulo('P2');

        $this->assertDecimal(500, $p2->price, 'P2 tiene que conservar su precio manual.');
        $this->assertNull($p2->percentage_gain, 'P2 no tiene que recibir el margen del Excel.');

        $del_p2 = $this->columnas_ignoradas($import, 'percentage_gain')
                        ->firstWhere('nombre_excel', 'Art precio manual');

        $this->assertNotNull($del_p2, 'Falta el conflicto del margen salteado de P2.');
        $this->assertEquals(30, (float) $del_p2->valor, 'El conflicto tiene que guardar el margen que traia el Excel.');
    }

    /**
     * Caso 3: articulo con margen del proveedor APLICABLE (tiene costo), Excel con precio.
     *
     * @return void
     */
    public function test_articulo_con_margen_del_proveedor_no_recibe_el_precio_del_excel()
    {
        $import = $this->importar_fixture();

        $p3 = $this->recargar_articulo('P3');

        $this->assertNull($p3->price, 'P3 se maneja por el margen del proveedor: el precio del Excel no se aplica.');

        $del_p3 = $this->columnas_ignoradas($import, 'price')
                        ->firstWhere('nombre_excel', 'Art margen proveedor');

        $this->assertNotNull($del_p3, 'Falta el conflicto del precio salteado de P3.');
    }

    /**
     * Caso 3 hermano: el MISMO articulo pero sin costo. Sin costo no hay margen del proveedor
     * aplicable, asi que el precio del Excel SI se aplica. Es la condicion que el front no
     * tenia y que dejaba a estos articulos sin ninguna forma de recibir un precio.
     *
     * @return void
     */
    public function test_articulo_con_proveedor_con_margen_pero_sin_costo_si_recibe_el_precio()
    {
        $import = $this->importar_fixture();

        $p4 = $this->recargar_articulo('P4');

        $this->assertDecimal(777, $p4->price, 'Sin costo, el margen del proveedor no aplica y el precio del Excel tiene que entrar.');

        $del_p4 = $this->columnas_ignoradas($import, 'price')
                        ->firstWhere('nombre_excel', 'Art proveedor sin costo');

        $this->assertNull($del_p4, 'No corresponde registrar un salteo: el precio se aplico.');
    }

    /**
     * Caso 4: articulo sin ninguno de los dos, Excel con los dos. Gana el margen.
     *
     * @return void
     */
    public function test_sin_criterio_previo_gana_el_margen()
    {
        $import = $this->importar_fixture();

        $p5 = $this->recargar_articulo('P5');

        $this->assertDecimal(50, $p5->percentage_gain, 'El margen del Excel tiene que entrar.');
        $this->assertNull($p5->price, 'El precio del Excel se saltea: gana el margen.');

        $del_p5 = $this->columnas_ignoradas($import, 'price')
                        ->firstWhere('nombre_excel', 'Art sin criterio previo');

        $this->assertNotNull($del_p5, 'Falta el conflicto del precio salteado de P5.');
        $this->assertEquals(666, (float) $del_p5->valor);
    }

    /**
     * Un cero del Excel no es un valor: no se escribe (era lo que generaba el estado sucio
     * que esta mision elimina) y tampoco se reporta como columna ignorada, porque no se
     * salteo nada por criterio.
     *
     * @return void
     */
    public function test_un_cero_del_excel_no_se_escribe_ni_se_reporta()
    {
        $import = $this->importar_fixture();

        $p6 = $this->recargar_articulo('P6');

        $this->assertDecimal(20, $p6->percentage_gain, 'Un margen en 0 del Excel no puede pisar el margen que el articulo ya tenia.');
        $this->assertNotEquals(0, (float) $p6->percentage_gain, 'Un margen en 0 no puede quedar guardado: es el estado que traba la ficha.');

        $del_p6 = $this->columnas_ignoradas($import)
                        ->firstWhere('nombre_excel', 'Art margen cero del excel');

        $this->assertNull($del_p6, 'Un cero no es una columna salteada por criterio.');
    }

    /**
     * Caso 5: el salteo NO es una fila que no se pudo procesar. Si sumara a conflicts_count,
     * cualquier Excel con una columna de precio de mas mostraria el aviso de error que usa
     * ArticleImportHelper::enviar_notificacion(), y no corresponde.
     *
     * @return void
     */
    public function test_el_salteo_no_infla_conflicts_count()
    {
        $import = $this->importar_fixture();

        $this->assertGreaterThan(
            0,
            $this->columnas_ignoradas($import)->count(),
            'El escenario tiene que haber generado salteos, si no el test no prueba nada.'
        );

        $this->assertSame(0, (int) $import->conflicts_count, 'Un salteo de columna de precio no es un conflicto que cuente.');

        /* Y las ocho filas se procesaron: ninguna quedo afuera por el salteo. */
        $this->assertSame(8, (int) $import->filas_procesadas);

        $conteo = $this->conteo($import);

        $this->assertSame(
            8,
            array_sum(array_map('intval', $conteo)),
            'Las ocho filas tienen que estar clasificadas en algun bucket.'
        );

        $this->assertSame(0, isset($conteo['sin_clasificar']) ? (int) $conteo['sin_clasificar'] : 0);
    }

    /**
     * Dos filas del MISMO Excel sobre el MISMO articulo, una con margen y otra con precio.
     *
     * La segunda fila relee el articulo de la base (merge_fila_duplicada() hace
     * Article::find()), donde el margen que encolo la primera todavia no esta. Sin el
     * estado pendiente por articulo, las dos filas pasan el criterio, el merge por id de
     * ActualizarBBDD fusiona las claves y el UPDATE escribe LAS DOS columnas: despues
     * setFinalPrice() borra el precio y el historial queda diciendo que cambio un campo
     * que termino en null, que es exactamente lo que el salteo existe para impedir.
     *
     * @return void
     */
    public function test_dos_filas_del_mismo_excel_sobre_el_mismo_articulo()
    {
        $import = $this->importar_fixture();

        $p7 = $this->recargar_articulo('P7');

        $this->assertDecimal(40, $p7->percentage_gain, 'El margen de la primera fila tiene que quedar.');
        $this->assertNull($p7->price, 'El precio de la segunda fila se saltea: el articulo ya quedo manejado por margen.');

        $del_p7 = $this->columnas_ignoradas($import, 'price')
                        ->firstWhere('nombre_excel', 'Art dos filas mismo art');

        $this->assertNotNull($del_p7, 'Falta el conflicto del precio salteado de la segunda fila.');
    }

    /**
     * El campo salteado no puede aparecer en el detalle de cambios del historial: si
     * apareciera, el updated_props diria que ese campo cambio cuando no cambio, y el
     * rollback lo querria restaurar desde un diff que no describe nada real.
     *
     * @return void
     */
    public function test_el_campo_salteado_no_queda_en_el_detalle_de_cambios()
    {
        $import = $this->importar_fixture();

        $actualizados = $import->articulos_actualizados()
                                ->where('articles.id', $this->articulos['P1']->id)
                                ->get();

        foreach ($actualizados as $articulo) {

            $props = json_decode($articulo->pivot->updated_props, true);

            if (!is_array($props)) {
                continue;
            }

            $this->assertArrayNotHasKey(
                'price',
                $props,
                'El precio salteado no puede figurar como cambiado en el historial de P1.'
            );

            $this->assertArrayNotHasKey(
                '__diff__price',
                $props,
                'Tampoco puede quedar el diff del precio salteado: es lo que lee el rollback.'
            );
        }
    }
}
