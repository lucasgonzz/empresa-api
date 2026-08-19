<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderPriceOffer;
use App\Models\User;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Tests\Import\ImportTestCase;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 8 (pasada de arreglos
 * post-chequeo): A1, A3 y A11 de OfertasDeProveedorService /
 * NewProviderOrderHelper.
 *
 * A1 -- un costo en dólares se guardaba como si fueran pesos:
 * catalogar_costo_proveedor() nunca mandaba moneda_id y registrar_lote()
 * completaba con el default (1 = Peso). Se prueba SIN sembrar la fila a
 * mano: se invoca NewProviderOrderHelper::attach_articles() de punta a
 * punta (mismo patrón que el tercer punto de captura del archivo 3), para
 * probar el camino real que lee cost_in_dollars del pivot de la línea y
 * provider_order->moneda_id, no una simulación.
 *
 * A3 -- un proveedor con precio estable desaparecía de la competencia a los
 * 120 días: registrar_lote() leía "la última fila" sin ventana de fecha, así
 * que un costo que nunca cambia dejaba una sola fila que terminaba fuera de
 * la ventana de mejores_ofertas_para(). Se prueba moviendo el reloj con
 * Carbon::setTestNow() (mismo patrón que
 * registrar_lote_dedupea_por_cambio_de_costo_no_por_dia del archivo 3).
 *
 * A11 -- mejores_ofertas_para() desempataba dos filas del mismo día por
 * `id DESC`, así que una importación cargada más tarde le ganaba a una
 * compra real del mismo día -- el dato más confiable del histórico.
 *
 * Bloqueante de revisión de merge (15/8/2026): A1 se había arreglado SOLO del
 * lado de la compra (los tres tests de arriba) -- los otros dos escritores del
 * histórico (ProcessRow::registrar_oferta_de_otro_proveedor(), en la
 * importación, y el volcado de la migración de dedupe del pivot) seguían
 * mandando moneda_id=1 fijo, y por eso el bug de importación pasaba de largo
 * con la suite en verde. Los tres tests de más abajo cierran esa brecha: los
 * dos primeros recorren el camino real de la importación (puntos A y B de
 * ProcessRow, mismo mecanismo que el archivo 3) con una fila en dólares y una
 * en pesos; el tercero prueba el volcado de la migración con un artículo
 * cost_in_dollars=1.
 *
 * @group sugerencias-compras
 */
