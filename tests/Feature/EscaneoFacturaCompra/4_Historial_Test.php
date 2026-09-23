<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Models\ExtencionEmpresa;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Misión historial-escaneos-compra — archivo 4: el historial de escaneos de una compra.
 *
 * Lo que sostiene la misión y no se puede sacar:
 *   - el historial trae TODOS los escaneos de la compra (confirmados, descartados, con error,
 *     pendientes), no solo el que enciende el botón rojo;
 *   - la tenencia: un escaneo de otro comercio sobre el mismo provider_order_id no aparece;
 *   - `provider_order_scans_count` viaja con la compra, que es lo que decide si el listado
 *     muestra el botón "Historial".
 *
 * Mismo cuidado que el archivo 3: Notification/Queue fakeados y la clave de Anthropic en null,
 * para que ningún camino salga a la API real.
 */
class Historial_Test extends EmpresaTestCase
{
    /** Slug de la extensión que gatea los endpoints del escaneo. */
    const SLUG = 'escaneo_factura_compra';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        config(['services.anthropic.api_key' => null]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Asigna la extensión al comercio, creando la fila del catálogo si la base del slot todavía
     * no la tiene sembrada.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Escaneo de facturas con IA',
            ]);
        }

        $user = auth()->user();

        if (!collect($user->extencions)->contains('slug', self::SLUG)) {
            $user->extencions()->attach($extencion->id);
        }

        $user->load('extencions');
    }

    /**
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra()
    {
        $proveedor = TestingFerreteriaSeeder::PROVIDER_OTRO;

        return ProviderOrder::create([
            'num'                      => mt_rand(9000000, 9999999),
            'provider_id'              => \App\Models\Provider::where('name', $proveedor)->first()->id,
            'provider_order_status_id' => 1,
            'modo_facturacion'         => 'manual',
            'update_stock'             => 0,
            'update_prices'            => 0,
            'generate_current_acount'  => 0,
            'precios_incluyen_iva'     => false,
            'moneda_id'                => 1,
            'user_id'                  => auth()->user()->id,
        ]);
    }

    /**
     * Crea un escaneo de la compra con los campos que se le pasen.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  array                      $overrides
     * @return \App\Models\ProviderOrderScan
     */
    protected function crear_escaneo($compra, array $overrides = [])
    {
        return ProviderOrderScan::create(array_merge([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => auth()->user()->id,
            'auth_user_id'      => auth()->user()->id,
            'provider_order_id' => $compra->id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado'         => [
                'articulos' => [
                    ['codigo_proveedor' => 'H-1', 'bar_code' => null, 'nombre' => 'HISTORIAL UNO', 'cantidad' => 3, 'costo_unitario' => 100],
                    ['codigo_proveedor' => 'H-2', 'bar_code' => null, 'nombre' => 'HISTORIAL DOS', 'cantidad' => 5, 'costo_unitario' => 250],
                ],
                'factura' => [
                    'tipo_comprobante'    => 'A',
                    'code'                => '0001-00001234',
                    'issued_at'           => '2026-09-01',
                    'emisor_razon_social' => 'PROVEEDOR SA',
                    'total'               => 1210,
                    'ivas'                => [['alicuota' => 21, 'importe' => 210]],
                ],
            ],
        ], $overrides));
    }

    /**
     * @param  int  $provider_order_id
     * @return array<int,array>
     */
    protected function historial_de($provider_order_id)
    {
        $respuesta = $this->getJson('api/provider-order-scan/historial/' . $provider_order_id);

        $respuesta->assertStatus(200);

        return $respuesta->json('models');
    }

    /* ------------------------------------------------------------------ */
    /* Tests                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * El historial trae los escaneos de TODOS los estados, el más nuevo primero, y cada uno con
     * su estado_visible. Es el agujero que se cierra: hasta ahora un escaneo confirmado o
     * descartado no se podía volver a ver.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function el_historial_trae_todos_los_estados_del_mas_nuevo_al_mas_viejo()
    {
        $this->dar_extension();

        $compra = $this->crear_compra();

        $confirmado = $this->crear_escaneo($compra, [
            'gestionado_at'     => now(),
            'resultado_gestion' => 'confirmado',
            'aplicado'          => ['articulos_agregados' => 2, 'articulos_creados' => 0, 'articulos_omitidos' => 0, 'factura_guardada' => 'completa', 'factura_motivo' => null],
        ]);
        $descartado = $this->crear_escaneo($compra, ['gestionado_at' => now(), 'resultado_gestion' => 'descartado']);
        $con_error  = $this->crear_escaneo($compra, ['estado' => 'error', 'resultado' => null, 'error' => 'No se pudo leer la foto.']);
        $pendiente  = $this->crear_escaneo($compra);
        $procesando = $this->crear_escaneo($compra, ['estado' => 'procesando', 'progreso' => 40, 'resultado' => null]);

        $modelos = $this->historial_de($compra->id);

        $this->assertCount(5, $modelos);

        /* Más nuevo primero: el último creado abre la lista. */
        $this->assertEquals($procesando->uuid, $modelos[0]['uuid']);
        $this->assertEquals($confirmado->uuid, $modelos[4]['uuid']);

        $por_uuid = [];
        foreach ($modelos as $modelo) {
            $por_uuid[$modelo['uuid']] = $modelo;
        }

        $this->assertEquals('confirmado', $por_uuid[$confirmado->uuid]['estado_visible']);
        $this->assertEquals('descartado', $por_uuid[$descartado->uuid]['estado_visible']);
        $this->assertEquals('error', $por_uuid[$con_error->uuid]['estado_visible']);
        $this->assertEquals('para_revisar', $por_uuid[$pendiente->uuid]['estado_visible']);
        $this->assertEquals('en_proceso', $por_uuid[$procesando->uuid]['estado_visible']);

        $this->assertEquals('No se pudo leer la foto.', $por_uuid[$con_error->uuid]['error']);
    }

    /**
     * El resumen trae lo asentado, el comprobante y los artículos leídos, y NO el resultado
     * crudo (que pesa decenas de KB por escaneo).
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function el_historial_devuelve_el_resumen_y_no_el_resultado_crudo()
    {
        $this->dar_extension();

        $compra = $this->crear_compra();

        $scan = $this->crear_escaneo($compra, [
            'gestionado_at'     => now(),
            'resultado_gestion' => 'confirmado',
            'aplicado'          => ['articulos_agregados' => 2, 'articulos_creados' => 1, 'articulos_omitidos' => 0, 'factura_guardada' => 'completa', 'factura_motivo' => null],
        ]);

        foreach ([1, 2] as $orden) {
            ProviderOrderScanImage::create([
                'provider_order_scan_id' => $scan->id,
                'provider_order_id'      => $compra->id,
                'user_id'                => auth()->user()->id,
                'orden'                  => $orden,
                'path'                   => 'provider_order_scans/prueba/' . $orden . '.webp',
                'nombre_original'        => 'pagina' . $orden . '.jpg',
            ]);
        }

        $modelo = $this->historial_de($compra->id)[0];

        $this->assertEquals(2, $modelo['cantidad_imagenes']);
        $this->assertEquals(2, $modelo['cantidad_articulos']);
        $this->assertEquals(1, $modelo['imagenes'][0]['orden']);
        $this->assertEquals('pagina2.jpg', $modelo['imagenes'][1]['nombre_original']);

        $this->assertEquals(2, $modelo['aplicado']['articulos_agregados']);
        $this->assertEquals(1, $modelo['aplicado']['articulos_creados']);

        $this->assertEquals('0001-00001234', $modelo['factura']['code']);
        $this->assertEquals('PROVEEDOR SA', $modelo['factura']['emisor_razon_social']);

        $this->assertEquals('HISTORIAL UNO', $modelo['articulos'][0]['nombre']);
        $this->assertEquals(250, $modelo['articulos'][1]['costo_unitario']);

        $this->assertArrayNotHasKey('resultado', $modelo);
        $this->assertArrayNotHasKey('ivas', $modelo['factura']);

        /* Quien lo lanzó viaja por nombre, no por id. */
        $this->assertEquals(auth()->user()->name, $modelo['usuario']);
    }

    /**
     * 🔴 Tenencia. Un escaneo de OTRO comercio sobre el mismo provider_order_id no puede
     * aparecer: una factura tiene CUIT, razón social y precios de compra.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function el_historial_no_muestra_escaneos_de_otro_comercio()
    {
        $this->dar_extension();

        $compra = $this->crear_compra();

        $propio = $this->crear_escaneo($compra);

        $ajeno = $this->crear_escaneo($compra, ['user_id' => auth()->user()->id + 987654]);

        $uuids = array_column($this->historial_de($compra->id), 'uuid');

        $this->assertContains($propio->uuid, $uuids);
        $this->assertNotContains($ajeno->uuid, $uuids, 'Se filtró un escaneo de otro comercio.');
    }

    /**
     * Una compra sin escaneos —o un id que no existe— da lista vacía y 200, no un error.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function una_compra_sin_escaneos_devuelve_lista_vacia()
    {
        $this->dar_extension();

        $compra = $this->crear_compra();

        $this->assertSame([], $this->historial_de($compra->id));
        $this->assertSame([], $this->historial_de(987654321));
    }

    /**
     * Un escaneo trabado en 'pendiente' hace más de una hora no está corriendo (el job tiene 10
     * minutos de timeout): se dice "sin_terminar" en vez de mostrarlo en proceso para siempre.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function un_escaneo_abandonado_se_ve_como_sin_terminar()
    {
        $this->dar_extension();

        $compra = $this->crear_compra();

        $reciente   = $this->crear_escaneo($compra, ['estado' => 'pendiente', 'resultado' => null]);
        $abandonado = $this->crear_escaneo($compra, ['estado' => 'pendiente', 'resultado' => null]);

        /* created_at no es asignable en masa sin timestamps: se fuerza por query. */
        ProviderOrderScan::where('id', $abandonado->id)->update(['created_at' => now()->subHours(3)]);

        $por_uuid = [];
        foreach ($this->historial_de($compra->id) as $modelo) {
            $por_uuid[$modelo['uuid']] = $modelo['estado_visible'];
        }

        $this->assertEquals('en_proceso', $por_uuid[$reciente->uuid]);
        $this->assertEquals('sin_terminar', $por_uuid[$abandonado->uuid]);
    }

    /**
     * Sin la extensión el endpoint da 403, igual que los otros siete del escaneo.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function el_historial_respeta_el_gate_de_la_extension()
    {
        $this->dar_extension();

        $user = auth()->user();
        $user->extencions()->detach(ExtencionEmpresa::where('slug', self::SLUG)->first()->id);
        $user->load('extencions');

        $compra = $this->crear_compra();

        $this->getJson('api/provider-order-scan/historial/' . $compra->id)->assertStatus(403);
    }

    /**
     * La compra viaja con la cantidad de escaneos que tuvo, que es lo que decide si el listado
     * muestra el botón "Historial". Se mira por el show y por el index (from-date), que son los
     * dos caminos por los que el listado recibe compras.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function la_compra_viaja_con_la_cantidad_de_escaneos_que_tuvo()
    {
        $this->dar_extension();

        $con_escaneos = $this->crear_compra();
        $sin_escaneos = $this->crear_compra();

        $this->crear_escaneo($con_escaneos);
        $this->crear_escaneo($con_escaneos, ['gestionado_at' => now(), 'resultado_gestion' => 'descartado']);

        $this->assertEquals(
            2,
            $this->getJson('api/provider-order/' . $con_escaneos->id)->json('model.provider_order_scans_count')
        );

        $this->assertEquals(
            0,
            $this->getJson('api/provider-order/' . $sin_escaneos->id)->json('model.provider_order_scans_count')
        );

        /* El listado también lo trae (mismo withAll). */
        $listado = $this->getJson('api/provider-order/from-date/' . now()->subDay()->toDateString() . '/' . now()->addDay()->toDateString());

        $listado->assertStatus(200);

        $cantidades = [];
        foreach ($listado->json('models') as $fila) {
            $cantidades[$fila['id']] = $fila['provider_order_scans_count'];
        }

        $this->assertEquals(2, $cantidades[$con_escaneos->id]);
    }
}
