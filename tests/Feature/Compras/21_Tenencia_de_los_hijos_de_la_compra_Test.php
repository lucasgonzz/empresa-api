<?php

namespace Tests\Feature\Compras;

use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderDiscount;
use App\Models\ProviderOrderExtraCost;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Misión `tenencia-compra-hermanos` (17/10/2026) — la TENENCIA de los hijos de la compra a
 * proveedor: facturas (`provider-order-afip-ticket`), sus alícuotas (`provider-order-afip-ticket-iva`),
 * costos extra (`provider-order-extra-cost`) y descuentos (`provider-order-discount`), más el
 * `provider_id` y los `articles` que llegan en el cuerpo de la compra.
 *
 * Continuación de `tenencia-compra-show` (test 20): los cuatro controllers resolvían por id pelado y
 * ESCRIBÍAN. En una base compartida (`u767360347_empresa`, 51 comercios, ids correlativos) un PUT o un
 * DELETE con el id de otro comercio le pisaba la factura, le borraba un costo o le recalculaba la
 * compra; y un `store` con el `model_id` de una compra ajena le colgaba filas y le recalculaba el
 * total y la cuenta corriente del proveedor.
 *
 * Criterio: lo ajeno se contesta EXACTAMENTE igual que lo inexistente o que lo que no es un id (404) y
 * se resuelve ANTES de escribir. Lo AJENO se crea a mano con el `user_id` de otro dueño; lo PROPIO,
 * por los endpoints reales.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Tenencia_de_los_hijos_de_la_compra_Test extends ComprasTestCase
{
    /** @var User|null */
    protected $otro_dueno = null;

    /** @var array<int,int> */
    protected $usuarios_creados = [];

    /** @var array<int,int> */
    protected $compras_creadas = [];

    /** @var array<int,int> */
    protected $articulos_creados = [];

    /** @var array<int,int> */
    protected $proveedores_creados = [];

    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {
            $this->otro_dueno = $this->crear_usuario('Otro comercio hijos', 'hijos-tenencia-otro-', null);
        }

        return $this->otro_dueno;
    }

    protected function crear_usuario($nombre, $prefijo_email, $owner_id)
    {
        $usuario = User::create([
            'name'     => $nombre,
            'email'    => $prefijo_email . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $owner_id,
        ]);

        $this->usuarios_creados[] = $usuario->id;

        return $usuario;
    }

    /** El `Auth::forgetGuards()` no es decorativo: sanctum cachea el usuario de la primera request. */
    protected function actuar_como(User $usuario)
    {
        Auth::forgetGuards();

        $this->actingAs($usuario, 'web');
    }

    /** El usuario que autentica `EmpresaTestCase::setUp()`. */
    protected function usuario_de_prueba()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /** Una compra de OTRO comercio, insertada a mano. */
    protected function compra_ajena()
    {
        $compra = ProviderOrder::create([
            'user_id'                  => $this->otro_dueno()->id,
            'provider_id'              => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'provider_order_status_id' => 1,
            'total_with_iva'           => 1,
            'modo_facturacion'         => 'manual',
            'numero_comprobante'       => 'AJENA-' . substr(uniqid(), -6),
        ]);

        $this->compras_creadas[] = $compra->id;

        return $compra;
    }

    /** Una compra PROPIA por el endpoint real, en modo manual y sin tocar stock ni cuenta. */
    protected function compra_propia()
    {
        $payload = $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
            'numero_comprobante'      => 'PROPIA-' . substr(uniqid(), -6),
            'articles'                => [$this->item('Marco para cama', 1000, 1)],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $this->compras_creadas[] = $response->json('model.id');

        return ProviderOrder::find($response->json('model.id'));
    }

    /**
     * Los cuatro hijos de la compra ajena, a mano: factura (con `user_id` del otro dueño, como la
     * deja el endpoint), una alícuota, un costo extra y un descuento.
     *
     * @return array{ticket:ProviderOrderAfipTicket,iva:ProviderOrderAfipTicketIva,costo:ProviderOrderExtraCost,descuento:ProviderOrderDiscount,compra:ProviderOrder}
     */
    protected function hijos_ajenos()
    {
        $compra = $this->compra_ajena();

        $ticket = ProviderOrderAfipTicket::create([
            'provider_order_id' => $compra->id,
            'user_id'           => $compra->user_id,
            'code'              => 'FAJENA-1',
            'issued_at'         => '2026-10-01',
        ]);

        $iva = ProviderOrderAfipTicketIva::create([
            'provider_order_afip_ticket_id' => $ticket->id,
            'iva_id'                        => 1,
            'neto'                          => 100,
            'iva_importe'                   => 21,
        ]);

        $costo = ProviderOrderExtraCost::create([
            'provider_order_id' => $compra->id,
            'description'       => 'Flete ajeno',
            'value'             => 50,
        ]);

        $descuento = ProviderOrderDiscount::create([
            'provider_order_id' => $compra->id,
            'description'       => 'Descuento ajeno',
            'percentage'        => 5,
        ]);

        return compact('compra', 'ticket', 'iva', 'costo', 'descuento');
    }

    /**
     * Facturas, alícuotas, costos extra y descuentos de una compra ajena: ni se leen, ni se editan,
     * ni se borran. El mismo 404 para lo ajeno, lo inexistente y lo que no es un id.
     *
     * @test
     */
    public function los_hijos_de_una_compra_ajena_no_se_leen_ni_se_editan_ni_se_borran()
    {
        $h = $this->hijos_ajenos();
        $compra_foto = $h['compra']->fresh()->toArray();

        $rutas = [
            'provider-order-afip-ticket'     => ['modelo' => $h['ticket'], 'cuerpo' => ['code' => 'PISADA', 'issued_at' => '2026-10-02']],
            'provider-order-afip-ticket-iva' => ['modelo' => $h['iva'], 'cuerpo' => ['iva_id' => 2, 'neto' => 999, 'iva_importe' => 999, 'provider_order_afip_ticket_id' => $h['ticket']->id]],
            'provider-order-extra-cost'      => ['modelo' => $h['costo'], 'cuerpo' => ['description' => 'PISADA', 'value' => 999, 'facturado' => 0, 'en_factura_compra' => 0]],
            'provider-order-discount'        => ['modelo' => $h['descuento'], 'cuerpo' => ['description' => 'PISADA', 'percentage' => 99]],
        ];

        foreach ($rutas as $ruta => $caso) {

            $modelo = $caso['modelo'];
            $foto = $modelo->fresh()->toArray();

            $r = $this->getJson("api/$ruta/" . $modelo->id);
            $this->assertSame(404, $r->getStatusCode(), "GET $ruta ajeno: " . $r->getContent());

            $r = $this->putJson("api/$ruta/" . $modelo->id, $caso['cuerpo']);
            $this->assertSame(404, $r->getStatusCode(), "PUT $ruta ajeno: " . $r->getContent());
            $this->assertEquals($foto, $modelo->fresh()->toArray(), "$ruta ajeno quedó como estaba.");

            $r = $this->deleteJson("api/$ruta/" . $modelo->id);
            $this->assertSame(404, $r->getStatusCode(), "DELETE $ruta ajeno: " . $r->getContent());
            $this->assertNotNull($modelo->fresh(), "$ruta ajeno sigue existiendo.");

            // Inexistente y no-id: el mismo 404 (antes era un 500 por `null->...`).
            foreach ([(string) (ProviderOrderAfipTicket::max('id') + 100000), 'abc', $modelo->id . 'abc', '0'] as $basura) {

                foreach (['getJson', 'putJson', 'deleteJson'] as $verbo) {

                    $r = $verbo === 'putJson'
                        ? $this->putJson("api/$ruta/$basura", $caso['cuerpo'])
                        : $this->{$verbo}("api/$ruta/$basura");

                    $this->assertSame(404, $r->getStatusCode(), "$verbo $ruta/$basura: " . $r->getContent());
                }
            }
        }

        $this->assertEquals($compra_foto, $h['compra']->fresh()->toArray(), 'La compra ajena no se recalculó.');
    }

    /**
     * El `store` con el `model_id` de una compra ajena (o de una factura ajena, para la alícuota)
     * le colgaba filas y recalculaba su total. Corta antes de crear nada.
     *
     * @test
     */
    public function un_alta_con_model_id_ajeno_no_cuelga_nada_ni_recalcula()
    {
        $h = $this->hijos_ajenos();
        $compra_foto = $h['compra']->fresh()->toArray();

        $antes = [
            'tickets' => ProviderOrderAfipTicket::count(),
            'ivas'    => ProviderOrderAfipTicketIva::count(),
            'costos'  => ProviderOrderExtraCost::count(),
            'desc'    => ProviderOrderDiscount::count(),
        ];

        $casos = [
            'provider-order-afip-ticket'     => ['model_id' => $h['compra']->id, 'code' => 'X', 'issued_at' => '2026-10-03'],
            'provider-order-extra-cost'      => ['model_id' => $h['compra']->id, 'description' => 'X', 'value' => 10, 'facturado' => 0, 'en_factura_compra' => 0],
            'provider-order-discount'        => ['model_id' => $h['compra']->id, 'description' => 'X', 'percentage' => 10],
            'provider-order-afip-ticket-iva' => ['model_id' => $h['ticket']->id, 'iva_id' => 1, 'neto' => 10, 'iva_importe' => 2],
        ];

        foreach ($casos as $ruta => $cuerpo) {

            $r = $this->postJson("api/$ruta", $cuerpo);

            $this->assertSame(404, $r->getStatusCode(), "POST $ruta con model_id ajeno: " . $r->getContent());
        }

        $this->assertSame($antes['tickets'], ProviderOrderAfipTicket::count());
        $this->assertSame($antes['ivas'], ProviderOrderAfipTicketIva::count());
        $this->assertSame($antes['costos'], ProviderOrderExtraCost::count());
        $this->assertSame($antes['desc'], ProviderOrderDiscount::count());
        $this->assertEquals($compra_foto, $h['compra']->fresh()->toArray());

        // Mover una factura propia a una compra ajena, o una alícuota propia a una factura ajena.
        $propia = $this->compra_propia();

        $factura_id = $this->postJson('api/provider-order-afip-ticket', ['model_id' => $propia->id, 'code' => 'P1', 'issued_at' => '2026-10-04'])
                           ->assertStatus(201)->json('model.id');

        $r = $this->putJson('api/provider-order-afip-ticket/' . $factura_id, ['code' => 'P1', 'issued_at' => '2026-10-04', 'provider_order_id' => $h['compra']->id]);
        $this->assertSame(404, $r->getStatusCode(), 'PUT mover la factura a una compra ajena: ' . $r->getContent());
        $this->assertSame($propia->id, ProviderOrderAfipTicket::find($factura_id)->provider_order_id);

        $iva_id = $this->postJson('api/provider-order-afip-ticket-iva', ['model_id' => $factura_id, 'iva_id' => 1, 'neto' => 100, 'iva_importe' => 21])
                       ->assertStatus(201)->json('model.id');

        $r = $this->putJson('api/provider-order-afip-ticket-iva/' . $iva_id, ['provider_order_afip_ticket_id' => $h['ticket']->id, 'iva_id' => 1, 'neto' => 100, 'iva_importe' => 21]);
        $this->assertSame(404, $r->getStatusCode(), 'PUT mover la alícuota a una factura ajena: ' . $r->getContent());
        $this->assertSame($factura_id, ProviderOrderAfipTicketIva::find($iva_id)->provider_order_afip_ticket_id);
    }

    /**
     * Costos extra, descuentos y alícuotas que todavía no cuelgan de nada (el formulario los crea con
     * `temporal_id` antes de guardar la compra): sin padre no hay de dónde sacar el dueño, así que
     * `store` los marca con el `user_id` de quien los crea. El otro comercio no los toca.
     *
     * @test
     */
    public function los_huerfanos_con_temporal_id_son_de_quien_los_creo()
    {
        $this->actuar_como($this->otro_dueno());

        $ajenos = [
            'provider-order-extra-cost'      => $this->postJson('api/provider-order-extra-cost', ['description' => 'Huerfano', 'value' => 5, 'facturado' => 0, 'en_factura_compra' => 0, 'temporal_id' => 't-' . uniqid()])->assertStatus(201)->json('model.id'),
            'provider-order-discount'        => $this->postJson('api/provider-order-discount', ['description' => 'Huerfano', 'percentage' => 5, 'temporal_id' => 't-' . uniqid()])->assertStatus(201)->json('model.id'),
            'provider-order-afip-ticket'     => $this->postJson('api/provider-order-afip-ticket', ['code' => 'H', 'issued_at' => '2026-10-05', 'temporal_id' => 't-' . uniqid()])->assertStatus(201)->json('model.id'),
        ];

        $ticket_huerfano = $ajenos['provider-order-afip-ticket'];

        $ajenos['provider-order-afip-ticket-iva'] = $this->postJson('api/provider-order-afip-ticket-iva', ['model_id' => $ticket_huerfano, 'iva_id' => 1, 'neto' => 1, 'iva_importe' => 0.21])->assertStatus(201)->json('model.id');

        // Él mismo sí las ve, las edita y las borra.
        $this->getJson('api/provider-order-extra-cost/' . $ajenos['provider-order-extra-cost'])->assertStatus(200);

        // El dueño de la sesión de test no.
        $this->actuar_como($this->usuario_de_prueba());

        foreach ($ajenos as $ruta => $id) {

            $this->assertSame(404, $this->getJson("api/$ruta/$id")->getStatusCode(), "GET huérfano $ruta");
            $this->assertSame(404, $this->deleteJson("api/$ruta/$id")->getStatusCode(), "DELETE huérfano $ruta");
        }

        // Y el otro dueño las puede borrar.
        $this->actuar_como($this->otro_dueno());

        $this->deleteJson('api/provider-order-extra-cost/' . $ajenos['provider-order-extra-cost'])->assertStatus(200);
        $this->deleteJson('api/provider-order-discount/' . $ajenos['provider-order-discount'])->assertStatus(200);

        ProviderOrderAfipTicketIva::where('id', $ajenos['provider-order-afip-ticket-iva'])->delete();
        ProviderOrderAfipTicket::where('id', $ticket_huerfano)->delete();
    }

    /**
     * Lo propio sigue andando de punta a punta (alta, lectura, edición, borrado) y el empleado
     * opera sobre lo de su dueño.
     *
     * @test
     */
    public function lo_propio_se_crea_se_lee_se_edita_y_se_borra_tambien_el_empleado()
    {
        $propia = $this->compra_propia();

        $factura_id = $this->postJson('api/provider-order-afip-ticket', ['model_id' => $propia->id, 'code' => 'P1', 'issued_at' => '2026-10-04'])
                           ->assertStatus(201)->json('model.id');

        $iva_id = $this->postJson('api/provider-order-afip-ticket-iva', ['model_id' => $factura_id, 'iva_id' => 1, 'neto' => 100, 'iva_importe' => 21])
                       ->assertStatus(201)->json('model.id');

        $costo_id = $this->postJson('api/provider-order-extra-cost', ['model_id' => $propia->id, 'description' => 'Flete', 'value' => 10, 'facturado' => 0, 'en_factura_compra' => 0])
                         ->assertStatus(201)->json('model.id');

        $descuento_id = $this->postJson('api/provider-order-discount', ['model_id' => $propia->id, 'description' => 'Desc', 'percentage' => 3])
                             ->assertStatus(201)->json('model.id');

        $this->getJson('api/provider-order-afip-ticket/' . $factura_id)->assertStatus(200)->assertJsonPath('model.id', $factura_id);
        $this->getJson('api/provider-order-afip-ticket-iva/' . $iva_id)->assertStatus(200);
        $this->getJson('api/provider-order-extra-cost/' . $costo_id)->assertStatus(200);
        $this->getJson('api/provider-order-discount/' . $descuento_id)->assertStatus(200);

        $this->putJson('api/provider-order-afip-ticket/' . $factura_id, ['code' => 'P2', 'issued_at' => '2026-10-05', 'provider_order_id' => $propia->id])
             ->assertStatus(200)->assertJsonPath('model.code', 'P2');
        $this->putJson('api/provider-order-afip-ticket-iva/' . $iva_id, ['provider_order_afip_ticket_id' => $factura_id, 'iva_id' => 1, 'neto' => 200, 'iva_importe' => 42])->assertStatus(200);
        $this->putJson('api/provider-order-extra-cost/' . $costo_id, ['description' => 'Flete 2', 'value' => 20, 'facturado' => 0, 'en_factura_compra' => 0])->assertStatus(200)->assertJsonPath('model.description', 'Flete 2');
        $this->putJson('api/provider-order-discount/' . $descuento_id, ['description' => 'Desc 2', 'percentage' => 4])->assertStatus(200)->assertJsonPath('model.description', 'Desc 2');

        // El empleado cuenta como su dueño.
        $empleado = $this->crear_usuario('Empleado hijos', 'hijos-tenencia-empleado-', $propia->user_id);
        $this->actuar_como($empleado);

        $this->getJson('api/provider-order-afip-ticket/' . $factura_id)->assertStatus(200);
        $this->getJson('api/provider-order-extra-cost/' . $costo_id)->assertStatus(200);
        $this->putJson('api/provider-order-discount/' . $descuento_id, ['description' => 'Desc 3', 'percentage' => 4])->assertStatus(200);

        // Y el otro dueño no ve nada de esto.
        $this->actuar_como($this->otro_dueno());

        $this->assertSame(404, $this->getJson('api/provider-order-afip-ticket/' . $factura_id)->getStatusCode());
        $this->assertSame(404, $this->getJson('api/provider-order-afip-ticket-iva/' . $iva_id)->getStatusCode());
        $this->assertSame(404, $this->getJson('api/provider-order-extra-cost/' . $costo_id)->getStatusCode());
        $this->assertSame(404, $this->getJson('api/provider-order-discount/' . $descuento_id)->getStatusCode());

        // Borrado propio.
        $this->actuar_como($this->usuario_de_prueba());

        $this->deleteJson('api/provider-order-afip-ticket-iva/' . $iva_id)->assertStatus(200);
        $this->deleteJson('api/provider-order-extra-cost/' . $costo_id)->assertStatus(200);
        $this->deleteJson('api/provider-order-discount/' . $descuento_id)->assertStatus(200);
        $this->deleteJson('api/provider-order-afip-ticket/' . $factura_id)->assertStatus(200);

        $this->assertNull(ProviderOrderAfipTicket::find($factura_id));
    }

    /**
     * En el cuerpo de la compra, el `provider_id` y los `articles` tienen que ser del dueño: con un
     * proveedor ajeno la compra se colgaba de la cuenta corriente de otro comercio; con un artículo
     * ajeno le movía el stock y los precios.
     *
     * @test
     */
    public function una_compra_con_proveedor_o_articulo_ajeno_no_se_guarda()
    {
        $otro = $this->otro_dueno();

        $proveedor_ajeno = Provider::create(['name' => 'Proveedor ajeno ' . uniqid(), 'user_id' => $otro->id]);
        $this->proveedores_creados[] = $proveedor_ajeno->id;

        $articulo_ajeno = Article::create(['name' => 'Articulo ajeno ' . uniqid(), 'user_id' => $otro->id, 'stock' => 7, 'cost' => 1, 'price' => 1]);
        $this->articulos_creados[] = $articulo_ajeno->id;

        $base = [
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
        ];

        $compras_antes = ProviderOrder::count();

        // Alta con proveedor ajeno.
        $r = $this->postJson('api/provider-order', $this->payload_compra(array_merge($base, [
            'provider_id'        => $proveedor_ajeno->id,
            'numero_comprobante' => 'PROV-AJENO',
            'articles'           => [$this->item('Marco para cama', 1000, 1)],
        ])));
        $this->assertSame(404, $r->getStatusCode(), 'POST con proveedor ajeno: ' . $r->getContent());

        // Alta con artículo ajeno.
        $r = $this->postJson('api/provider-order', $this->payload_compra(array_merge($base, [
            'provider_id'        => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'numero_comprobante' => 'ART-AJENO',
            'articles'           => [array_merge($this->item('Marco para cama', 1000, 1), ['id' => $articulo_ajeno->id])],
        ])));
        $this->assertSame(404, $r->getStatusCode(), 'POST con artículo ajeno: ' . $r->getContent());

        $this->assertSame($compras_antes, ProviderOrder::count(), 'No se creó ninguna compra.');

        // Edición de una compra propia con proveedor / artículo ajeno.
        $propia = $this->compra_propia();
        $foto = $propia->fresh()->toArray();

        $r = $this->putJson('api/provider-order/' . $propia->id, $this->payload_compra(array_merge($base, [
            'provider_id'        => $proveedor_ajeno->id,
            'numero_comprobante' => 'PISADA',
            'articles'           => [$this->item('Marco para cama', 1000, 1)],
        ])));
        $this->assertSame(404, $r->getStatusCode(), 'PUT con proveedor ajeno: ' . $r->getContent());

        $r = $this->putJson('api/provider-order/' . $propia->id, $this->payload_compra(array_merge($base, [
            'provider_id'        => $propia->provider_id,
            'numero_comprobante' => 'PISADA',
            'articles'           => [array_merge($this->item('Marco para cama', 1000, 1), ['id' => $articulo_ajeno->id])],
        ])));
        $this->assertSame(404, $r->getStatusCode(), 'PUT con artículo ajeno: ' . $r->getContent());

        $this->assertEquals($foto, $propia->fresh()->toArray(), 'La compra propia quedó como estaba.');
        $this->assertSame(7, (int) Article::find($articulo_ajeno->id)->stock, 'El stock del artículo ajeno no se movió.');
    }

    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $ids = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->pluck('id')->all();

            ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();
            ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
            ProviderOrderExtraCost::where('provider_order_id', $compra_id)->delete();
            ProviderOrderDiscount::where('provider_order_id', $compra_id)->delete();
            CurrentAcount::where('provider_order_id', $compra_id)->delete();
            DB::table('article_provider_order')->where('provider_order_id', $compra_id)->delete();
            ProviderOrder::where('id', $compra_id)->delete();
        }

        if (count($this->articulos_creados) > 0) {
            Article::withTrashed()->whereIn('id', $this->articulos_creados)->forceDelete();
        }

        if (count($this->proveedores_creados) > 0) {
            Provider::withTrashed()->whereIn('id', $this->proveedores_creados)->forceDelete();
        }

        if (count($this->usuarios_creados) > 0) {
            User::whereIn('id', $this->usuarios_creados)->delete();
        }

        parent::tearDown();
    }
}