class Moneda_y_vigencia_del_historico_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Si no, el pipeline sale a la API real de Anthropic. Ningún test de
        // este archivo usa IA, pero es la regla obligatoria de setUp de toda
        // la misión (§11 del plan), la misma en los otros 7 archivos.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio moneda y vigencia P8',
            'email'    => 'sugerencias-compras-p8-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Proveedor propio de este archivo: ids frescos, cero chance de pisar el
     * fixture de otro archivo de esta misma suite que corre en paralelo
     * sobre la misma base (empresa_testing_s1).
     *
     * @param string $sufijo
     * @return Provider
     */
    protected function crear_proveedor($sufijo)
    {
        return Provider::create(['name' => 'Proveedor P8 ' . $sufijo, 'user_id' => $this->comercio->id]);
    }

    /**
     * Artículo propio de este archivo.
     *
     * $cost_inicial (opcional) se usa SOLO para los tests que compran: si se
     * deja en null, articles.cost queda null y update_cost() (NewProviderOrderHelper)
     * detecta "cambió" en cuanto se compra, y dispara
     * ArticleHelper::setFinalPrice($article) SIN pasarle usuario -- bug
     * preexistente y ajeno a esta misión (revienta con "Trying to get
     * property 'percentage_gain' of non-object" porque $user llega null a
     * calcular_base_antes_de_listas()). Igualar el costo inicial al costo de
     * la compra hace que update_cost() no detecte cambio y no dispare ese
     * camino -- sigue siendo el camino real de principio a fin
     * (attach_articles -> update_article -> catalogar_costo_proveedor), solo
     * que sin tropezar con un efecto colateral que no tiene nada que ver con
     * A1/A3/A11.
     *
     * @param string $sufijo
     * @param float|null $cost_inicial
     * @return Article
     */
    protected function crear_articulo($sufijo, $cost_inicial = null)
    {
        return Article::create([
            'name'    => 'Articulo P8 ' . $sufijo,
            'user_id' => $this->comercio->id,
            'cost'    => $cost_inicial,
        ]);
    }

    /**
     * Orden de compra mínima -- mismo molde que el tercer punto de captura
     * del archivo 3: update_prices=1 (gate de catalogar_costo_proveedor()) y
     * precios_incluyen_iva=false para que back_out_iva() nunca entre en
     * juego (este archivo es sobre moneda, no sobre IVA).
     *
     * @param Provider $provider
     * @param int $moneda_id 1 = Peso, 2 = Dólar (la moneda de la ORDEN completa).
     * @return ProviderOrder
     */
    protected function crear_orden($provider, $moneda_id)
    {
        return ProviderOrder::create([
            'num'                      => mt_rand(9000000, 9999999),
            'provider_id'              => $provider->id,
            'provider_order_status_id' => 1,
            'update_stock'             => 0,
            'update_prices'            => 1,
            'generate_current_acount'  => 0,
            'precios_incluyen_iva'     => false,
            'moneda_id'                => $moneda_id,
            'user_id'                  => $this->comercio->id,
        ]);
    }

    /**
     * Invoca el camino real de la compra (NewProviderOrderHelper::attach_articles()
     * -> update_article() -> catalogar_costo_proveedor(), con update_prices=1)
     * para un solo artículo -- ningún atajo ni simulación.
     *
     * @param ProviderOrder $orden
     * @param Article $articulo
     * @param float $cost
     * @param bool $cost_in_dollars Flag de LA LÍNEA (article_provider_order.cost_in_dollars).
     * @return void
     */
    protected function comprar($orden, $articulo, $cost, $cost_in_dollars)
    {
        $helper = new NewProviderOrderHelper($orden, [
            [
                'id'            => $articulo->id,
                'bar_code'      => $articulo->bar_code,
                'provider_code' => 'PC-P8',
                'pivot'         => [
                    'cost'            => $cost,
                    'amount'          => 1,
                    'received'        => 1,
                    'price'           => null,
                    'discount'        => null,
                    'iva_id'          => null,
                    'cost_in_dollars' => $cost_in_dollars ? 1 : 0,
                    'update_provider' => 1,
                ],
            ],
        ]);

        $helper->attach_articles();
    }

    /**
     * Busca la oferta de origen='compra' de un par (artículo, proveedor).
     *
     * @param Article $articulo
     * @param Provider $provider
     * @return ProviderPriceOffer|null
     */
    protected function oferta_de_compra($articulo, $provider)
    {
        return ProviderPriceOffer::where('article_id', $articulo->id)
            ->where('provider_id', $provider->id)
            ->where('origen', 'compra')
            ->first();
    }

    /**
     * A1: una compra tipeada en dólares (cost_in_dollars=1 en LA LÍNEA) sobre
     * una orden en pesos (moneda_id=1) tiene que dejar la oferta con
     * moneda_id=2. Es exactamente el caso del hallazgo: antes de este
     * arreglo, esta compra de USD 10 quedaba anotada como oferta de $10 --
     * ese proveedor ganaba "el más barato" por ~1000x en la corrida
     * siguiente del motor de sugerencias.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compra_en_dolares_sobre_orden_en_pesos_deja_moneda_id_2()
    {
        $provider = $this->crear_proveedor('dolares');
        $articulo = $this->crear_articulo('dolares', 10.0);
        $orden    = $this->crear_orden($provider, 1); // orden en pesos

        $this->comprar($orden, $articulo, 10.0, true); // línea puntual tildada en dólares

        $oferta = $this->oferta_de_compra($articulo, $provider);

        $this->assertNotNull($oferta, 'catalogar_costo_proveedor() tiene que dejar la oferta de esta compra.');
        $this->assertEquals(
            2,
            (int) $oferta->moneda_id,
            'cost_in_dollars=1 en la línea tiene que dejar moneda_id=2, aunque la orden entera sea en pesos.'
        );
        // El costo NUNCA se convierte acá (ni el que se guarda en article_provider ni
        // el del histórico): sigue siendo el literal tipeado. Lo único que cambia con
        // este arreglo es la ETIQUETA de moneda, para que el motor sepa que no compite
        // en pesos contra las demás ofertas.
        $this->assertEquals(10.0, (float) $oferta->cost);
    }

    /**
     * A1, segundo camino de la regla (el OR con provider_order->moneda_id):
     * una orden ENTERA en dólares también tiene que dejar moneda_id=2 aunque
     * la línea puntual NO tenga tildado cost_in_dollars -- get_total_article()
     * nunca convierte cuando la orden ya es en dólares (su `if` solo dispara
     * con moneda_id==1), así que ahí el costo tipeado ya está directo en esa
     * moneda sin que haga falta tildar nada por línea.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compra_en_orden_moneda_dolares_deja_moneda_id_2_aunque_la_linea_no_este_tildada()
    {
        $provider = $this->crear_proveedor('orden-dolares');
        $articulo = $this->crear_articulo('orden-dolares', 25.0);
        $orden    = $this->crear_orden($provider, 2); // orden ENTERA en dólares

        $this->comprar($orden, $articulo, 25.0, false); // línea SIN tildar cost_in_dollars

        $oferta = $this->oferta_de_compra($articulo, $provider);

        $this->assertNotNull($oferta);
        $this->assertEquals(2, (int) $oferta->moneda_id);
        $this->assertEquals(25.0, (float) $oferta->cost);
    }

    /**
     * A1: el caso de siempre, sin regresión -- una compra en pesos (ni la
     * línea ni la orden marcadas en dólares) sigue dejando moneda_id=1.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compra_en_pesos_deja_moneda_id_1()
    {
        $provider = $this->crear_proveedor('pesos');
        $articulo = $this->crear_articulo('pesos', 555.0);
        $orden    = $this->crear_orden($provider, 1);

        $this->comprar($orden, $articulo, 555.0, false);

        $oferta = $this->oferta_de_compra($articulo, $provider);

        $this->assertNotNull($oferta);
        $this->assertEquals(1, (int) $oferta->moneda_id);
        $this->assertEquals(555.0, (float) $oferta->cost);
    }

    /**
     * A3: con el mismo costo, reconfirmar ANTES de DIAS_RECONFIRMACION (30)
     * días no puede dejar fila nueva (sigue siendo la misma "verdad" de
     * precio); a partir de esos 30 días sí, aunque el costo sea idéntico --
     * si no, ese proveedor sale de la ventana de vigencia de
     * mejores_ofertas_para() (120 días por default) sin haber dejado de
     * ofertar. $ahora_real fijo ANTES de cualquier setTestNow(), para que
     * cada offset sea explícito y no dependa de encadenar sumas sobre un
     * testNow ya movido.
     *
     * @group sugerencias-compras
     * @test
     */
    public function costo_igual_reconfirmado_despues_de_dias_reconfirmacion_deja_fila_nueva_antes_no()
    {
        $provider = $this->crear_proveedor('reconfirmacion');
        $articulo = $this->crear_articulo('reconfirmacion');
        $ahora_real = Carbon::now();

        OfertasDeProveedorService::registrar_lote(
            [$articulo->id => [$provider->id => ['cost' => 800]]],
            $this->comercio->id,
            'importacion',
            null
        );

        $this->assertEquals(
            1,
            ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
            'Primera oferta: tiene que quedar 1 fila.'
        );

        try {
            // +29 días, mismo costo: todavía DENTRO de la ventana de
            // reconfirmación (30) -> no puede dejar fila nueva.
            Carbon::setTestNow($ahora_real->copy()->addDays(29));

            OfertasDeProveedorService::registrar_lote(
                [$articulo->id => [$provider->id => ['cost' => 800]]],
                $this->comercio->id,
                'importacion',
                null
            );

            $this->assertEquals(
                1,
                ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
                'A los 29 días, con el mismo costo, todavía NO tiene que reconfirmar (DIAS_RECONFIRMACION = 30).'
            );

            // +30 días desde el momento original (>= DIAS_RECONFIRMACION):
            // ya pasó el plazo -> tiene que reconfirmar con una fila nueva.
            Carbon::setTestNow($ahora_real->copy()->addDays(30));

            OfertasDeProveedorService::registrar_lote(
                [$articulo->id => [$provider->id => ['cost' => 800]]],
                $this->comercio->id,
                'importacion',
                null
            );

            $filas = ProviderPriceOffer::where('article_id', $articulo->id)
                ->where('provider_id', $provider->id)
                ->orderBy('fecha')
                ->get();

            $this->assertCount(
                2,
                $filas,
                'A los 30 días (>= DIAS_RECONFIRMACION), con el mismo costo, tiene que dejar una fila nueva de reconfirmación.'
            );
            $this->assertEquals(800.0, (float) $filas[1]->cost, 'La fila de reconfirmación tiene el MISMO costo, no uno distinto.');
            $this->assertEquals($ahora_real->copy()->addDays(30)->toDateString(), $filas[1]->fecha);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A11: dos filas del MISMO día -- una 'compra' creada primero (id más
     * bajo) y una 'importacion' creada después (id más alto) -- tienen que
     * desempatar a favor de 'compra', el dato más confiable, y no del id más
     * alto. Escenario realista: la compra se cargó a la mañana y la
     * importación de la lista de precios del proveedor llegó a la tarde del
     * mismo día.
     *
     * @group sugerencias-compras
     * @test
     */
    public function mejores_ofertas_para_desempata_el_mismo_dia_a_favor_de_compra()
    {
        $provider = $this->crear_proveedor('desempate');
        $articulo = $this->crear_articulo('desempate');
        $hoy = now()->toDateString();

        $oferta_compra = ProviderPriceOffer::create([
            'user_id'       => $this->comercio->id,
            'article_id'    => $articulo->id,
            'provider_id'   => $provider->id,
            'cost'          => 900.0,
            'moneda_id'     => 1,
            'origen'        => 'compra',
            'fecha'         => $hoy,
            'referencia_id' => null,
        ]);

        $oferta_importacion = ProviderPriceOffer::create([
            'user_id'       => $this->comercio->id,
            'article_id'    => $articulo->id,
            'provider_id'   => $provider->id,
            'cost'          => 950.0,
            'moneda_id'     => 1,
            'origen'        => 'importacion',
            'fecha'         => $hoy,
            'referencia_id' => null,
        ]);

        $this->assertGreaterThan(
            $oferta_compra->id,
            $oferta_importacion->id,
            'La importación tiene que tener el id más alto para que este test pruebe algo: sin esto, id DESC ya elegiría bien por casualidad.'
        );

        $resultado = OfertasDeProveedorService::mejores_ofertas_para([$articulo->id], $this->comercio->id, 120);

        $this->assertArrayHasKey($articulo->id, $resultado);
        $this->assertArrayHasKey($provider->id, $resultado[$articulo->id]);

        $elegida = $resultado[$articulo->id][$provider->id];

        $this->assertEquals(
            'compra',
            $elegida['origen'],
            'El desempate del mismo día tiene que preferir origen=compra, aunque tenga el id más bajo.'
        );
        $this->assertEquals(900.0, (float) $elegida['cost']);
    }

    /**
     * Corre una importación real contra el endpoint (mismo mecanismo que
     * Captura_de_ofertas_en_la_importacion_Test::importar_filas() del archivo 3,
     * con una columna "moneda" agregada al final del header). Reusa columnas() y
     * config_por_defecto() de ImportTestCase para no duplicar ese contrato, pero
     * este archivo NO extiende esa clase: usa sus propios ids frescos (comercio /
     * proveedores / artículos por test), no el tenant compartido id=900 ni el
     * fixture A1..A15 de tests/Import.
     *
     * Cache::flush() + ArticleIndexCache::reset_runtime_de_tests() antes de cada
     * corrida por el mismo motivo que ImportTestCase::setUp() los hace en cada uno
     * de sus tests: el índice de artículos vive en cache Y en estáticas de
     * proceso, y PHPUnit corre todos los tests del archivo en el mismo proceso.
     *
     * @param array $filas
     * @param array $config
     * @return \App\Models\ImportHistory
     */
    protected function importar_filas_p8(array $filas, array $config = [])
    {
        Cache::flush();
        ArticleIndexCache::reset_runtime_de_tests();
        $this->actingAs($this->comercio, 'web');

        $ruta = sys_get_temp_dir() . '/' . uniqid('sugerencias_compras_p8_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva', 'moneda',
        ]));
        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }
        $writer->close();

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $ruta,
                    'import.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            ImportTestCase::config_por_defecto(),
            ImportTestCase::columnas(),
            ['prop_moneda' => 9],
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        return ImportHistory::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * true si el índice existe en la tabla (mismo helper que Dedupe_del_pivot_Test
     * del archivo 2, que prueba esta misma migración desde otros ángulos).
     *
     * @param string $tabla
     * @param string $nombre_indice
     * @return bool
     */
    protected function indice_existe($tabla, $nombre_indice)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = '{$nombre_indice}'");

        return !empty($filas);
    }

    /**
     * Bloqueante de revisión de merge: punto A de la importación (ProcessRow.php,
     * la fila bloqueada por provider_code existente en OTRO proveedor -- mismo
     * mecanismo que punto_a_... del archivo 3) con una fila cuya columna "moneda"
     * trae "dolar". Antes de este arreglo, registrar_oferta_de_otro_proveedor()
     * nunca mandaba moneda_id y registrar_lote() completaba con el default (1 =
     * Peso) -- el mismo bug que A1, pero del lado de la importación, sin ningún
     * test que lo cubriera (los tres casos de moneda de arriba son todos de
     * compra). Recorre el camino real: POST a /api/article/excel/import, no
     * Reflection ni fila sembrada a mano.
     *
     * @group sugerencias-compras
     * @test
     */
    public function importacion_punto_a_fila_bloqueada_en_dolares_deja_moneda_id_2()
    {
        $provider_dueno   = $this->crear_proveedor('import-a-dueno');
        $provider_importa = $this->crear_proveedor('import-a-importa');

        $articulo_bloqueante = Article::create([
            'name'          => 'Articulo P8 bloqueante import A',
            'user_id'       => $this->comercio->id,
            'provider_id'   => $provider_dueno->id,
            'provider_code' => 'PC-P8-IMPORT-A',
        ]);

        $this->importar_filas_p8([
            [null, 'SKU-P8-IMPORT-A-USD', 'PC-P8-IMPORT-A', 'Nombre que no tiene que pisar nada', 88.0, 200.0, 5.0, '21', 'dolar'],
        ], [
            'provider_id' => $provider_importa->id,
            'permitir_provider_code_repetido_en_multi_providers' => false,
        ]);

        $oferta = ProviderPriceOffer::where('article_id', $articulo_bloqueante->id)
            ->where('provider_id', $provider_importa->id)
            ->where('origen', 'importacion')
            ->first();

        $this->assertNotNull($oferta, 'El punto A tiene que dejar la oferta del proveedor que intentó importar.');
        $this->assertEquals(88.0, (float) $oferta->cost);
        $this->assertEquals(
            2,
            (int) $oferta->moneda_id,
            'La columna "moneda" traía "dolar": la oferta capturada en el punto A tiene que quedar moneda_id=2, no el default (1 = Peso).'
        );
    }

    /**
     * Bloqueante de revisión de merge: punto B de la importación (ProcessRow.php,
     * la fila salteada por pertenecer a OTRO proveedor, matcheada por bar_code --
     * mismo mecanismo que punto_b_... del archivo 3) con una fila cuya columna
     * "moneda" trae "pesos" -- regresión: sin flag de dólar, tiene que seguir
     * quedando moneda_id=1, el mismo default de siempre. Recorre el camino real:
     * POST a /api/article/excel/import, no Reflection ni fila sembrada a mano.
     *
     * @group sugerencias-compras
     * @test
     */
    public function importacion_punto_b_fila_de_otro_proveedor_en_pesos_deja_moneda_id_1()
    {
        $provider_dueno   = $this->crear_proveedor('import-b-dueno');
        $provider_importa = $this->crear_proveedor('import-b-importa');

        $articulo_de_otro = Article::create([
            'name'        => 'Articulo P8 otro proveedor import B',
            'user_id'     => $this->comercio->id,
            'provider_id' => $provider_dueno->id,
            'bar_code'    => '7799088002',
        ]);

        $this->importar_filas_p8([
            ['7799088002', null, null, 'Nombre que no tiene que pisar nada B', 77.0, 150.0, 3.0, '21', 'pesos'],
        ], [
            'provider_id' => $provider_importa->id,
        ]);

        $oferta = ProviderPriceOffer::where('article_id', $articulo_de_otro->id)
            ->where('provider_id', $provider_importa->id)
            ->where('origen', 'importacion')
            ->first();

        $this->assertNotNull($oferta, 'El punto B tiene que dejar la oferta del proveedor que intentó importar.');
        $this->assertEquals(77.0, (float) $oferta->cost);
        $this->assertEquals(
            1,
            (int) $oferta->moneda_id,
            'La columna "moneda" traía "pesos": sin flag de dólar, la oferta capturada en el punto B tiene que quedar en el default moneda_id=1.'
        );
    }

    /**
     * Bloqueante de revisión de merge: el volcado de la migración de dedupe
     * (paso 1, volcar_duplicadas_al_historico()) fijaba moneda_id=1 SIEMPRE, sin
     * mirar articles.cost_in_dollars -- el mismo dato que ya usan los otros dos
     * escritores del histórico, cubiertos arriba en este archivo. Un artículo con
     * cost_in_dollars=1 y una duplicada descartable en su pivot tiene que quedar
     * volcado con moneda_id=2.
     *
     * Mismo protocolo de aislamiento que Dedupe_del_pivot_Test (archivo 2, que
     * prueba esta misma migración desde otros ángulos): el índice único
     * uniq_article_provider no deja sembrar duplicados con él puesto, así que se
     * dropea, se inserta la duplicada y se llama a up() de la migración real en
     * la misma respiración -- up() termina recreándolo como último paso -- todo
     * en un try/finally que repone el índice pase lo que pase. El DROP/ADD INDEX
     * (DDL) hace COMMIT implícito en InnoDB: DatabaseTransactions no revierte
     * nada de lo que se escriba después de esa línea, incluido el $this->comercio
     * que setUp() ya había insertado en la MISMA transacción -- por eso el
     * finally también lo borra a mano, no solo el pivot/histórico/índice.
     *
     * @group sugerencias-compras
     * @test
     */
    public function migracion_dedupe_del_pivot_etiqueta_moneda_id_2_cuando_el_articulo_tiene_cost_in_dollars()
    {
        $indice = 'uniq_article_provider';
        $archivo_migracion = base_path('database/migrations/2026_08_16_100500_dedup_and_index_article_provider_table.php');
        $this->assertFileExists($archivo_migracion);

        // La clase de la migración no tiene namespace (vive en el global): solo
        // hace falta requerirla una vez por proceso de phpunit.
        if (!class_exists('DedupAndIndexArticleProviderTable', false)) {
            require_once $archivo_migracion;
        }

        $provider = $this->crear_proveedor('migracion-moneda');
        $articulo = Article::create([
            'name'            => 'Articulo P8 migracion moneda',
            'user_id'         => $this->comercio->id,
            'cost_in_dollars' => 1,
        ]);

        $indice_existia_antes = $this->indice_existe('article_provider', $indice);

        $fecha_vieja = now()->subDays(2);
        $fecha_hoy   = now();

        try {
            if ($indice_existia_antes) {
                Schema::table('article_provider', function ($table) use ($indice) {
                    $table->dropUnique($indice);
                });
            }

            // Par (articulo, provider): 2 filas -- sobrevive la de id más alto
            // (keeper), la otra es la descartable que se vuelca al histórico.
            $id_descartable = DB::table('article_provider')->insertGetId([
                'article_id' => $articulo->id, 'provider_id' => $provider->id,
                'cost' => 65, 'created_at' => $fecha_vieja, 'updated_at' => $fecha_vieja,
            ]);
            $id_keeper = DB::table('article_provider')->insertGetId([
                'article_id' => $articulo->id, 'provider_id' => $provider->id,
                'cost' => 70, 'created_at' => $fecha_hoy, 'updated_at' => $fecha_hoy,
            ]);

            // La migración real, end-to-end: vuelca, borra, verifica y recrea el índice.
            $migracion = new \DedupAndIndexArticleProviderTable();
            $migracion->up();

            $filas_pivot = DB::table('article_provider')
                ->where('article_id', $articulo->id)
                ->where('provider_id', $provider->id)
                ->get();

            $this->assertCount(1, $filas_pivot, 'Después del dedupe tiene que quedar 1 sola fila del par.');
            $this->assertEquals($id_keeper, $filas_pivot[0]->id, 'Tiene que sobrevivir la fila de id más alto.');

            $historico = ProviderPriceOffer::where('article_id', $articulo->id)
                ->where('provider_id', $provider->id)
                ->where('origen', 'pivot_dedupe')
                ->first();

            $this->assertNotNull($historico, 'La fila descartable tiene que quedar volcada al histórico.');
            // $id_descartable (65, fecha vieja) es la que perdió el dedupe -- $id_keeper
            // (70, fecha hoy) es la que sigue viva en el pivot, ya verificado arriba.
            $this->assertEquals(65.0, (float) $historico->cost);
            $this->assertEquals(
                2,
                (int) $historico->moneda_id,
                'El artículo tiene cost_in_dollars=1: el volcado tiene que etiquetar moneda_id=2, no el default (1 = Peso).'
            );

            $this->assertTrue($this->indice_existe('article_provider', $indice), 'La migración tiene que recrear el índice como último paso.');
        } finally {
            DB::table('article_provider')
                ->where('article_id', $articulo->id)
                ->where('provider_id', $provider->id)
                ->delete();
            DB::table('provider_price_offers')
                ->where('article_id', $articulo->id)
                ->where('provider_id', $provider->id)
                ->delete();

            // Red de seguridad: si algo de arriba explotó ANTES de que up() llegara
            // a recrear el índice, se repone acá para no dejar la base compartida
            // del slot sin él.
            if (!$this->indice_existe('article_provider', $indice)) {
                Schema::table('article_provider', function ($table) use ($indice) {
                    $table->unique(['article_id', 'provider_id'], $indice);
                });
            }

            $articulo->forceDelete();
            $provider->forceDelete();
            // El DDL de arriba comitió también el INSERT de $this->comercio que
            // setUp() había hecho en la misma transacción: sin este delete manual
            // quedaría huérfano en la base compartida del slot.
            $this->comercio->delete();
        }
    }
}
