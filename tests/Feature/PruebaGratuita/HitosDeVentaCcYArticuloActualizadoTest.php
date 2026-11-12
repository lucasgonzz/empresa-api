<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as WriterEntityFactory;
use App\Models\Article;
use App\Models\Client;
use App\Models\DemoEvento;
use App\Models\PruebaHito;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Los dos hitos que sumó la misión prueba-e2e-campana-imperio (11/11/2026), por los endpoints reales:
 *
 *  - `prueba.venta_cc`: una venta guardada que entró a la cuenta corriente de su cliente.
 *  - `prueba.articulo_actualizado`: el dueño editó desde la ficha un artículo que ya existía.
 *
 * Lo que se fija:
 *  1. Cada uno se registra y se emite UNA sola vez, con la misma forma que el resto (`datos: {id}`).
 *  2. Fuera de una instancia de prueba no se registra nada.
 *  3. La venta común (de mostrador, o la de un cliente que no va a su cuenta) emite `prueba.venta` y NO
 *     `prueba.venta_cc`.
 *  4. El alta de un artículo y una importación de Excel que actualiza uno existente NO emiten
 *     `prueba.articulo_actualizado`.
 *  5. El admin los ve en `GET admin-sync/prueba/estado` (salen de `PruebaHelper::TIPOS_DE_HITO`).
 *
 * En la suite la cola es `sync` (phpunit.xml): `RegistrarHitoDePruebaJob` corre adentro del request, así que
 * alcanza con mirar `prueba_hitos` y `demo_eventos` después de cada pedido.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class HitosDeVentaCcYArticuloActualizadoTest extends EmpresaTestCase
{
    const TOKEN = 'token-de-eventos-del-test-de-hitos-e2e-7c1d';
    const URL = 'https://admin.test.local/api/demo-eventos';

    /** @var \App\Models\User */
    protected $dueno;

    /** @var int Total de la próxima venta: distinto cada vez, para no caer en el guard anti-duplicado. */
    protected $total = 1310;

    protected function setUp(): void
    {
        parent::setUp();

        // El modo prueba existe solo con FOR_USER=prueba (lo escribe el script de alta del admin).
        config(['app.FOR_USER' => 'prueba']);

        PruebaHelper::olvidar_cache();
        DemoTrackingConfigHelper::olvidar_cache();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        DemoTrackingConfigHelper::olvidar_cache();
        parent::tearDown();
    }

    private function prender_la_prueba()
    {
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7, 'whatsapp_soporte' => '+5493444622139']);
    }

    private function abrir_el_canal()
    {
        DemoTrackingConfigHelper::guardar(self::TOKEN, self::URL, null, null);
        DemoTrackingConfigHelper::olvidar_cache();
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
    }

    /**
     * @param string $tipo
     * @return int Cuántas veces quedó registrado el hito del dueño (0 o 1: el índice es único).
     */
    private function hitos($tipo)
    {
        return PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', $tipo)->count();
    }

    /**
     * El cliente con cuenta corriente del fixture, con su cuenta en pesos asegurada.
     *
     * @return \App\Models\Client
     */
    private function cliente_cc()
    {
        $cliente = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();
        $this->assertNotNull($cliente, 'Falta el cliente con cuenta corriente del fixture.');

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id);

        return $cliente;
    }

    /**
     * Guarda una venta por `POST api/sale` y devuelve su id.
     *
     * El payload no habla del cobro (ni `current_acount_payment_method_id` ni `selected_payment_methods`): con
     * la clave ausente `PaymentMethodHelper` no opina, así el test no depende de cajas abiertas del fixture.
     *
     * @param array $props Lo que cambia respecto de una venta de mostrador sin cliente.
     * @return int
     */
    private function vender(array $props = [])
    {
        $this->total += 7;

        $respuesta = $this->postJson('api/sale', array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'items'                      => [],
            'discounts'                  => [],
            'surchages'                  => [],
            'sub_total'                  => $this->total,
            'total'                      => $this->total,
            'discounts_in_services'      => 0,
            'surchages_in_services'      => 0,
            'to_check'                   => 0,
            'moneda_id'                  => 1,
        ], $props));

        $this->assertSame(201, $respuesta->getStatusCode(), 'POST api/sale: ' . $respuesta->getContent());

        return (int) $respuesta->json('model.id');
    }

    /**
     * @param array $props
     * @return int
     */
    private function vender_a_cuenta_corriente(array $props = [])
    {
        return $this->vender(array_merge([
            'client_id'           => $this->cliente_cc()->id,
            'save_current_acount' => 1,
        ], $props));
    }

    /**
     * Payload mínimo que acepta ArticleController (mismas claves que ArticuloSlugAlGuardarTest).
     *
     * @param array $props
     * @return array
     */
    private function payload_articulo(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Hito articulo',
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 0,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Un artículo que ya existe, creado directo en la base (no por el endpoint: el alta tiene su propio hito).
     *
     * @param string $nombre
     * @param string|null $bar_code
     * @return \App\Models\Article
     */
    private function articulo_existente($nombre, $bar_code = null)
    {
        $articulo = new Article();

        $articulo->user_id  = $this->dueno->id;
        $articulo->name     = $nombre;
        $articulo->bar_code = $bar_code;
        $articulo->cost     = 100;
        $articulo->iva_id   = 2;
        $articulo->status   = 'active';
        $articulo->save();

        return $articulo->fresh();
    }

    /**
     * Edita un artículo por `PUT api/article/{id}` (el formulario de la ficha).
     *
     * @param \App\Models\Article $articulo
     * @param array $props
     * @return void
     */
    private function editar_por_la_ficha($articulo, array $props)
    {
        $respuesta = $this->putJson('/api/article/' . $articulo->id, $this->payload_articulo(array_merge([
            'id'   => $articulo->id,
            'name' => $articulo->name,
        ], $props)));

        $this->assertSame(200, $respuesta->getStatusCode(), 'PUT api/article: ' . $respuesta->getContent());

        $this->cerrar_la_transaccion_del_pedido();
    }

    /**
     * Corre lo que el controlador dejó para «después del commit».
     *
     * `ArticleController` emite sus hitos con `DB::afterCommit()`, porque corre adentro de la transacción del
     * historial de precios. En la suite esa transacción queda anidada en la de `DatabaseTransactions`, que
     * nunca hace commit (revierte al terminar el test): en Laravel 8 esos callbacks no correrían jamás. En
     * producción el pedido termina con un commit de verdad; acá se le avisa al registro de transacciones que
     * ese commit ocurrió, sin tocar la conexión (el rollback del test sigue revirtiendo todo).
     *
     * @return void
     */
    private function cerrar_la_transaccion_del_pedido()
    {
        app('db.transactions')->commit(DB::connection()->getName());
    }

    /**
     * Importa un Excel de artículos por el endpoint real. Con la cola `sync`, al volver ya terminó.
     *
     * @param array $filas Filas `[codigo_de_barras, nombre, costo]`.
     * @return void
     */
    private function importar_excel(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('hitos_prueba_import_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(WriterEntityFactory::createRowFromArray(['codigo_de_barras', 'nombre', 'costo']));
        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }
        $writer->close();

        $respuesta = $this->postJson('/api/article/excel/import', [
            'models' => new UploadedFile(
                $ruta,
                basename($ruta),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
            'start_row'   => 2,
            'finish_row'  => 99999,
            'provider_id' => null,

            'create_and_edit'                                    => true,
            'permitir_provider_code_repetido'                    => false,
            'permitir_provider_code_repetido_en_multi_providers' => true,
            'actualizar_articulos_de_otro_proveedor'             => false,
            'actualizar_por_provider_code'                       => true,
            'actualizar_proveedor'                               => true,
            'registrar_art_cre'                                  => true,
            'registrar_art_act'                                  => true,

            'prop_codigo_de_barras' => 1,
            'prop_nombre'           => 2,
            'prop_costo'            => 3,
        ]);

        $this->assertSame(200, $respuesta->getStatusCode(), 'POST api/article/excel/import: ' . $respuesta->getContent());

        $this->cerrar_la_transaccion_del_pedido();
    }

    public function test_los_dos_tipos_nuevos_estan_en_el_catalogo_y_en_el_estado_para_el_admin()
    {
        $this->assertContains('prueba.venta_cc', PruebaHelper::TIPOS_DE_HITO);
        $this->assertContains('prueba.articulo_actualizado', PruebaHelper::TIPOS_DE_HITO);

        $this->prender_la_prueba();

        $estado = PruebaHelper::estado_para_admin($this->dueno->id);

        $this->assertArrayHasKey('prueba.venta_cc', $estado['hitos']);
        $this->assertArrayHasKey('prueba.articulo_actualizado', $estado['hitos']);
        $this->assertNull($estado['hitos']['prueba.venta_cc']);
        $this->assertNull($estado['hitos']['prueba.articulo_actualizado']);

        // Los once de antes siguen estando, con el mismo nombre.
        foreach ([
            'prueba.ingreso', 'prueba.importo', 'prueba.articulo_creado', 'prueba.venta', 'prueba.cliente_creado',
            'prueba.pago_cc', 'prueba.compra', 'prueba.gasto', 'prueba.tienda_pedido', 'prueba.agente', 'prueba.vencida',
        ] as $tipo) {
            $this->assertArrayHasKey($tipo, $estado['hitos'], $tipo);
        }
    }

    public function test_la_venta_a_cuenta_corriente_registra_venta_y_venta_cc()
    {
        $this->prender_la_prueba();

        $venta_id = $this->vender_a_cuenta_corriente();

        $this->assertSame(1, DB::table('current_acounts')->where('sale_id', $venta_id)->where('debe', '>', 0)->count(), 'La venta tiene que haber entrado a la cuenta corriente.');
        $this->assertSame(1, $this->hitos('prueba.venta'));
        $this->assertSame(1, $this->hitos('prueba.venta_cc'));
    }

    public function test_venta_cc_se_emite_al_admin_una_sola_vez_y_con_la_forma_de_siempre()
    {
        $this->prender_la_prueba();
        $this->abrir_el_canal();

        $primera = $this->vender_a_cuenta_corriente();
        $this->vender_a_cuenta_corriente();

        $this->assertSame(1, $this->hitos('prueba.venta_cc'));

        $eventos = DemoEvento::where('nombre', 'prueba.venta_cc')->get();
        $this->assertCount(1, $eventos, 'La segunda venta a cuenta corriente no vuelve a emitir.');
        $this->assertSame(['id' => $primera], $eventos->first()->datos);

        Http::assertSent(function ($request) use ($primera) {
            foreach ($request['eventos'] as $evento) {
                if ($evento['nombre'] === 'prueba.venta_cc') {
                    return $request->url() === self::URL
                        && $request->hasHeader('X-Demo-Eventos-Key', self::TOKEN)
                        && is_null($evento['clip_id'])
                        && $evento['datos'] === ['id' => $primera]
                        && strlen((string) $evento['uuid']) === 36
                        && is_string($evento['ocurrido_at']);
                }
            }

            return false;
        });
    }

    public function test_la_venta_de_mostrador_no_registra_venta_cc()
    {
        $this->prender_la_prueba();

        $this->vender();

        $this->assertSame(1, $this->hitos('prueba.venta'));
        $this->assertSame(0, $this->hitos('prueba.venta_cc'));
    }

    public function test_la_venta_de_un_cliente_que_no_va_a_su_cuenta_no_registra_venta_cc()
    {
        $this->prender_la_prueba();

        $venta_id = $this->vender_a_cuenta_corriente(['save_current_acount' => 0]);
        $otra_id = $this->vender_a_cuenta_corriente(['omitir_en_cuenta_corriente' => 1]);

        $this->assertSame(0, DB::table('current_acounts')->whereIn('sale_id', [$venta_id, $otra_id])->count());
        $this->assertSame(1, $this->hitos('prueba.venta'));
        $this->assertSame(0, $this->hitos('prueba.venta_cc'));
    }

    public function test_fuera_de_una_instancia_de_prueba_la_venta_a_cuenta_corriente_no_registra_nada()
    {
        $this->prender_la_prueba();
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();

        $venta_id = $this->vender_a_cuenta_corriente();

        $this->assertSame(1, DB::table('current_acounts')->where('sale_id', $venta_id)->count());
        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->count());
    }

    public function test_sin_config_de_prueba_la_venta_a_cuenta_corriente_no_registra_nada()
    {
        // Instancia de prueba, pero este dueño no tiene la prueba prendida.
        $this->vender_a_cuenta_corriente();

        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->count());
    }

    public function test_editar_un_articulo_desde_la_ficha_registra_articulo_actualizado()
    {
        $this->prender_la_prueba();
        $articulo = $this->articulo_existente('ZZ Hito editado ' . uniqid());

        $this->editar_por_la_ficha($articulo, ['cost' => 150]);

        $this->assertEqualsWithDelta(150, (float) $articulo->fresh()->cost, 0.01);
        $this->assertSame(1, $this->hitos('prueba.articulo_actualizado'));
        $this->assertSame(0, $this->hitos('prueba.articulo_creado'), 'Editar no es dar de alta.');
    }

    public function test_articulo_actualizado_se_emite_al_admin_una_sola_vez_y_con_la_forma_de_siempre()
    {
        $this->prender_la_prueba();
        $this->abrir_el_canal();
        $articulo = $this->articulo_existente('ZZ Hito editado ' . uniqid());

        $this->editar_por_la_ficha($articulo, ['cost' => 150]);
        $this->editar_por_la_ficha($articulo, ['name' => $articulo->name . ' bis', 'cost' => 180]);

        $this->assertSame(1, $this->hitos('prueba.articulo_actualizado'));

        $eventos = DemoEvento::where('nombre', 'prueba.articulo_actualizado')->get();
        $this->assertCount(1, $eventos, 'La segunda edición no vuelve a emitir.');
        $this->assertSame(['id' => (int) $articulo->id], $eventos->first()->datos);

        Http::assertSent(function ($request) use ($articulo) {
            foreach ($request['eventos'] as $evento) {
                if ($evento['nombre'] === 'prueba.articulo_actualizado') {
                    return $request->url() === self::URL
                        && $request->hasHeader('X-Demo-Eventos-Key', self::TOKEN)
                        && is_null($evento['clip_id'])
                        && $evento['datos'] === ['id' => (int) $articulo->id];
                }
            }

            return false;
        });
    }

    public function test_el_alta_de_un_articulo_no_registra_articulo_actualizado()
    {
        $this->prender_la_prueba();

        $respuesta = $this->postJson('/api/article', $this->payload_articulo(['name' => 'ZZ Hito alta ' . uniqid()]));
        $this->assertSame(201, $respuesta->getStatusCode(), 'POST api/article: ' . $respuesta->getContent());
        $this->cerrar_la_transaccion_del_pedido();

        $this->assertSame(1, $this->hitos('prueba.articulo_creado'));
        $this->assertSame(0, $this->hitos('prueba.articulo_actualizado'));
    }

    public function test_fuera_de_una_instancia_de_prueba_editar_un_articulo_no_registra_nada()
    {
        $this->prender_la_prueba();
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();
        $articulo = $this->articulo_existente('ZZ Hito editado ' . uniqid());

        $this->editar_por_la_ficha($articulo, ['cost' => 150]);

        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->count());
    }

    public function test_una_importacion_que_actualiza_un_articulo_no_registra_articulo_actualizado()
    {
        $this->prender_la_prueba();
        $codigo = '779' . substr(str_replace('.', '', (string) microtime(true)), -10);
        $articulo = $this->articulo_existente('ZZ Hito importado ' . uniqid(), $codigo);

        $this->importar_excel([[$codigo, $articulo->name, 250]]);

        $this->assertEqualsWithDelta(250, (float) $articulo->fresh()->cost, 0.01, 'La importación tiene que haber actualizado el artículo.');
        $this->assertSame(0, $this->hitos('prueba.articulo_actualizado'), 'Importar no es editar desde la ficha.');
        $this->assertSame(0, $this->hitos('prueba.articulo_creado'));
    }
}
