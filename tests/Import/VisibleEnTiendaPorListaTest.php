<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\PriceType;
use Illuminate\Support\Facades\DB;

/**
 * La columna "visible en la tienda para la lista X" de la importación de Excel (misión
 * catalogo-por-lista-tienda, 5/10/2026).
 *
 * Contrato C2 con empresa-spa: la columna plana es `visible_en_tienda_<nombre de la lista
 * normalizado>` (mismo normalizado que `%_<lista>`), con valores Sí/No. Se escribe en
 * `article_price_type.visible_en_tienda` de esa lista. Lo que estos tests fijan:
 *
 *   1. Artículos CREADOS: "Sí" → 1, "No" → 0, celda vacía → NULL (nacen sin habilitar).
 *   2. Artículos EXISTENTES: "Sí"/"No" cambian la visibilidad; la celda vacía no la toca.
 *   3. 🔴 Un existente donde SOLO cambia la visibilidad NO se descarta (antes
 *      filter_only_changed_price_types() tiraba toda lista sin cambio de margen/precio/setear) y
 *      tampoco le pisa el margen propio con el de la lista (el UPDATE de margen/precio lo haría).
 *   4. Sin la columna mapeada, nada cambia: los existentes conservan lo suyo y los nuevos nacen en
 *      NULL.
 *   5. 🔴 Un Excel que solo trae la columna de la tienda no cuenta como "habla de precios": a los
 *      artículos que crea NO se les propaga el `setear_precio_final` de la lista (el defecto del
 *      precio congelado medido el 24/8/2026, ver ProcessRow::add_price_type_data()).
 *
 * El fixture es `32_visible_en_tienda.xlsx` (generar_visible_en_tienda.php). Los existentes son los
 * que siembra ImportTestSeeder con los MISMOS datos de la planilla, y además se apagan las columnas
 * de costo, precio, stock e IVA: así lo único que la fila puede cambiar es lo de las listas.
 *
 * ⚠️ Se lee con DB::table('article_price_type'), igual que ListasDePrecioPorDefectoTest: el pivote
 * no tiene índice único y lo que se mide son filas.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class VisibleEnTiendaPorListaTest extends ImportTestCase
{
    /** El fixture de esta misión. */
    const ARCHIVO = '32_visible_en_tienda.xlsx';

    /** @var \App\Models\PriceType Lista sin restricción. */
    protected $minorista;

    /** @var \App\Models\PriceType Lista con el catálogo restringido en la tienda. */
    protected $mayorista;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Mismo armado que ListasDePrecioPorDefectoTest: el tenant 900 no trabaja con listas, así
         * que se prende el flag y se crean las dos listas DENTRO de la transacción del test, después
         * de sembrar (si no, el sembrado ya las ataría por setFinalPrice).
         */
        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        $this->minorista = $this->crear_price_type('Minorista', 30, 0, 1);
        $this->mayorista = $this->crear_price_type('Mayorista', 40, 0, 2, 1);

        /*
         * Los existentes del fixture con su pivote de Mayorista ya armado. A1 tiene un margen PROPIO
         * (55, no el 40 de la lista): si el UPDATE de margen/precio pasara por esa fila, se lo
         * pisaría con el de la lista. A2 y A12 arrancan habilitados; A1 y A15, en NULL.
         */
        $this->atar('A1', $this->mayorista->id, 55, null);
        $this->atar('A2', $this->mayorista->id, 40, 1);
        $this->atar('A12', $this->mayorista->id, 40, 1);
        $this->atar('A15', $this->mayorista->id, 40, null);

        // Minorista atada a todos en NULL (el estado de cualquier artículo de una cuenta con listas).
        foreach (['A1', 'A2', 'A12', 'A15'] as $clave) {
            $this->atar($clave, $this->minorista->id, null, null);
        }
    }

    /**
     * Se asigna propiedad por propiedad, mismo motivo que ImportTestSeeder::crear_article().
     *
     * @param  string   $name
     * @param  float    $percentage
     * @param  int      $setear_precio_final
     * @param  int      $position
     * @param  int|null $catalogo_restringido_en_tienda
     * @return \App\Models\PriceType
     */
    protected function crear_price_type($name, $percentage, $setear_precio_final, $position, $catalogo_restringido_en_tienda = null)
    {
        $price_type = new PriceType();

        $price_type->name                                 = $name;
        $price_type->percentage                           = $percentage;
        $price_type->incluir_en_lista_de_precios_de_excel = 0;
        $price_type->setear_precio_final                  = $setear_precio_final;
        $price_type->position                             = $position;
        $price_type->catalogo_restringido_en_tienda       = $catalogo_restringido_en_tienda;
        $price_type->user_id                              = $this->tenant->id;

        $price_type->save();

        return $price_type;
    }

    /**
     * Una fila de pivote para un artículo sembrado.
     *
     * @param  string     $clave          A1..A15
     * @param  int        $price_type_id
     * @param  float|null $percentage
     * @param  int|null   $visible
     * @return void
     */
    protected function atar($clave, $price_type_id, $percentage, $visible)
    {
        DB::table('article_price_type')->insert([
            'article_id'          => $this->seed[$clave]->id,
            'price_type_id'       => $price_type_id,
            'percentage'          => $percentage,
            'setear_precio_final' => 0,
            'visible_en_tienda'   => $visible,
        ]);
    }

    /**
     * La configuración del mapeo: identificación y nombre como siempre, costo/precio/stock/IVA
     * apagados (-1), y las columnas de las listas que pida cada test.
     *
     * @param  array $extra
     * @return array
     */
    protected function config(array $extra)
    {
        return array_merge([
            'provider_id'       => null,
            'prop_costo'        => -1,
            'prop_precio'       => -1,
            'prop_stock_actual' => -1,
            'prop_iva'          => -1,
        ], $extra);
    }

    /**
     * La fila del pivote de un artículo en una lista (exactamente una).
     *
     * @param  int $article_id
     * @param  int $price_type_id
     * @return object
     */
    protected function fila($article_id, $price_type_id)
    {
        $filas = DB::table('article_price_type')
                    ->where('article_id', $article_id)
                    ->where('price_type_id', $price_type_id)
                    ->get();

        $this->assertCount(1, $filas, 'Tiene que haber exactamente una fila para el artículo ' . $article_id . ' en la lista ' . $price_type_id);

        return $filas->first();
    }

    /**
     * visible_en_tienda de un artículo en una lista: null, 0 o 1.
     *
     * @param  int $article_id
     * @param  int $price_type_id
     * @return int|null
     */
    protected function visible($article_id, $price_type_id)
    {
        $valor = $this->fila($article_id, $price_type_id)->visible_en_tienda;

        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * @param  string $codigo
     * @return \App\Models\Article
     */
    protected function creado($codigo)
    {
        $articulo = Article::where('user_id', $this->tenant->id)
                            ->where('provider_code', $codigo)
                            ->first();

        $this->assertNotNull($articulo, 'La importación tenía que crear el artículo ' . $codigo);

        return $articulo;
    }

    /**
     * Con la columna de la tienda y la de margen mapeadas: creados y existentes.
     *
     * @return void
     */
    public function test_con_la_columna_los_creados_y_los_existentes_toman_la_visibilidad_del_excel()
    {
        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
            'prop_%_mayorista'                 => 10,
        ]));

        // Existentes.
        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id), 'A1: "Si" lo habilita');
        $this->assertSame(0, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2: "No" lo deshabilita');
        $this->assertSame(1, $this->visible($this->seed['A12']->id, $this->mayorista->id), 'A12: celda vacía, conserva el 1');
        $this->assertSame(1, $this->visible($this->seed['A15']->id, $this->mayorista->id), 'A15: "SÍ" (con tilde y en mayúscula) lo habilita');

        // A15 además cambió el margen: las dos cosas en la misma fila.
        $this->assertDecimal(25, $this->fila($this->seed['A15']->id, $this->mayorista->id)->percentage, 'A15 toma el margen del Excel');

        // 🔴 A1 solo cambió la visibilidad: su margen propio NO se pisa con el 40 de la lista.
        $this->assertDecimal(55, $this->fila($this->seed['A1']->id, $this->mayorista->id)->percentage, 'A1 conserva su margen propio');

        // La lista sin columna no se toca.
        $this->assertNull($this->visible($this->seed['A1']->id, $this->minorista->id), 'Minorista no tenía columna');

        // Creados.
        $this->assertSame(1, $this->visible($this->creado('PC-VIS-1')->id, $this->mayorista->id), 'VIS-1: "sí"');
        $this->assertSame(0, $this->visible($this->creado('PC-VIS-2')->id, $this->mayorista->id), 'VIS-2: "no"');
        $this->assertNull($this->visible($this->creado('PC-VIS-3')->id, $this->mayorista->id), 'VIS-3: celda vacía, nace sin habilitar (NULL)');

        foreach (['PC-VIS-1', 'PC-VIS-2', 'PC-VIS-3'] as $codigo) {
            $this->assertNull($this->visible($this->creado($codigo)->id, $this->minorista->id), $codigo . ': Minorista nace en NULL');
            $this->assertDecimal(40, $this->fila($this->creado($codigo)->id, $this->mayorista->id)->percentage, $codigo . ': margen por defecto de Mayorista');
        }
    }

    /**
     * 🔴 El caso que motivó tocar filter_only_changed_price_types(): un Excel que SOLO trae la
     * columna de la tienda. El existente cambia la visibilidad sin perder el margen, y a los
     * creados no se les propaga el `setear_precio_final` de la lista.
     *
     * @return void
     */
    public function test_solo_la_columna_de_la_tienda_llega_a_los_existentes_sin_tocar_precios()
    {
        // La lista con "setear precio final" por defecto: es la que se congelaría si se propagara.
        DB::table('price_types')->where('id', $this->mayorista->id)->update(['setear_precio_final' => 1]);

        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
        ]));

        $a1 = $this->fila($this->seed['A1']->id, $this->mayorista->id);

        $this->assertSame(1, (int) $a1->visible_en_tienda, 'A1: la fila con SOLO la visibilidad cambiada no se descarta');
        $this->assertDecimal(55, $a1->percentage, 'A1: el margen propio queda intacto');
        $this->assertSame(0, (int) $a1->setear_precio_final, 'A1: setear_precio_final intacto (no se le copia el de la lista)');

        $this->assertSame(0, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2: "No"');

        // A15 trae margen en la planilla, pero la columna no está mapeada en este test.
        $this->assertDecimal(40, $this->fila($this->seed['A15']->id, $this->mayorista->id)->percentage, 'A15: sin la columna de margen, el margen no cambia');

        // Creados: visibilidad del Excel y setear_precio_final NO propagado.
        $vis_1 = $this->fila($this->creado('PC-VIS-1')->id, $this->mayorista->id);

        $this->assertSame(1, (int) $vis_1->visible_en_tienda);
        $this->assertSame(0, (int) $vis_1->setear_precio_final, 'Un Excel que solo habla de la tienda no fija precios a mano');
        $this->assertDecimal(40, $vis_1->percentage);
    }

    /**
     * Sin la columna mapeada, la importación no toca la visibilidad de nadie.
     *
     * @return void
     */
    public function test_sin_la_columna_no_cambia_nada_y_los_creados_nacen_en_null()
    {
        $this->importar(self::ARCHIVO, $this->config([
            'prop_%_mayorista' => 10,
        ]));

        $this->assertNull($this->visible($this->seed['A1']->id, $this->mayorista->id), 'A1 sigue en NULL');
        $this->assertSame(1, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2 sigue habilitado');
        $this->assertSame(1, $this->visible($this->seed['A12']->id, $this->mayorista->id), 'A12 sigue habilitado');

        // A15 cambió el margen (la columna sí está) y la visibilidad quedó como estaba.
        $this->assertDecimal(25, $this->fila($this->seed['A15']->id, $this->mayorista->id)->percentage);
        $this->assertNull($this->visible($this->seed['A15']->id, $this->mayorista->id), 'A15: el UPDATE de margen no toca la visibilidad');

        foreach (['PC-VIS-1', 'PC-VIS-2', 'PC-VIS-3'] as $codigo) {
            $this->assertNull($this->visible($this->creado($codigo)->id, $this->mayorista->id), $codigo . ': sin la columna nace en NULL');
        }
    }

    /**
     * Un "No" sobre un artículo que nunca se habilitó (NULL) no es un cambio: NULL y 0 son los
     * dos "no habilitado", y reimportar una planilla llena de "No" no tiene que reescribir nada.
     *
     * @return void
     */
    public function test_un_no_sobre_un_null_no_cuenta_como_cambio()
    {
        // A2 arranca en NULL en vez de 1: su "No" no cambia su visibilidad.
        DB::table('article_price_type')
            ->where('article_id', $this->seed['A2']->id)
            ->where('price_type_id', $this->mayorista->id)
            ->update(['visible_en_tienda' => null]);

        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
        ]));

        $this->assertNull($this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2 queda en NULL: no se reescribe a 0');
        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id), 'Y el resto del lote se aplica igual');
    }

    /**
     * Cuántas filas de pivote tiene un artículo en una lista con un `visible_en_tienda` dado.
     *
     * @param  int      $article_id
     * @param  int      $price_type_id
     * @param  int|null $visible       null = filas con la columna en NULL.
     * @return int
     */
    protected function filas_con_visible($article_id, $price_type_id, $visible)
    {
        $consulta = DB::table('article_price_type')
                        ->where('article_id', $article_id)
                        ->where('price_type_id', $price_type_id);

        if (is_null($visible)) {
            $consulta->whereNull('visible_en_tienda');
        } else {
            $consulta->where('visible_en_tienda', $visible);
        }

        return $consulta->count();
    }

    /**
     * 🔴 M1 de la revisión independiente (6/10/2026): un artículo EXISTENTE que no tiene fila de
     * pivote para la lista (una lista recién creada, cuyos pares todavía no los ató el recálculo
     * encolado, o un artículo viejo que nunca la tuvo) y un Excel con "Sí".
     *
     * El UPDATE de visibilidad solo tocaba filas que ya existían: con cero filas el importador
     * decía "actualizado" y el artículo quedaba sin habilitar, sin ningún aviso. Ahora la fila se
     * crea con la visibilidad del Excel, y NADA más: el margen y el precio de la lista los pone el
     * recálculo de precios como a cualquier par recién nacido (el margen por defecto de la lista),
     * no la importación.
     *
     * @return void
     */
    public function test_un_si_sobre_un_existente_sin_fila_de_pivote_crea_la_fila_habilitada_sin_inventar_margen()
    {
        // Una lista "recién creada": ningún artículo la tiene atada todavía.
        DB::table('article_price_type')->where('price_type_id', $this->mayorista->id)->delete();

        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
        ]));

        // A1 ("Si") y A15 ("SÍ"): la fila existe, una sola, y habilitada.
        foreach (['A1', 'A15'] as $clave) {
            $fila = $this->fila($this->seed[$clave]->id, $this->mayorista->id);

            $this->assertSame(1, (int) $fila->visible_en_tienda, $clave . ': el "Sí" del Excel tiene que habilitarlo aunque no tuviera fila');

            // Sin margen inventado: el 40 es el margen POR DEFECTO de la lista, que escribe el recálculo
            // de precios (como en cualquier par nuevo). El 55 que A1 tenía en el setUp() no existe más.
            $this->assertDecimal(40, $fila->percentage, $clave . ': el margen lo pone el recálculo, no la importación');
            $this->assertSame(0, (int) $fila->setear_precio_final, $clave . ': no se le fija el precio a mano');
            $this->assertSame(0, (int) $fila->incluir_en_excel_para_clientes, $clave . ': el par nace con los defaults de la base');
        }

        // A2 ("No") y A12 (celda vacía): nada que habilitar.
        $this->assertSame(0, $this->filas_con_visible($this->seed['A2']->id, $this->mayorista->id, 1), 'A2: "No" no habilita');
        $this->assertSame(0, $this->filas_con_visible($this->seed['A12']->id, $this->mayorista->id, 1), 'A12: celda vacía no habilita');

        // La lista sin columna en el Excel no se toca.
        $this->assertNull($this->visible($this->seed['A1']->id, $this->minorista->id), 'Minorista no tenía columna');

        // Y la fila nueva no se duplicó por haberla creado antes del recálculo: a lo sumo una por par
        // (A2 y A12 no traen ningún cambio, así que la importación ni los toca y siguen sin fila).
        foreach (['A1', 'A2', 'A12', 'A15'] as $clave) {
            $this->assertLessThanOrEqual(
                1,
                DB::table('article_price_type')->where('article_id', $this->seed[$clave]->id)->where('price_type_id', $this->mayorista->id)->count(),
                $clave . ': nunca dos filas en Mayorista'
            );
        }
    }

    /**
     * 🔴 B1 de la revisión independiente (6/10/2026): lo que no es un "Sí" ni un "No" reconocible es
     * "no informado" y NO se escribe. Antes cualquier texto raro valía 0 y un typo ("Sii"), o una
     * columna mal mapeada, DESHABILITABA artículos al reimportar.
     *
     * Acá la columna "visible" se apunta, por error, a la del NOMBRE (la 4): ningún nombre es un Sí
     * ni un No, así que ningún artículo cambia su visibilidad. Con el parser anterior A2 y A12, que
     * arrancaban habilitados, quedaban en 0, y los nuevos nacían con un 0 en vez de NULL.
     * (El detalle de qué texto vale qué lo fija SiONoDeLaCeldaTest.)
     *
     * @return void
     */
    public function test_un_texto_que_no_es_si_ni_no_no_deshabilita_a_nadie()
    {
        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 4,
        ]));

        $this->assertSame(1, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2 estaba habilitado y un texto raro no lo deshabilita');
        $this->assertSame(1, $this->visible($this->seed['A12']->id, $this->mayorista->id), 'A12 estaba habilitado y un texto raro no lo deshabilita');
        $this->assertNull($this->visible($this->seed['A1']->id, $this->mayorista->id), 'A1 sigue en NULL: no se le escribe un 0');
        $this->assertNull($this->visible($this->seed['A15']->id, $this->mayorista->id), 'A15 sigue en NULL: no se le escribe un 0');

        foreach (['PC-VIS-1', 'PC-VIS-2', 'PC-VIS-3'] as $codigo) {
            $this->assertNull($this->visible($this->creado($codigo)->id, $this->mayorista->id), $codigo . ': un texto raro es "no informado", el artículo nace en NULL');
        }
    }

    /* ==================================================================
     * El endpoint que usa el modal con IA (columns en JSON)
     * ================================================================== */

    /**
     * 🔴 EL CAMINO QUE USA LA SPA ES OTRO ENDPOINT. Los tests de arriba entran por el import clásico
     * (`/api/article/excel/import`, con las claves `prop_*`, que PHP baja a minúsculas), pero el
     * modal del paso 3 postea a `/api/ai-excel-import/import`, que recibe `columns` en JSON y se lo
     * pasa a InitExcelImport tal cual. Ahí la columna ya viene como la arma la SPA
     * (`columns['visible_en_tienda_' + name_key]`, con `name_key` = nombre en minúsculas y los
     * espacios a `_`): si la API la leyera distinto, los tests del import clásico seguirían verdes y
     * la columna se ignoraría en silencio justo en el único camino que el usuario usa.
     *
     * Misma planilla que el import clásico (`32_visible_en_tienda.xlsx`), mapeo 0-based y sin
     * columnas de costo, precio, stock ni IVA, así lo único que puede cambiar son las listas.
     *
     * @param  array $columnas  Columnas extra (propiedad => índice 0-based).
     * @param  array $extra     Overrides del cuerpo del request.
     * @return \App\Models\ImportHistory
     */
    protected function importar_por_el_modal(array $columnas, array $extra = [])
    {
        $origen = __DIR__ . '/fixtures/' . self::ARCHIVO;

        $this->assertFileExists($origen, 'Falta el fixture ' . self::ARCHIVO);

        $carpeta = storage_path('app/imported_files');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        /* Nombre único: el CSV que arma InitExcelImport se deriva del nombre del Excel más time(). */
        $base   = uniqid('visible_en_tienda_modal_');
        $nombre = $base . '.xlsx';

        copy($origen, $carpeta . '/' . $nombre);

        $data = array_merge(
            [
                'excel_path'      => 'imported_files/' . $nombre,
                'model'           => 'article',
                'columns'         => array_merge([
                    'codigo_de_barras'    => 0,
                    'sku'                 => 1,
                    'codigo_de_proveedor' => 2,
                    'nombre'              => 3,
                ], $columnas),
                'start_row'       => 2,
                /* InitExcelImport::ajustar_finish_row_segun_excel_real() lo baja al real. */
                'finish_row'      => 99999,
                'provider_id'     => null,
                'create_and_edit' => true,
                'registrar_art_cre'                                  => true,
                'registrar_art_act'                                  => true,
                'actualizar_por_provider_code'                       => true,
                'actualizar_proveedor'                               => true,
                'permitir_provider_code_repetido'                    => false,
                'permitir_provider_code_repetido_en_multi_providers' => true,
                'actualizar_articulos_de_otro_proveedor'             => false,
            ],
            $extra
        );

        try {
            $this->postJson('/api/ai-excel-import/import', $data)->assertStatus(200);
        } finally {
            // La copia del Excel y el CSV que se deriva de ella no tienen por qué quedar en storage.
            foreach (glob($carpeta . '/' . $base . '*') ?: [] as $residuo) {
                @unlink($residuo);
            }
        }

        $import = ImportHistory::where('user_id', $this->tenant->id)
                                ->orderBy('id', 'DESC')
                                ->first();

        $this->assertNotNull($import, 'La importación por el endpoint del modal no dejó ImportHistory.');

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * Por el endpoint del modal, con `columns` en JSON: la columna plana `visible_en_tienda_mayorista`
     * (la que arma la SPA a partir de `price_type_{id}_visible_en_tienda`) llega a los creados y a
     * los existentes igual que por el import clásico.
     *
     * @return void
     */
    public function test_por_el_endpoint_del_modal_la_columna_plana_llega_a_los_creados_y_a_los_existentes()
    {
        $this->importar_por_el_modal(['visible_en_tienda_mayorista' => 8]);

        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id), 'A1: "Si" lo habilita');
        $this->assertSame(0, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2: "No" lo deshabilita');
        $this->assertSame(1, $this->visible($this->seed['A12']->id, $this->mayorista->id), 'A12: celda vacía, conserva el 1');
        $this->assertSame(1, $this->visible($this->seed['A15']->id, $this->mayorista->id), 'A15: "SÍ" con tilde y en mayúscula lo habilita');

        // 🔴 El margen propio de A1 (55) no se pisa: solo cambió la visibilidad.
        $this->assertDecimal(55, $this->fila($this->seed['A1']->id, $this->mayorista->id)->percentage, 'A1 conserva su margen propio');

        $this->assertSame(1, $this->visible($this->creado('PC-VIS-1')->id, $this->mayorista->id), 'VIS-1: "sí"');
        $this->assertSame(0, $this->visible($this->creado('PC-VIS-2')->id, $this->mayorista->id), 'VIS-2: "no"');
        $this->assertNull($this->visible($this->creado('PC-VIS-3')->id, $this->mayorista->id), 'VIS-3: celda vacía, nace en NULL');

        // La lista sin columna no se toca.
        $this->assertNull($this->visible($this->seed['A1']->id, $this->minorista->id));
    }

    /**
     * Una lista de DOS palabras: la SPA arma `visible_en_tienda_lista_mayorista` (nombre en
     * minúsculas y los espacios a `_`) y la API tiene que armar la misma clave a partir del nombre
     * "Lista Mayorista". Si las dos normalizaciones se separaran, la columna se ignoraría sin ningún
     * error. (Con la clave de una sola palabra de siempre no alcanza: no ejercita el espacio.)
     *
     * @return void
     */
    public function test_por_el_endpoint_del_modal_una_lista_de_dos_palabras_se_arma_con_guion_bajo()
    {
        DB::table('price_types')->where('id', $this->mayorista->id)->update(['name' => 'Lista Mayorista']);

        $this->importar_por_el_modal(['visible_en_tienda_lista_mayorista' => 8]);

        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id), 'A1: "Si" lo habilita en "Lista Mayorista"');
        $this->assertSame(0, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2: "No" lo deshabilita');
        $this->assertSame(1, $this->visible($this->creado('PC-VIS-1')->id, $this->mayorista->id), 'VIS-1: "sí"');
    }

    /**
     * `vaciar_valores_en_blanco` (el checkbox único del modal: una celda vacía borra la propiedad)
     * NO deshabilita un artículo por una celda vacía de la columna de la tienda. Lo que "vaciar"
     * vacía son propiedades del artículo; la visibilidad en el catálogo de los mayoristas es otra
     * decisión, y una planilla parcial no tiene que sacar artículos de la tienda de nadie. Lo fija
     * la celda vacía de A12 (habilitado) con el checkbox prendido.
     *
     * @return void
     */
    public function test_vaciar_valores_en_blanco_no_deshabilita_por_una_celda_vacia()
    {
        $this->importar_por_el_modal(['visible_en_tienda_mayorista' => 8], ['vaciar_valores_en_blanco' => true]);

        $this->assertSame(1, $this->visible($this->seed['A12']->id, $this->mayorista->id), 'A12: celda vacía, sigue habilitado aunque se vacíen los blancos');
        $this->assertNull($this->visible($this->creado('PC-VIS-3')->id, $this->mayorista->id), 'VIS-3: celda vacía, nace en NULL');

        // Y lo que sí trae un valor se aplica igual.
        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id));
        $this->assertSame(0, $this->visible($this->seed['A2']->id, $this->mayorista->id));
    }

    /**
     * Una columna mapeada a una lista SIN restricción también se escribe: el interruptor es de la
     * tienda, no de la importación. (La pantalla solo ofrece la columna para las listas restringidas,
     * pero la API la acepta para cualquiera: sirve para cargar la habilitación ANTES de prender el
     * interruptor y mirar el contador "X habilitados de Y"; mientras la lista no esté restringida la
     * tienda ignora el valor.)
     *
     * @return void
     */
    public function test_una_lista_sin_restriccion_tambien_recibe_la_columna()
    {
        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_minorista' => 9,
        ]));

        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->minorista->id), 'A1: "Si" en la lista sin restricción');
        $this->assertSame(1, $this->visible($this->seed['A15']->id, $this->minorista->id), 'A15: "SÍ"');

        // Un "No" sobre un NULL no es un cambio (NULL y 0 son lo mismo): sigue en NULL.
        $this->assertNull($this->visible($this->seed['A2']->id, $this->minorista->id), 'A2: "No" sobre NULL no escribe');

        // La lista restringida, sin columna, no se toca.
        $this->assertSame(1, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'Mayorista no tenía columna');
    }

    /**
     * Dos listas a la vez: cada una con su columna (acá las dos apuntan a la misma columna del
     * Excel) y cada una se escribe por su cuenta. Un "No" sobre una lista deshabilitada y sobre
     * otra en NULL da resultados distintos, y el UPDATE de visibilidad no mezcla los pares.
     *
     * @return void
     */
    public function test_dos_listas_a_la_vez_se_escriben_cada_una_por_su_cuenta()
    {
        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
            'prop_visible_en_tienda_minorista' => 9,
        ]));

        // A1 ("Si"): las dos listas pasan de NULL a 1.
        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id));
        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->minorista->id));

        // A2 ("No"): Mayorista pasa de 1 a 0; Minorista, que estaba en NULL, no cambia.
        $this->assertSame(0, $this->visible($this->seed['A2']->id, $this->mayorista->id), 'A2 en Mayorista: de 1 a 0');
        $this->assertNull($this->visible($this->seed['A2']->id, $this->minorista->id), 'A2 en Minorista: "No" sobre NULL no escribe');

        // A12 (vacía): ninguna de las dos se toca.
        $this->assertSame(1, $this->visible($this->seed['A12']->id, $this->mayorista->id));
        $this->assertNull($this->visible($this->seed['A12']->id, $this->minorista->id));

        // A15 ("SÍ"): las dos pasan a 1.
        $this->assertSame(1, $this->visible($this->seed['A15']->id, $this->mayorista->id));
        $this->assertSame(1, $this->visible($this->seed['A15']->id, $this->minorista->id));

        // Los creados toman el valor de la planilla en las dos listas.
        foreach (['mayorista', 'minorista'] as $lista) {
            $id = $this->{$lista}->id;

            $this->assertSame(1, $this->visible($this->creado('PC-VIS-1')->id, $id), 'VIS-1 en ' . $lista);
            $this->assertSame(0, $this->visible($this->creado('PC-VIS-2')->id, $id), 'VIS-2 en ' . $lista);
            $this->assertNull($this->visible($this->creado('PC-VIS-3')->id, $id), 'VIS-3 en ' . $lista);
        }
    }

    /**
     * M1: el INSERT de los pares sin fila no duplica a los que ya tienen. El pivote no tiene índice
     * único (un artículo puede tener la lista atada dos veces) y un INSERT a ciegas le sumaría una
     * tercera fila: acá A1 ya tiene dos, con la visibilidad en NULL, y las dos terminan en 1.
     *
     * @return void
     */
    public function test_un_si_sobre_un_par_que_ya_tiene_dos_filas_no_suma_una_tercera()
    {
        $this->atar('A1', $this->mayorista->id, 55, null);

        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
        ]));

        $valores = DB::table('article_price_type')
                        ->where('article_id', $this->seed['A1']->id)
                        ->where('price_type_id', $this->mayorista->id)
                        ->pluck('visible_en_tienda')
                        ->map(function ($valor) {
                            return is_null($valor) ? null : (int) $valor;
                        })
                        ->all();

        $this->assertSame([1, 1], $valores, 'Las dos filas existentes se habilitan y no nace una tercera');
    }

    /**
     * M1, el mismo caso con "No": un existente sin fila de pivote y un "No" no habilita nada ni
     * inventa un 0. NULL y 0 son los dos "no habilitado" y reimportar una planilla llena de "No" no
     * tiene que escribir nada (mismo criterio que el "No" sobre un NULL).
     *
     * @return void
     */
    public function test_un_no_sobre_un_existente_sin_fila_de_pivote_no_habilita_ni_escribe_un_cero()
    {
        DB::table('article_price_type')
            ->where('article_id', $this->seed['A2']->id)
            ->where('price_type_id', $this->mayorista->id)
            ->delete();

        $this->importar(self::ARCHIVO, $this->config([
            'prop_visible_en_tienda_mayorista' => 9,
        ]));

        $this->assertSame(0, $this->filas_con_visible($this->seed['A2']->id, $this->mayorista->id, 1), 'A2: un "No" no lo habilita');
        $this->assertSame(0, $this->filas_con_visible($this->seed['A2']->id, $this->mayorista->id, 0), 'A2: un "No" sobre nada no escribe un 0');

        // El resto del lote se aplica igual.
        $this->assertSame(1, $this->visible($this->seed['A1']->id, $this->mayorista->id), 'A1 se habilita igual');
    }
}
