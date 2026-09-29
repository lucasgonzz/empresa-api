<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\ProcessProviderOrderArticleImport;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\PriceChange;
use App\Models\ProviderOrder;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * La importación por Excel de una compra, corrida como la corre el worker de la cola: SIN sesión
 * (misión compras-precios-en-lote, 29/9/2026, decisión D3 del plan).
 *
 * El problema: ProcessProviderOrderArticleImport corre en un worker, donde no hay request ni
 * sesión. NewProviderOrderHelper::__construct() toma el dueño con UserHelper::user(), que ahí da
 * null, y el helper revienta más adelante sin guarda (lo documenta ConfirmacionPorTextoIaHelper).
 * Los tests de 9_Import_Excel_Chunks_Test corren el job a mano con el usuario de prueba todavía
 * autenticado, y por eso pasan: nunca ven el worker de verdad.
 *
 * Este test lo reproduce: la importación la dispara un EMPLEADO por el endpoint real (con
 * Bus::fake, el mismo patrón del test 9), y antes de correr el job se le saca la autenticación al
 * proceso, que queda como un worker recién levantado (guard `web`, sin usuario). Lo que tiene que
 * pasar es lo mismo que si la compra se hubiera cargado a mano: la importación se completa, los
 * costos y los precios quedan actualizados, los movimientos de stock quedan a nombre de la cuenta
 * (el dueño) con el empleado como quien los hizo, y los cambios de precio a nombre del empleado.
 */
class Importacion_en_la_cola_sin_sesion_Test extends ComprasTestCase
{
    /**
     * Renglones del Excel: artículos EXISTENTES del fixture (los dos del proveedor Buenos Aires),
     * que el importador encuentra por nombre, con costos distintos de los del fixture para que el
     * precio se mueva.
     *
     * @var array
     */
    const RENGLONES = [
        ['nombre' => 'Pinza',   'cantidad' => 3, 'costo' => 1300],
        ['nombre' => 'Alicate', 'cantidad' => 2, 'costo' => 450],
    ];

