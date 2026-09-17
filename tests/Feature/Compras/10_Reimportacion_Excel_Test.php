<?php

namespace Tests\Feature\Compras;

use App\Jobs\ProcessProviderOrderArticleImport;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\ProviderOrder;
use App\Models\StockMovement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * Tests de REIMPORTACION de Excel sobre una MISMA compra a proveedor (misión
 * `import-excel-compras-chunks`, pedido de Lucas del 17/9/2026).
 *
 * El pedido, textual: "quiero que un usuario pueda importar las veces que necesite un Excel a una
 * misma compra... puede importar por primera vez el Excel para indicar los artículos y sus
 * cantidades pedidas, después vuelva a importar otro Excel para actualizar algunos artículos y la
 * cantidad pedida, y después importe un Excel para indicar las unidades recibidas". O sea: la
 * importación no es un evento único de creación, es una operación REPETIBLE sobre la misma compra,
 * y cada pasada tiene que dejar el estado correcto sin arrastrar basura de la anterior.
 *
 * Las columnas del pivot en juego son dos y no hay que confundirlas: `amount` es la cantidad
 * PEDIDA y `received` la cantidad RECIBIDA (existe además una columna `amount_pedida` en el
 * esquema que el importador nunca escribe — no participa de nada de esto).
 *
 * Todo lo que se verifica acá se lee DIRECTO de `article_provider_order` con el query builder, no
 * por la relación de Eloquent: la relación cachea y lo que está en discusión es justamente si la
 * fila del pivot se actualizó o se duplicó.
 *
 * Mismo andamiaje que `9_Import_Excel_Chunks_Test`: `Bus::fake()` para capturar el job que
 * despacha el endpoint real y correrlo a mano acá, sin levantar `queue:work`. Los helpers de
 * generación de Excel están duplicados a propósito en este archivo (no extraídos a
 * `ComprasTestCase`) porque este archivo necesita una planilla con las DOS columnas de cantidad a
 * la vez —que es exactamente el caso que Lucas describe— y tocar el andamiaje compartido pondría
 * en riesgo una suite que hoy pasa.
 */
class Reimportacion_Excel_Test extends ComprasTestCase
{
    /** Tolerancia para comparar cantidades leídas de la base (vienen como string decimal). */
    const DELTA = 0.001;

    /** @var string Prefijo único por corrida, para que el artículo "nuevo" no choque entre corridas. */
    protected $prefijo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefijo = 'REIMP' . substr(str_replace('.', '', (string) microtime(true)), -8) . '-';

