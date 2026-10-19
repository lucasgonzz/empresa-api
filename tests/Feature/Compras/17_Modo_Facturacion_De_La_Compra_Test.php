<?php

namespace Tests\Feature\Compras;

use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Misión `factura-compra-tres-defectos` (9/10/2026), Defecto 3 — una compra guardada sin "Modo
 * Facturacion" se facturaba sola y quedaba sin modo.
 *
 * La causa, medida: el select de la SPA arranca en la opción 0 ("Seleccione"), la SPA manda
 * `modo_facturacion: 0` (entero) y en PHP 7.4 `0 == 'automatico'` es verdadero. El alta corría la
 * factura automática pero guardaba "0"; al reabrir, el select decía "Seleccione"; y en la edición
 * `"0" == 'automatico'` ya daba falso, así que de ahí en adelante la compra se comportaba como
 * manual sin que nada lo mostrara.
 *
 * Decisión de Lucas (9/10/2026): una compra nueva arranca en 'automatico', a la vista. Lo que cubre:
 *
 *   1. Alta con `0`, `"0"`, `""` o sin la clave → queda guardada en 'automatico' y con su factura
 *      automática armada (compatible con la SPA vieja, que sigue mandando el 0).
 *   2. Alta con 'sin factura' → sin factura; con 'manual' → sin factura automática.
 *   3. Edición con un modo inválido → la compra conserva el que tenía.
 *   4. La migración deja en 'manual' las compras con modo NULL o fuera de los tres.
 *   5. El alta del asistente de WhatsApp (sin modo) → 'manual'.
 *
 * Escenario: proveedor Rosario, sin tocar precios ni stock, sin cuenta corriente, cuenta RRII.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Modo_Facturacion_De_La_Compra_Test extends ComprasTestCase
{
    /** @var array<int,int> Compras creadas por el test, para la limpieza. */
    protected $compras_creadas = [];

    /**
     * Valor que significa "no mandar la clave" en el proveedor de datos (un null es un valor
     * distinto: la clave viaja con null).
     */
    const SIN_LA_CLAVE = '__sin_la_clave__';

    /**
     * Payload del escenario, pisando el modo de facturación. Con `SIN_LA_CLAVE` la clave no viaja.
     *
     * @param  mixed  $modo
     * @return array<string,mixed>
     */
    protected function payload_con_modo($modo)
    {
        $payload = $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ]);

        if ($modo === self::SIN_LA_CLAVE) {
            unset($payload['modo_facturacion']);
        } else {
            $payload['modo_facturacion'] = $modo;
        }

        return $payload;
    }

    /**
     * @param  mixed  $modo
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra_con_modo($modo)
    {
        $response = $this->postJson('api/provider-order', $this->payload_con_modo($modo));

        $response->assertStatus(201);

        $compra_id = $response->json('model.id');

        $this->compras_creadas[] = $compra_id;

        return ProviderOrder::find($compra_id);
    }

    /**
     * La factura principal de la compra (nunca un comprobante aparte de un costo extra).
     *
     * @param  int $provider_order_id
     * @return \App\Models\ProviderOrderAfipTicket|null
     */
    protected function factura_principal_de($provider_order_id)
    {
        return ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)
                                        ->whereNull('provider_order_extra_cost_id')
                                        ->first();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $ids = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->pluck('id')->all();

            ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();
            ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
            CurrentAcount::where('provider_order_id', $compra_id)->delete();
            ProviderOrder::where('id', $compra_id)->delete();
        }

        $this->compras_creadas = [];

        parent::tearDown();
    }

    /**
     * Lo que manda la SPA cuando el select quedó en "Seleccione", en las cuatro formas en que
     * puede llegar.
     *
     * @return array<string,array<int,mixed>>
     */
    public function modos_vacios()
    {
        return [
            'entero 0 (alta de la SPA vieja)' => [0],
            'string "0"'                      => ['0'],
            'string vacío'                    => [''],
            'sin la clave'                    => [self::SIN_LA_CLAVE],
        ];
    }

    /**
     * Test 1 — Alta sin modo elegido → la compra queda en 'automatico' y con su factura automática.
     *
     * @dataProvider modos_vacios
     * @group compras
     * @test
     *
     * @param  mixed  $modo
     */
    public function el_alta_sin_modo_elegido_queda_en_automatico_y_arma_la_factura($modo)
    {
        $this->set_condicion_iva('RRII');

        $compra = $this->crear_compra_con_modo($modo);

        $this->assertSame('automatico', $compra->modo_facturacion, 'Una compra nueva sin modo elegido tiene que quedar GUARDADA en "automatico", no en "0" ni vacía.');

        $factura = $this->factura_principal_de($compra->id);

        $this->assertNotNull($factura, 'En automático el alta arma la factura de la compra.');

        // Marco para cama, 1000 de costo al 21% (el fixture lo tiene en 21%): la factura
        // automática trae el desglose desde los artículos.
        $this->assertGreaterThan(0, (float) $factura->total_iva, 'La factura automática tiene el IVA calculado desde los artículos.');
        $this->assertGreaterThan(0, ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->count(), 'Y su desglose por alícuota.');
    }

    /**
     * Test 2 — Alta con 'sin factura' → queda así, y sin ninguna factura.
     *
     * @group compras
     * @test
     */
    public function el_alta_sin_factura_queda_sin_factura()
    {
        $compra = $this->crear_compra_con_modo('sin factura');

        $this->assertSame('sin factura', $compra->modo_facturacion);
        $this->assertSame(0, ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(), 'Una compra "sin factura" no tiene facturas.');
    }

    /**
     * Test 3 — Alta con 'manual' → queda así, y el sistema no le arma ninguna factura.
     *
     * @group compras
     * @test
     */
    public function el_alta_manual_queda_manual_y_sin_factura_automatica()
    {
        $compra = $this->crear_compra_con_modo('manual');

        $this->assertSame('manual', $compra->modo_facturacion);
        $this->assertSame(0, ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(), 'En manual el sistema no arma ninguna factura.');
    }

    /**
     * Test 4 — Editar con un modo inválido (el "0" de "Seleccione", un vacío) no pisa el modo que
     * la compra ya tenía. Y un modo válido sí lo cambia.
     *
     * @group compras
     * @test
     */
    public function editar_con_un_modo_invalido_conserva_el_modo_de_la_compra()
    {
        $compra = $this->crear_compra_con_modo('manual');

        foreach (['0', '', 0] as $modo_invalido) {

            $this->putJson('api/provider-order/'.$compra->id, $this->payload_con_modo($modo_invalido))->assertStatus(200);

            $this->assertSame('manual', $compra->fresh()->modo_facturacion, 'Un modo inválido ('.var_export($modo_invalido, true).') no puede pisar el modo guardado.');
            $this->assertSame(0, ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(), 'Y la compra sigue comportándose como manual: ninguna factura automática.');
        }

        $this->putJson('api/provider-order/'.$compra->id, $this->payload_con_modo(self::SIN_LA_CLAVE))->assertStatus(200);

        $this->assertSame('manual', $compra->fresh()->modo_facturacion, 'Sin la clave en el request, tampoco se pisa.');

        // Un modo válido sí cambia.
        $this->putJson('api/provider-order/'.$compra->id, $this->payload_con_modo('automatico'))->assertStatus(200);

        $this->assertSame('automatico', $compra->fresh()->modo_facturacion, 'Un modo válido sí se guarda.');
        $this->assertNotNull($this->factura_principal_de($compra->id), 'Y al pasar a automático se arma la factura.');
    }

    /**
     * Test 5 — La migración deja en 'manual' toda compra con modo NULL o fuera de los tres —
     * incluidos los que MySQL daría por iguales sin la comparación binaria ('Automatico',
     * 'manual ')— y no toca las que ya tienen un modo válido.
     *
     * @group compras
     * @test
     */
    public function la_migracion_deja_en_manual_las_compras_sin_un_modo_valido()
    {
        $dueno     = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $valores = [
            'null'            => null,
            'cero'            => '0',
            'vacio'           => '',
            'mayuscula'       => 'Automatico',
            'espacio_final'   => 'manual ',
            'automatico'      => 'automatico',
            'manual'          => 'manual',
            'sin_factura'     => 'sin factura',
        ];

        $ids = [];

        foreach ($valores as $clave => $valor) {

            // Directo en la tabla: así quedaron las compras viejas, y ningún endpoint las produce hoy.
            $ids[$clave] = DB::table('provider_orders')->insertGetId([
                'num'               => 900000 + count($ids),
                'user_id'           => $dueno->id,
                'provider_id'       => $proveedor->id,
                'modo_facturacion'  => $valor,
                'created_at'        => '2026-01-01 00:00:00',
                'updated_at'        => '2026-01-01 00:00:00',
            ]);

            $this->compras_creadas[] = $ids[$clave];
        }

        require_once base_path('database/migrations/2026_10_09_120000_normalizar_modo_facturacion_de_provider_orders.php');

        (new \NormalizarModoFacturacionDeProviderOrders())->up();

        $esperado = [
            'null'          => 'manual',
            'cero'          => 'manual',
            'vacio'         => 'manual',
            'mayuscula'     => 'manual',
            'espacio_final' => 'manual',
            'automatico'    => 'automatico',
            'manual'        => 'manual',
            'sin_factura'   => 'sin factura',
        ];

        foreach ($esperado as $clave => $modo) {

            $fila = DB::table('provider_orders')->where('id', $ids[$clave])->first();

            $this->assertSame($modo, $fila->modo_facturacion, 'La compra con modo "'.$clave.'" tiene que quedar en "'.$modo.'".');
            $this->assertSame('2026-01-01 00:00:00', $fila->updated_at, 'La migración no puede marcar la compra como modificada hoy.');
        }
    }

    /**
     * Test 6 — El alta del asistente de WhatsApp no manda modo: la compra nace en 'manual', que es
     * como se comportaba el null de hasta hoy (el escaneo de la factura guarda sus importes sin
     * pedir que se pase a manual).
     *
     * @group compras
     * @test
     */
    public function el_alta_del_asistente_sin_modo_queda_en_manual()
    {
        $dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // Las mismas claves que manda PropuestaCompraConFacturaIaHelper::orden_para_la_factura().
        $compra = ProviderOrderAltaHelper::crear([
            'user_id'                  => $dueno->id,
            'provider_id'              => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'provider_order_status_id' => 1,
            'address_id'               => null,
            'update_prices'            => 0,
            'update_stock'             => 0,
            'generate_current_acount'  => 1,
            'precios_incluyen_iva'     => 0,
            'moneda_id'                => 1,
            'articles'                 => [],
        ]);

        $this->compras_creadas[] = $compra->id;

        $this->assertSame('manual', $compra->fresh()->modo_facturacion, 'Sin modo, el alta del asistente queda en "manual".');
        $this->assertSame(0, ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(), 'Y sin factura armada: la factura la trae el escaneo.');
    }
}