    /**
     * @group compras
     * @test
     */
    public function la_importacion_en_el_worker_sin_sesion_se_completa_y_queda_a_nombre_de_quien_importo()
    {
        Bus::fake([ProcessProviderOrderArticleImport::class]);

        $dueno    = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $empleado = $this->empleado($dueno);

        /* Foto de antes de cada artículo del Excel, por nombre: id y precio final. */
        $antes = [];

        foreach (self::RENGLONES as $renglon) {

            $articulo = $this->articulo($renglon['nombre']);

            $this->assertNotNull($articulo, 'Falta el artículo "' . $renglon['nombre'] . '" del fixture.');

            $this->assertNotEquals(
                (float) $renglon['costo'],
                (float) $articulo->cost,
                'El costo del Excel tiene que ser distinto del actual de "' . $renglon['nombre'] . '": si no, el precio no se mueve y el test no prueba nada.'
            );

            $antes[$renglon['nombre']] = [
                'id'          => (int) $articulo->id,
                'final_price' => (float) $articulo->final_price,
            ];
        }

        /* La compra la crea el dueño, vacía: las líneas las trae el Excel. */
        $provider_order = $this->crear_compra_vacia();

        /*
         * La importación la dispara el EMPLEADO. forgetGuards() antes del actingAs: después del
         * request de arriba el guard de Sanctum (RequestGuard) quedó con el dueño cacheado, y sin
         * esto el segundo request se autenticaría como el dueño aunque el actingAs diga otra cosa.
         */
        Auth::forgetGuards();
        $this->actingAs($empleado, 'web');

        $archivo = $this->generar_excel(self::RENGLONES);

        $response = $this->post('api/provider-order/excel/import', array_merge([
            'models'             => new UploadedFile($archivo, basename($archivo), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'provider_order_id'  => $provider_order->id,
            'start_row'          => 2,
            'finish_row'         => 1 + count(self::RENGLONES),
            'import_type'        => 'pedido',
            'overwrite_articles' => 0,
        ], $this->columnas_pedido()));

        $response->assertStatus(200);

        Bus::assertDispatched(ProcessProviderOrderArticleImport::class);

        $job = Bus::dispatched(ProcessProviderOrderArticleImport::class)->first();

        /*
         * EL WORKER. Sin request y sin sesión, con el guard por defecto de config/auth.php (`web`):
         * forgetGuards() tira las instancias de los guards (y con ellas los usuarios que tenían
         * puestos) y shouldUse('web') deshace el cambio a `sanctum` que dejó el middleware del
         * request de arriba. Es lo que ve ProcessProviderOrderArticleImport cuando lo levanta
         * `queue:work`.
         */
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->assertNull(
            UserHelper::user(),
            'Precondición: sin sesión, UserHelper::user() tiene que dar null, como en un worker. Si no da null, este test no reproduce el worker y no prueba nada.'
        );

        /* Para quedarse solo con los cambios de precio que deja ESTA importación. */
        $ultimo_price_change_id = (int) PriceChange::max('id');

        try {

            $job->handle();

        } catch (\Throwable $e) {

            $this->fail(
                'La importación de la compra revienta en el worker sin sesión: ' . get_class($e) . ': '
                . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
            );
        }

        /*
         * El job no puede dejar a nadie autenticado en el worker: el próximo job que tome ese mismo
         * proceso (de este cliente o de otro) quedaría firmado por esta persona.
         */
        $this->assertNull(Auth::user(), 'Después del job el worker tiene que quedar sin usuario autenticado, como estaba.');

        /*
         * De acá en adelante se mide, ya no se reproduce el worker: se vuelve a autenticar al dueño
         * para que la referencia del cálculo (precio_segun_el_calculo()) corra en el mismo contexto
         * que cualquier cálculo del sistema, con la cuenta logueada.
         */
        $this->actingAs($dueno, 'web');

        /* 1. La importación terminó bien. */
        $import_status = ImportStatus::where('provider_order_id', $provider_order->id)->first();

        $import_history = ImportHistory::where('provider_order_id', $provider_order->id)
            ->where('model_name', 'provider_order')
            ->first();

        $this->assertSame('completado', $import_status->status, 'El ImportStatus tiene que quedar completado. Mensaje de error: ' . $import_status->error_message);
        $this->assertSame('terminado', $import_history->status, 'El ImportHistory tiene que quedar terminado. Mensaje de error: ' . $import_history->error_message);

        $this->assertSame(
            count(self::RENGLONES),
            $provider_order->articles()->count(),
            'Cada renglón del Excel tiene que quedar como una línea de la compra.'
        );

        foreach (self::RENGLONES as $renglon) {

            $foto     = $antes[$renglon['nombre']];
            $articulo = Article::find($foto['id']);

            /* 2. Costo y precio final actualizados. */
            $this->assertEqualsWithDelta(
                (float) $renglon['costo'],
                (float) $articulo->cost,
                0.000001,
                'El costo de "' . $renglon['nombre'] . '" tiene que quedar con el del Excel.'
            );

            $this->assertNotEquals(
                $foto['final_price'],
                (float) $articulo->final_price,
                'El precio final de "' . $renglon['nombre'] . '" tiene que haberse recalculado con el costo nuevo.'
            );

            /*
             * Y tiene que ser EL precio que da el cálculo de siempre con lo que el artículo tiene
             * guardado después de la compra: eso distingue "se recalculó con el estado final" de "se
             * movió a cualquier lado". No se compara contra el precio sembrado escalado por los
             * costos: el fixture lo guardó con otra configuración fiscal (sin IVA en el precio) y
             * la de hoy (RRII con usar_condicion_fiscal_en_costeo) sí lo suma.
             */
            $referencia = $this->precio_segun_el_calculo($foto['id'], $dueno);

            $this->assertEqualsWithDelta(
                $referencia['costo_real'],
                (float) $articulo->costo_real,
                0.000001,
                'El costo real de "' . $renglon['nombre'] . '" no es el que da el cálculo con el costo nuevo.'
            );

            $this->assertEqualsWithDelta(
                $referencia['final_price'],
                (float) $articulo->final_price,
                0.001,
                'El precio final de "' . $renglon['nombre'] . '" no es el que da el cálculo con el costo nuevo.'
            );

            /* 3. Cambios de precio a nombre de quien importó (uno solo por artículo: decisión D1). */
            $cambios = PriceChange::where('article_id', $foto['id'])
                                    ->where('id', '>', $ultimo_price_change_id)
                                    ->get();

            $this->assertCount(1, $cambios, 'La compra tiene que dejar un solo cambio de precio para "' . $renglon['nombre'] . '".');

            $this->assertSame(
                (int) $empleado->id,
                (int) $cambios->first()->employee_id,
                'El cambio de precio de "' . $renglon['nombre'] . '" tiene que quedar a nombre del empleado que importó.'
            );

            $this->assertEqualsWithDelta(
                (float) $articulo->final_price,
                (float) $cambios->first()->final_price,
                0.001,
                'El cambio de precio de "' . $renglon['nombre'] . '" tiene que registrar el precio final con el que quedó.'
            );

            /* 4. Movimiento de stock: de la cuenta (el dueño), hecho por el empleado. */
            $movimientos = StockMovement::where('provider_order_id', $provider_order->id)
                                        ->where('article_id', $foto['id'])
                                        ->get();

            $this->assertCount(1, $movimientos, 'La compra tiene que dejar un movimiento de stock para "' . $renglon['nombre'] . '".');

            $movimiento = $movimientos->first();

            $this->assertSame((int) $dueno->id, (int) $movimiento->user_id, 'El movimiento de stock de "' . $renglon['nombre'] . '" tiene que ser de la cuenta del dueño.');
            $this->assertSame((int) $empleado->id, (int) $movimiento->employee_id, 'El movimiento de stock de "' . $renglon['nombre'] . '" tiene que quedar hecho por el empleado que importó.');
            $this->assertEqualsWithDelta((float) $renglon['cantidad'], (float) $movimiento->amount, 0.000001, 'El movimiento de stock de "' . $renglon['nombre'] . '" tiene que mover la cantidad del Excel.');

            /*
             * 5. Historial de proveedores (decisión D2): el "Precio Final" que grabó el movimiento de
             * stock tiene que ser el precio con el que el artículo sale de la compra, no el de antes.
             * La columna es entera: la base redondea el decimal, mitad hacia afuera del cero, igual
             * que round() de PHP.
             */
            $precios_del_historial = DB::table('article_provider')
                                        ->where('article_id', $foto['id'])
                                        ->where('provider_id', $provider_order->provider_id)
                                        ->pluck('price')
                                        ->all();

            $this->assertNotEmpty($precios_del_historial, 'El movimiento de stock tiene que dejar a "' . $renglon['nombre'] . '" en el historial del proveedor.');

            foreach ($precios_del_historial as $precio_del_historial) {

                $this->assertSame(
                    (int) round((float) $articulo->final_price),
                    (int) $precio_del_historial,
                    'El "Precio Final" del historial de proveedores de "' . $renglon['nombre'] . '" tiene que ser el precio con el que quedó después de la compra.'
                );
            }
        }
    }

    /**
     * El costo real y el precio final que el cálculo de siempre (ArticleHelper::setFinalPrice())
     * le da al artículo con lo que tiene guardado ahora, con el dueño de la cuenta.
     *
     * Se corre en modo simulación y ADENTRO de un savepoint que se revierte: con
     * $guardar_cambios = false setFinalPrice() igual escribe algunas cosas (el `price = null;
     * save()` de un artículo con margen, los pivots de listas, un price_change si el precio
     * difiere; ver su docblock), y la referencia no puede ensuciar lo que el test mide.
     *
     * @param  int              $article_id
     * @param  \App\Models\User $dueno
     * @return array ['costo_real' => float|null, 'final_price' => float|null]
     */
    protected function precio_segun_el_calculo($article_id, $dueno)
    {
        DB::beginTransaction();

        try {

            $resultado = ArticleHelper::setFinalPrice(Article::find($article_id), $dueno->id, $dueno, null, false);

        } finally {

            DB::rollBack();
        }

        return [
            'costo_real'  => is_null($resultado['costo_real']) ? null : (float) $resultado['costo_real'],
            'final_price' => is_null($resultado['final_price']) ? null : (float) $resultado['final_price'],
        ];
    }

    /**
     * Crea un empleado de la cuenta del dueño del fixture (el fixture no siembra empleados).
     *
     * @param  \App\Models\User $dueno
     * @return \App\Models\User
     */
    protected function empleado($dueno)
    {
        return User::create([
            'name'         => 'zz Empleado importacion en la cola',
            'company_name' => 'zz Ferreteria importacion en la cola',
            'email'        => 'importacion-cola-' . uniqid('', true) . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
        ]);
    }

    /**
     * Crea una compra vacía (sin líneas) por el endpoint real, con `update_prices` y
     * `update_stock` prendidos (los defaults de payload_compra()) y las mismas neutralizaciones que
     * el resto de la suite: RRII y sin las bonificaciones de catálogo de Buenos Aires, para que la
     * compra no le sume descuentos propios a los artículos y el precio dependa solo del costo.
     *
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra_vacia()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $response = $this->postJson('api/provider-order', $this->payload_compra(['articles' => []]));

        $response->assertStatus(201);

        return ProviderOrder::find($response->json('model.id'));
    }

    /**
     * Arma un .xlsx en modo 'pedido' (codigo_de_barras, codigo_de_proveedor, nombre, cantidad,
     * costo, notas: el orden del modal de importación de compras) con los renglones pedidos, sin
     * códigos, para que el importador los busque por nombre.
     *
     * @param  array $renglones [['nombre' => string, 'cantidad' => float, 'costo' => float], ...]
     * @return string Ruta del archivo temporal.
     */
    protected function generar_excel(array $renglones)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('import_compras_cola_test_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);

        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'codigo_de_proveedor', 'nombre', 'cantidad', 'costo', 'notas',
        ]));

        foreach ($renglones as $renglon) {
            $writer->addRow(WriterEntityFactory::createRowFromArray([
                null,
                null,
                $renglon['nombre'],
                $renglon['cantidad'],
                $renglon['costo'],
                null,
            ]));
        }

        $writer->close();

        return $ruta;
    }

    /**
     * Mapa de columnas del modo 'pedido', como lo manda el modal de importación.
     *
     * @return array
     */
    protected function columnas_pedido()
    {
        return [
            'prop_codigo_de_barras'    => 1,
            'prop_codigo_de_proveedor' => 2,
            'prop_nombre'              => 3,
            'prop_cantidad'            => 4,
            'prop_costo'               => 5,
            'prop_notas'               => 6,
        ];
    }
}