        /*
         * `ProviderOrderController::import_excel_articles()` corta con 409 si el usuario tiene
         * CUALQUIER ImportHistory en 'en_preparacion'/'en_proceso'. Una corrida anterior abortada
         * (Ctrl+C, timeout del worker) deja esas filas colgadas en la base del slot y haría que la
         * PRIMERA importación de este archivo devuelva 409 por un motivo que no tiene nada que ver
         * con lo que se está midiendo. Se neutralizan acá dentro de la transacción del test (se
         * revierte solo al terminar), para que un rojo de este archivo sea siempre un rojo real.
         */
        ImportHistory::where('user_id', auth()->id())
                        ->whereIn('status', ['en_preparacion', 'en_proceso'])
                        ->update(['status' => 'fallo']);
    }

    /**
     * Arma un .xlsx con las SIETE columnas del modal de importación de compras, incluidas las dos
     * de cantidad a la vez (`cantidad` = pedida, `cantidad_recibida` = recibida). Una fila puede
     * traer una, la otra, o las dos — que es literalmente el escenario que describe el pedido.
     *
     * El matcheo de artículos es por NOMBRE (se dejan vacíos código de barras y de proveedor):
     * es el único identificador que el fixture de testing garantiza para todos sus artículos, y
     * es el mismo criterio que ya usa `9_Import_Excel_Chunks_Test::generar_excel_de_recibido()`.
     *
     * @param array<int,array<string,mixed>> $filas Claves: nombre, cantidad, cantidad_recibida, costo.
     * @return string Ruta del archivo temporal generado.
     */
    protected function generar_excel(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('reimport_compras_test_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);

        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'codigo_de_proveedor', 'nombre', 'cantidad', 'cantidad_recibida', 'costo', 'notas',
        ]));

        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray([
                null,
                null,
                $fila['nombre'],
                array_key_exists('cantidad', $fila) ? $fila['cantidad'] : null,
                array_key_exists('cantidad_recibida', $fila) ? $fila['cantidad_recibida'] : null,
                array_key_exists('costo', $fila) ? $fila['costo'] : null,
                null,
            ]));
        }

        $writer->close();

        return $ruta;
    }

    /**
     * Mapeo de columnas tal como lo manda el front (`prop_<columna>` 1-based). Se mapean SIEMPRE
     * las siete, también en la importación de recibidos: una celda vacía de `cantidad` tiene que
     * resolver en "no toques la pedida", no en "poné null".
     *
     * @return array<string,int>
     */
    protected function columnas()
    {
        return [
            'prop_codigo_de_barras'    => 1,
            'prop_codigo_de_proveedor' => 2,
            'prop_nombre'              => 3,
            'prop_cantidad'            => 4,
            'prop_cantidad_recibida'   => 5,
            'prop_costo'               => 6,
            'prop_notas'               => 7,
        ];
    }

    /**
     * Crea una ProviderOrder vacía (sin líneas) por el endpoint real, con las neutralizaciones
     * habituales de la suite (RRII, sin bonificaciones de catálogo) para que el pipeline de
     * negocio no le agregue nada por su cuenta a lo que traiga el Excel.
     *
     * @param array<string,mixed> $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_orden_vacia($overrides = [])
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $payload = $this->payload_compra(array_merge(['articles' => []], $overrides));

        $response = $this->postJson('api/provider-order', $payload);
        $response->assertStatus(201);

        return ProviderOrder::find($response->json('model.id'));
    }

    /**
     * Hace UNA importación completa de punta a punta: pega al endpoint real, agarra el job que se
     * despachó y lo corre acá mismo. Devuelve recién cuando el pipeline de negocio
     * (attach_articles → check_modo_facturacion → procesar_pedido) ya terminó, para que el test
     * pueda mirar el pivot.
     *
     * @param \App\Models\ProviderOrder $provider_order
     * @param array<int,array<string,mixed>> $filas
     * @param string $import_type 'pedido' o 'recibido'
     * @param int $overwrite_articles 1 = sync() (saca de la compra lo que no vino), 0 = syncWithoutDetaching()
     * @return \App\Models\ImportStatus El tracking de ESTA importación, ya cerrado por el job.
     */
    protected function importar($provider_order, array $filas, $import_type = 'pedido', $overwrite_articles = 0)
    {
        Bus::fake([ProcessProviderOrderArticleImport::class]);

        $archivo = $this->generar_excel($filas);

        $data = array_merge([
            'models'             => new UploadedFile($archivo, basename($archivo), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'provider_order_id'  => $provider_order->id,
            'start_row'          => 2,
            'finish_row'         => 1 + count($filas),
            'import_type'        => $import_type,
            'overwrite_articles' => $overwrite_articles,
        ], $this->columnas());

        $response = $this->post('api/provider-order/excel/import', $data);

        $response->assertStatus(
            200,
            'El endpoint de importación tiene que aceptar una importación sobre una compra que YA '.
            'tiene artículos: importar varias veces a la misma compra es el pedido entero.'
        );

        $job = Bus::dispatched(ProcessProviderOrderArticleImport::class)->first();

        $this->assertNotNull($job, 'El endpoint tiene que despachar el job de importación.');

        $job->handle();

        /*
         * Guard contra el test que pasa sin probar nada: si la segunda pasada no llegara a
         * resolver ninguna fila (nombre que no matchea, hoja equivocada, rango mal calculado), el
         * pivot quedaría igual y el stock tampoco se movería — y los tests de idempotencia darían
         * verde midiendo una importación que no hizo nada. `completado` + la cantidad de filas
         * resueltas confirman que el pipeline corrió de verdad sobre TODAS las filas.
         */
        $import_status = ImportStatus::where('provider_order_id', $provider_order->id)
                                        ->orderBy('id', 'desc')
                                        ->first();

        $this->assertNotNull($import_status, 'El controller tiene que dejar el ImportStatus de esta importación.');
        $this->assertSame('completado', $import_status->status, 'La importación tiene que terminar en completado.');

        $this->assertSame(
            count($filas),
            (int) $import_status->created_models + (int) $import_status->updated_models,
            'Todas las filas del Excel tienen que resolver en un artículo (creado o ya existente): si no, '.
            'lo que este test está midiendo es una importación que no procesó nada.'
        );

        return $import_status;
    }

    /**
     * Lee la fila cruda del pivot de esta compra para un artículo, sin pasar por Eloquent.
     *
     * @param \App\Models\ProviderOrder $provider_order
     * @param int $article_id
     * @return object|null
     */
    protected function pivot_de($provider_order, $article_id)
    {
        return DB::table('article_provider_order')
                    ->where('provider_order_id', $provider_order->id)
                    ->where('article_id', $article_id)
                    ->first();
    }

    /**
     * Cuenta las filas del pivot de esta compra. Es el contador que delata el bug clásico de la
     * reimportación: en vez de actualizar el renglón existente, agregar uno nuevo al lado.
     *
     * @param \App\Models\ProviderOrder $provider_order
     * @return int
     */
    protected function filas_de_pivot($provider_order)
    {
        return (int) DB::table('article_provider_order')
                        ->where('provider_order_id', $provider_order->id)
                        ->count();
    }

    /**
     * Marca de agua de `stock_movements.id` para contar movimientos generados DESPUÉS de este
     * punto. Se usa el id y no `created_at` a propósito: `created_at` tiene resolución de un
     * segundo y dos importaciones seguidas caen en el mismo segundo, lo que daría falsos positivos
     * (o falsos negativos) según de qué lado del filtro quede el movimiento.
     *
     * @return int
     */
    protected function watermark_stock()
    {
        return (int) StockMovement::max('id');
    }

    /**
     * Movimientos de stock de esta compra generados por encima de la marca de agua.
     *
     * @param \App\Models\ProviderOrder $provider_order
     * @param int $watermark
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos_desde($provider_order, $watermark)
    {
        return StockMovement::where('provider_order_id', $provider_order->id)
                                ->where('id', '>', $watermark)
                                ->orderBy('id')
                                ->get();
    }

    /**
     * Test 1 — el ciclo completo que describió Lucas: pedido → corrección del pedido → recibidos,
     * todo sobre LA MISMA compra.
     *
     * Invariantes que protege, los cuatro de una sola pasada porque son estados encadenados del
     * mismo pivot y separarlos en cuatro tests obligaría a repetir las tres importaciones:
     *
     *  - Paso 1: la primera importación deja cantidad PEDIDA y la recibida sin tocar (null),
     *    porque el Excel de pedido no dice nada de recibidos.
     *  - Paso 2: la cantidad pedida REEMPLAZA, no suma (decisión explícita de Lucas, 17/9/2026):
     *    Pinza tenía 4, el Excel nuevo dice 10, tiene que quedar en 10 y NO en 14.
     *  - Paso 2: un artículo que estaba en la compra y no vino en el Excel nuevo (Alicate)
     *    SOBREVIVE, porque `overwrite_articles = false` es el default y usa
     *    `syncWithoutDetaching()`.
     *  - Paso 2: no se duplican renglones — 4 artículos en la compra son 4 filas de pivot, no 7.
     *  - Paso 3: importar recibidos NO pisa la cantidad pedida. `amount` sigue valiendo lo del
     *    paso 2 y `received` pasa a valer lo del Excel nuevo. Es el caso donde más duele
     *    equivocarse: si la importación de recibidos borra lo pedido, la compra pierde el dato de
     *    qué se había pedido y el diff pedido/recibido queda sin sentido.
     *
     * `update_stock`/`update_prices` apagados a propósito: acá se mide el PIVOT, no el stock ni el
     * costeo. El stock tiene sus propios tests más abajo.
     *
     * @group compras
     * @test
     */
    public function el_ciclo_pedido_correccion_y_recibido_deja_cada_pivot_como_corresponde()
    {
        $pinza    = $this->articulo('Pinza');
        $alicate  = $this->articulo('Alicate');
        $cuchilla = $this->articulo('Cuchilla');

        $snapshots = [
            $pinza->id    => $this->snapshot_articulo($pinza),
            $alicate->id  => $this->snapshot_articulo($alicate),
            $cuchilla->id => $this->snapshot_articulo($cuchilla),
        ];

        $nombre_nuevo = $this->prefijo . 'Taladro';

        try {
            $provider_order = $this->crear_orden_vacia([
                'update_stock'  => 0,
                'update_prices' => 0,
            ]);

            // ---------- Paso 1: el pedido original ----------
            $this->importar($provider_order, [
                ['nombre' => $pinza->name,    'cantidad' => 4, 'costo' => 1000],
                ['nombre' => $alicate->name,  'cantidad' => 6, 'costo' => 300],
                ['nombre' => $cuchilla->name, 'cantidad' => 2, 'costo' => 500],
            ], 'pedido');

            $this->assertSame(3, $this->filas_de_pivot($provider_order), 'Paso 1: la compra tiene que quedar con 3 renglones.');

            $this->assertEqualsWithDelta(4, (float) $this->pivot_de($provider_order, $pinza->id)->amount, self::DELTA, 'Paso 1: Pinza pedida = 4.');
            $this->assertEqualsWithDelta(6, (float) $this->pivot_de($provider_order, $alicate->id)->amount, self::DELTA, 'Paso 1: Alicate pedida = 6.');
            $this->assertEqualsWithDelta(2, (float) $this->pivot_de($provider_order, $cuchilla->id)->amount, self::DELTA, 'Paso 1: Cuchilla pedida = 2.');

            $this->assertNull(
                $this->pivot_de($provider_order, $pinza->id)->received,
                'Paso 1: un Excel de PEDIDO no dice nada de recibidos — la cantidad recibida tiene que quedar vacía (null), no 0.'
            );

            // ---------- Paso 2: se corrige el pedido y se agrega un artículo nuevo ----------
            // Pinza pasa de 4 a 10, Cuchilla repite su 2, aparece un artículo que no existía, y
            // Alicate NO viene en esta planilla (tiene que sobrevivir intacto).
            $this->importar($provider_order, [
                ['nombre' => $pinza->name,    'cantidad' => 10, 'costo' => 1000],
                ['nombre' => $cuchilla->name, 'cantidad' => 2,  'costo' => 500],
                ['nombre' => $nombre_nuevo,   'cantidad' => 5,  'costo' => 700],
            ], 'pedido');

            $articulo_nuevo = $this->articulo($nombre_nuevo);

            $this->assertNotNull(
                $articulo_nuevo,
                'Paso 2: una reimportación de pedido con un artículo que no existía tiene que crearlo, igual que la primera.'
            );

            $this->assertSame(
                4,
                $this->filas_de_pivot($provider_order),
                'Paso 2: la compra tiene que quedar con 4 renglones (3 viejos + 1 nuevo). Si da 6 o 7, la '.
                'reimportación está AGREGANDO renglones en vez de actualizar los existentes.'
            );

            $this->assertEqualsWithDelta(
                10,
                (float) $this->pivot_de($provider_order, $pinza->id)->amount,
                self::DELTA,
                'Paso 2: la cantidad pedida REEMPLAZA, no suma. Pinza tenía 4 y el Excel nuevo dice 10: '.
                'tiene que quedar en 10, nunca en 14.'
            );

            $this->assertEqualsWithDelta(
                6,
                (float) $this->pivot_de($provider_order, $alicate->id)->amount,
                self::DELTA,
                'Paso 2: con overwrite_articles = false, un artículo que estaba en la compra y no vino en '.
                'el Excel nuevo tiene que sobrevivir con su cantidad intacta.'
            );

            $this->assertEqualsWithDelta(5, (float) $this->pivot_de($provider_order, $articulo_nuevo->id)->amount, self::DELTA, 'Paso 2: el artículo nuevo entra con cantidad 5.');

            // ---------- Paso 3: el Excel de recibidos ----------
            // Cuchilla con 0 recibido a propósito: "pedí 2, no me llegó ninguna" es un dato real y
            // distinto de "no cargué nada" (0 es falsy en PHP, es el bug clásico de este módulo).
            $this->importar($provider_order, [
                ['nombre' => $pinza->name,    'cantidad_recibida' => 8],
                ['nombre' => $alicate->name,  'cantidad_recibida' => 6],
                ['nombre' => $cuchilla->name, 'cantidad_recibida' => 0],
            ], 'recibido');

            $this->assertSame(
                4,
                $this->filas_de_pivot($provider_order),
                'Paso 3: importar recibidos no puede agregar ni sacar renglones de la compra.'
            );

            $pivot_pinza = $this->pivot_de($provider_order, $pinza->id);

            $this->assertEqualsWithDelta(
                10,
                (float) $pivot_pinza->amount,
                self::DELTA,
                'Paso 3: importar RECIBIDOS no pisa la cantidad PEDIDA. Pinza tiene que seguir con los 10 '.
                'del paso 2. Si quedó en 8 (o en null), la importación de recibidos está sobrescribiendo '.
                'la columna equivocada y la compra pierde el dato de qué se pidió.'
            );

            $this->assertEqualsWithDelta(8, (float) $pivot_pinza->received, self::DELTA, 'Paso 3: Pinza recibida = 8.');

            $pivot_alicate = $this->pivot_de($provider_order, $alicate->id);
            $this->assertEqualsWithDelta(6, (float) $pivot_alicate->amount, self::DELTA, 'Paso 3: Alicate mantiene su pedida de 6.');
            $this->assertEqualsWithDelta(6, (float) $pivot_alicate->received, self::DELTA, 'Paso 3: Alicate recibida = 6.');

            $pivot_cuchilla = $this->pivot_de($provider_order, $cuchilla->id);
            $this->assertEqualsWithDelta(2, (float) $pivot_cuchilla->amount, self::DELTA, 'Paso 3: Cuchilla mantiene su pedida de 2.');
            $this->assertNotNull(
                $pivot_cuchilla->received,
                'Paso 3: "recibí 0" es un dato cargado, no un campo vacío. Si queda null, el importador '.
                'está tratando el 0 como "no vino nada en la celda" (falsy de PHP).'
            );
            $this->assertEqualsWithDelta(0, (float) $pivot_cuchilla->received, self::DELTA, 'Paso 3: Cuchilla recibida = 0.');

            $pivot_nuevo = $this->pivot_de($provider_order, $articulo_nuevo->id);
            $this->assertEqualsWithDelta(
                5,
                (float) $pivot_nuevo->amount,
                self::DELTA,
                'Paso 3: el artículo que no vino en el Excel de recibidos conserva su cantidad pedida.'
            );
            $this->assertNull(
                $pivot_nuevo->received,
                'Paso 3: el artículo que no vino en el Excel de recibidos conserva su recibida vacía.'
            );
        } finally {
            foreach ($snapshots as $article_id => $snapshot) {
                $this->restaurar_articulo(\App\Models\Article::find($article_id), $snapshot);
            }
        }
    }

    /**
     * Test 2 — IDEMPOTENCIA DURA: importar DOS VECES el mismo archivo deja exactamente el mismo
     * estado.
     *
     * Es el invariante más importante del pedido y el más fácil de romper sin que nadie lo note:
     * el usuario duda de si la importación entró, vuelve a subir la misma planilla, y el sistema
     * le duplica el stock. La cantidad pedida se ve enseguida (queda el doble); el movimiento de
     * stock duplicado no se ve nunca hasta que alguien cuenta el inventario a mano.
     *
     * La idempotencia del stock no la da el pivot: la da
     * `NewProviderOrderHelper::set_ultimos_articulos_recividos()`, que corre EN EL CONSTRUCTOR del
     * helper (antes de `attach_articles()`), guarda la cantidad real vieja de cada artículo, y
     * después `save_stock_movement()` se la resta al mover. Si la segunda pasada no encuentra esa
     * base, mueve los 4 de nuevo.
     *
     * Acá `update_stock` está ENCENDIDO a propósito: es la única configuración en la que el
     * movimiento duplicado puede existir.
     *
     * @group compras
     * @test
     */
    public function reimportar_el_mismo_archivo_no_duplica_ni_la_cantidad_ni_el_movimiento_de_stock()
    {
        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $provider_order = $this->crear_orden_vacia([
                'update_stock'  => 1,
                'update_prices' => 0,
            ]);

            // Las mismas filas las dos veces: dos archivos generados del MISMO array son
            // byte-equivalentes en lo que el importador lee. No se reusa la ruta del primero
            // porque `UploadedFile::storeAs()` MUEVE el temporal y la segunda subida no lo
            // encontraría.
            $filas = [
                ['nombre' => $pinza->name, 'cantidad' => 4, 'costo' => 1000],
            ];

            $watermark_primera = $this->watermark_stock();

            $this->importar($provider_order, $filas, 'pedido');

            $movimientos_primera = $this->movimientos_desde($provider_order, $watermark_primera);

            $this->assertCount(
                1,
                $movimientos_primera,
                'La primera importación con update_stock encendido tiene que generar UN movimiento de stock.'
            );
            $this->assertEqualsWithDelta(4, (float) $movimientos_primera->first()->amount, self::DELTA, 'El movimiento de la primera importación mueve las 4 unidades pedidas.');

            $pinza->refresh();
            $stock_despues_de_la_primera = (float) $pinza->stock;

            // ---------- La misma planilla, otra vez ----------
            $watermark_segunda = $this->watermark_stock();

            $import_status_segunda = $this->importar($provider_order, $filas, 'pedido');

            $this->assertSame(
                1,
                (int) $import_status_segunda->updated_models,
                'La segunda pasada tiene que RESOLVER el artículo ya existente (no crearlo de nuevo). Sin '.
                'esta aserción, un importador que no matchea nada dejaría el test de idempotencia en verde '.
                'sin haber probado nada.'
            );

            $this->assertSame(
                1,
                $this->filas_de_pivot($provider_order),
                'Reimportar el mismo archivo no puede agregar un segundo renglón para el mismo artículo.'
            );

            $this->assertEqualsWithDelta(
                4,
                (float) $this->pivot_de($provider_order, $pinza->id)->amount,
                self::DELTA,
                'Reimportar el mismo archivo tiene que dejar la cantidad pedida en 4, no en 8: la cantidad '.
                'se reemplaza, no se acumula.'
            );

            $movimientos_segunda = $this->movimientos_desde($provider_order, $watermark_segunda);

            $this->assertCount(
                0,
                $movimientos_segunda,
                'Reimportar el MISMO archivo no puede generar un segundo movimiento de stock: la cantidad '.
                'real no cambió, así que el delta a mover es 0. Si acá hay un movimiento, el stock del '.
                'artículo quedó inflado y nadie se entera hasta contar el inventario a mano.'
            );

            $pinza->refresh();

            $this->assertEqualsWithDelta(
                $stock_despues_de_la_primera,
                (float) $pinza->stock,
                self::DELTA,
                'El stock del artículo tiene que quedar idéntico después de la segunda importación del mismo archivo.'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }

    /**
     * Test 3 — el ciclo completo del pedido de Lucas, esta vez con `update_stock` ENCENDIDO: el
     * stock del artículo tiene que seguir siempre a la última cantidad real informada, nunca a la
     * suma de las importaciones.
     *
     * Es la combinación de los dos tests anteriores y el escenario que el usuario va a vivir de
     * verdad: carga el pedido, lo corrige, y después informa lo que efectivamente llegó. Si cada
     * pasada sumara en vez de corregir, el artículo terminaría con 4 + 10 + 8 = 22 unidades de más
     * cuando en la realidad entraron 8.
     *
     * Se verifica el DELTA contra el stock inicial (no un valor absoluto) porque el fixture es
     * compartido y el stock de Pinza puede venir de cualquier valor previo.
     *
     * @group compras
     * @test
     */
    public function el_ciclo_completo_con_stock_encendido_deja_el_stock_en_la_ultima_cantidad_real()
    {
        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $provider_order = $this->crear_orden_vacia([
                'update_stock'  => 1,
                'update_prices' => 0,
            ]);

            $pinza->refresh();
            $stock_inicial = (float) $pinza->stock;

            // Paso 1: se pidieron 4.
            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad' => 4, 'costo' => 1000],
            ], 'pedido');

            $pinza->refresh();
            $this->assertEqualsWithDelta(
                $stock_inicial + 4,
                (float) $pinza->stock,
                self::DELTA,
                'Paso 1: el stock sube por las 4 unidades pedidas.'
            );

            // Paso 2: se corrige el pedido a 10. El delta que falta es 6, no 10.
            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad' => 10, 'costo' => 1000],
            ], 'pedido');

            $pinza->refresh();
            $this->assertEqualsWithDelta(
                $stock_inicial + 10,
                (float) $pinza->stock,
                self::DELTA,
                'Paso 2: corregir la cantidad pedida de 4 a 10 tiene que dejar el stock en +10 sobre el '.
                'inicial, no en +14. El movimiento nuevo mueve solo el delta (6) contra lo que ya se había '.
                'movido.'
            );

            // Paso 3: llegaron 8 de las 10 pedidas. El stock tiene que BAJAR 2.
            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad_recibida' => 8],
            ], 'recibido');

            $pinza->refresh();
            $this->assertEqualsWithDelta(
                $stock_inicial + 8,
                (float) $pinza->stock,
                self::DELTA,
                'Paso 3: informar que llegaron 8 de las 10 pedidas tiene que dejar el stock en +8 sobre el '.
                'inicial (corrige hacia abajo las 2 que no llegaron), no en +18.'
            );

            $pivot = $this->pivot_de($provider_order, $pinza->id);
            $this->assertEqualsWithDelta(10, (float) $pivot->amount, self::DELTA, 'Paso 3: la cantidad pedida sigue siendo 10.');
            $this->assertEqualsWithDelta(8, (float) $pivot->received, self::DELTA, 'Paso 3: la cantidad recibida es 8.');
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }

    /**
     * Test 4 — la OTRA rama del modo de importación: con `overwrite_articles = true`,
     * `attach_articles()` usa `sync()` en vez de `syncWithoutDetaching()`, y eso DETACHA de la
     * compra a todo artículo que no venga en el Excel nuevo.
     *
     * Existe como test propio (y no como una aserción más del test 1) porque es un comportamiento
     * destructivo: el usuario que tilda "reemplazar artículos" está pidiendo que la compra quede
     * EXACTAMENTE como la planilla nueva. Si esa rama dejara de detachar, el tilde no haría nada y
     * nadie lo notaría hasta auditar una compra con renglones de más.
     *
     * @group compras
     * @test
     */
    public function con_overwrite_articles_encendido_lo_que_no_vino_en_el_excel_sale_de_la_compra()
    {
        $pinza   = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');

        $snapshot_pinza   = $this->snapshot_articulo($pinza);
        $snapshot_alicate = $this->snapshot_articulo($alicate);

        try {
            $provider_order = $this->crear_orden_vacia([
                'update_stock'  => 0,
                'update_prices' => 0,
            ]);

            $this->importar($provider_order, [
                ['nombre' => $pinza->name,   'cantidad' => 4, 'costo' => 1000],
                ['nombre' => $alicate->name, 'cantidad' => 6, 'costo' => 300],
            ], 'pedido', 0);

            $this->assertSame(2, $this->filas_de_pivot($provider_order), 'Precondición: la compra arranca con los dos artículos.');

            // Segunda planilla SIN Alicate, esta vez con el tilde de reemplazar.
            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad' => 10, 'costo' => 1000],
            ], 'pedido', 1);

            $this->assertSame(
                1,
                $this->filas_de_pivot($provider_order),
                'Con overwrite_articles = true la compra tiene que quedar con EXACTAMENTE lo que trae el Excel nuevo.'
            );

            $this->assertNull(
                $this->pivot_de($provider_order, $alicate->id),
                'Con overwrite_articles = true, el artículo que no vino en el Excel nuevo tiene que salir de la compra.'
            );

            $this->assertEqualsWithDelta(
                10,
                (float) $this->pivot_de($provider_order, $pinza->id)->amount,
                self::DELTA,
                'Con overwrite_articles = true el artículo que sí vino queda con la cantidad nueva.'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot_pinza);
            $this->restaurar_articulo($alicate, $snapshot_alicate);
        }
    }

    /**
     * Test 5 — con `update_stock` APAGADO no se mueve stock NUNCA, ni en la primera importación ni
     * en ninguna reimportación (tampoco en la de recibidos, que es la que más "pinta" de movimiento
     * de mercadería).
     *
     * `NewProviderOrderHelper::update_article()` solo llama a `update_stock()` si
     * `$provider_order->update_stock` está encendido. El invariante importa porque una compra con
     * el tilde apagado es justamente la que el usuario carga "para tenerla anotada" — si igual
     * moviera stock, el inventario se ensucia en la operación donde el usuario cree que no pasa
     * nada.
     *
     * @group compras
     * @test
     */
    public function con_update_stock_apagado_ninguna_importacion_mueve_stock()
    {
        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $provider_order = $this->crear_orden_vacia([
                'update_stock'  => 0,
                'update_prices' => 0,
            ]);

            $pinza->refresh();
            $stock_inicial = (float) $pinza->stock;

            $watermark = $this->watermark_stock();

            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad' => 4, 'costo' => 1000],
            ], 'pedido');

            $this->assertCount(
                0,
                $this->movimientos_desde($provider_order, $watermark),
                'Primera importación con update_stock apagado: no puede haber ningún movimiento de stock.'
            );

            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad' => 10, 'costo' => 1000],
            ], 'pedido');

            $this->assertCount(
                0,
                $this->movimientos_desde($provider_order, $watermark),
                'Reimportación del pedido con update_stock apagado: sigue sin haber movimientos de stock.'
            );

            $this->importar($provider_order, [
                ['nombre' => $pinza->name, 'cantidad_recibida' => 8],
            ], 'recibido');

            $this->assertCount(
                0,
                $this->movimientos_desde($provider_order, $watermark),
                'Importación de RECIBIDOS con update_stock apagado: tampoco mueve stock. Es la que más '.
                'parece un ingreso de mercadería, y es justo donde el tilde apagado tiene que respetarse.'
            );

            $pinza->refresh();

            $this->assertEqualsWithDelta(
                $stock_inicial,
                (float) $pinza->stock,
                self::DELTA,
                'Con update_stock apagado el stock del artículo tiene que quedar exactamente donde estaba.'
            );

            // El pivot sí se actualizó: apagar el stock no apaga la importación.
            $pivot = $this->pivot_de($provider_order, $pinza->id);
            $this->assertEqualsWithDelta(10, (float) $pivot->amount, self::DELTA, 'La cantidad pedida se actualizó igual.');
            $this->assertEqualsWithDelta(8, (float) $pivot->received, self::DELTA, 'La cantidad recibida se actualizó igual.');
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }
}
