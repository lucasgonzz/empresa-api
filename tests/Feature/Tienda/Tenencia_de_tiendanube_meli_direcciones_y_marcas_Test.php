<?php

namespace Tests\Feature\Tienda;

use App\Models\Address;
use App\Models\Brand;
use App\Models\Buyer;
use App\Models\MeliOrder;
use App\Models\TiendaNubeOrder;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión `tenencia-tiendanube-meli-direcciones-marcas` (19/10/2026) — segunda tanda de la TENENCIA
 * de los registros que llegan por id de ruta (la primera: `Tenencia_de_la_tienda_online_Test`).
 *
 * `TiendaNubeOrderController` (show/update/destroy), `AddressController` (show/update; el destroy ya
 * estaba guardado), `MeLiOrderController` (show/update/destroy/create_sale; el update comparaba el
 * dueño pero respondía 403) y `BrandController` (show/update/destroy) resolvían el registro con
 * `find($id)` (o `fullModel`) sin cruzarlo con el dueño de la sesión. En una base compartida entre
 * comercios (ids correlativos) un usuario podía leer, editar, borrar —o convertir en venta— el pedido,
 * la sucursal o la marca de OTRO comercio mandando su id.
 *
 * Criterio: lo ajeno se contesta EXACTAMENTE igual que lo inexistente o que lo que no es un id (404
 * JSON con `message`) y se resuelve ANTES de leer o escribir. Para el dueño —y su empleado— la
 * respuesta es la de siempre.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group tienda
 */
class Tenencia_de_tiendanube_meli_direcciones_y_marcas_Test extends EmpresaTestCase
{
    /** @var User|null */
    protected $otro_dueno = null;

    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {
            $this->otro_dueno = $this->crear_usuario('Otro comercio marketplaces', 'marketplaces-tenencia-otro-', null);
        }

        return $this->otro_dueno;
    }

    protected function crear_usuario($nombre, $prefijo_email, $owner_id)
    {
        return User::create([
            'name'     => $nombre,
            'email'    => $prefijo_email . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $owner_id,
        ]);
    }

    /** El `Auth::forgetGuards()` no es decorativo: sanctum cachea el usuario de la primera request. */
    protected function actuar_como(User $usuario)
    {
        Auth::forgetGuards();

        $this->actingAs($usuario, 'web');
    }

    protected function usuario_de_prueba()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    // ------------------------------------------------------------------ fábricas

    protected function pedido_tienda_nube($user_id)
    {
        return TiendaNubeOrder::create([
            'user_id'                     => $user_id,
            'external_id'                 => random_int(100000, 99999999),
            'customer_name'               => 'Comprador TN ' . $user_id,
            'payment_status'              => 'paid',
            'tienda_nube_order_status_id' => 1,
            'notes'                       => 'nota original',
            'total'                       => 1500,
        ]);
    }

    protected function pedido_meli($user_id)
    {
        return MeliOrder::create([
            'user_id'              => $user_id,
            'meli_order_id'        => 'ML' . uniqid(),
            'status'               => 'paid',
            'meli_order_status_id' => 1,
            'notes'                => 'nota original',
            'total'                => 2500,
        ]);
    }

    protected function sucursal($user_id)
    {
        return Address::create([
            'user_id'       => $user_id,
            'street'        => 'Calle original',
            'street_number' => '100',
            'city'          => 'La Plata',
            'province'      => 'Buenos Aires',
        ]);
    }

    protected function marca($user_id)
    {
        return Brand::create([
            'user_id' => $user_id,
            'name'    => 'Marca ' . uniqid(),
        ]);
    }

    /**
     * La matriz: ruta, fábrica, verbos que existen, payload de un PUT que anda para el dueño y
     * código de éxito de cada verbo.
     *
     * @return array<string,array<string,mixed>>
     */
    protected function matriz()
    {
        return [
            'tienda-nube-order' => [
                'fabrica' => 'pedido_tienda_nube',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['notes' => 'cambiada', 'tienda_nube_order_status_id' => 1, 'address_id' => null],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
            'address' => [
                'fabrica' => 'sucursal',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['street' => 'Calle cambiada', 'street_number' => '200', 'city' => 'Quilmes', 'province' => 'Buenos Aires'],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
            'meli-order' => [
                'fabrica' => 'pedido_meli',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['notes' => 'cambiada', 'meli_order_status_id' => 1, 'address_id' => null],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
            'brand' => [
                'fabrica' => 'marca',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['name' => 'Marca cambiada'],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
        ];
    }

    protected function llamar($verbo, $url, array $payload = [])
    {
        if ($verbo === 'GET') {
            return $this->getJson($url);
        }
        if ($verbo === 'PUT') {
            return $this->putJson($url, $payload);
        }
        if ($verbo === 'POST') {
            return $this->postJson($url, $payload);
        }

        return $this->deleteJson($url);
    }

    protected function fila($ruta, $id)
    {
        $clases = [
            'tienda-nube-order' => TiendaNubeOrder::class,
            'address'           => Address::class,
            'meli-order'        => MeliOrder::class,
            'brand'             => Brand::class,
        ];

        return $clases[$ruta]::find($id);
    }

    // ------------------------------------------------------------------ casos

    /**
     * Lo AJENO da 404 en cada verbo de cada controller y la fila del otro comercio queda como estaba
     * (ni leída con datos, ni editada, ni borrada).
     *
     * @test
     */
    public function lo_ajeno_da_404_y_no_se_toca()
    {
        foreach ($this->matriz() as $ruta => $caso) {
            $ajeno = $this->{$caso['fabrica']}($this->otro_dueno()->id);
            $foto  = $ajeno->fresh()->toArray();

            foreach ($caso['verbos'] as $verbo) {
                $url = 'api/' . $ruta . '/' . $ajeno->id;
                $r   = $this->llamar($verbo, $url, $caso['put']);

                $this->assertSame(404, $r->getStatusCode(), "$verbo $url ajeno: " . $r->getContent());
                $this->assertArrayHasKey('message', $r->json(), "$verbo $url: el 404 trae message.");
                $this->assertArrayNotHasKey('model', $r->json(), "$verbo $url: no se devuelve el registro ajeno.");
            }

            $this->assertEquals($foto, $this->fila($ruta, $ajeno->id)->toArray(), "$ruta ajeno quedó como estaba.");
        }
    }

    /**
     * Lo inexistente y lo que no es un id contestan lo mismo (404), no un 500.
     *
     * @test
     */
    public function lo_inexistente_y_lo_que_no_es_un_id_dan_404()
    {
        foreach ($this->matriz() as $ruta => $caso) {
            foreach (['99999999', 'abc', '12abc', '0'] as $id) {
                foreach ($caso['verbos'] as $verbo) {
                    $url = 'api/' . $ruta . '/' . $id;
                    $r   = $this->llamar($verbo, $url, $caso['put']);

                    $this->assertSame(404, $r->getStatusCode(), "$verbo $url: " . $r->getContent());
                }

                if ($ruta === 'meli-order') {
                    $r = $this->postJson('api/meli-order/create-sale/' . $id);
                    $this->assertSame(404, $r->getStatusCode(), "POST create-sale/$id: " . $r->getContent());
                }
            }
        }
    }

    /**
     * El pedido de Mercado Libre ajeno no se convierte en venta de nadie: `create-sale` da 404 y no
     * crea ninguna venta; el `update` ajeno dejó de ser 403 y es el mismo 404 que lo inexistente.
     *
     * @test
     */
    public function el_pedido_meli_ajeno_no_se_convierte_en_venta_y_el_update_ya_no_es_403()
    {
        $ajeno  = $this->pedido_meli($this->otro_dueno()->id);
        $foto   = $ajeno->fresh()->toArray();
        $ventas = \App\Models\Sale::count();

        $r = $this->postJson('api/meli-order/create-sale/' . $ajeno->id);
        $this->assertSame(404, $r->getStatusCode(), $r->getContent());
        $this->assertSame($ventas, \App\Models\Sale::count(), 'No se creó ninguna venta.');

        // Confirmar un pedido pago ajeno dispararía la venta: tampoco.
        $r = $this->putJson('api/meli-order/' . $ajeno->id, ['notes' => 'x', 'meli_order_status_id' => 2, 'address_id' => null]);
        $this->assertSame(404, $r->getStatusCode(), $r->getContent());
        $this->assertSame($ventas, \App\Models\Sale::count(), 'No se creó ninguna venta.');

        $this->assertEquals($foto, $ajeno->fresh()->toArray(), 'El pedido ajeno quedó como estaba.');
    }

    /**
     * El dueño sigue pudiendo todo, con la forma de respuesta de siempre: leer (200 con `model`),
     * editar (con `model`) y borrar (la fila desaparece). Y en el pedido de Mercado Libre, crear la
     * venta a mano.
     *
     * @test
     */
    public function el_dueno_sigue_pudiendo_leer_editar_y_borrar_lo_suyo()
    {
        $yo = $this->usuario_de_prueba()->id;

        foreach ($this->matriz() as $ruta => $caso) {
            foreach ($caso['verbos'] as $verbo) {
                $propio = $this->{$caso['fabrica']}($yo);
                $url    = 'api/' . $ruta . '/' . $propio->id;
                $r      = $this->llamar($verbo, $url, $caso['put']);

                $this->assertSame($caso['exito'][$verbo], $r->getStatusCode(), "$verbo $url propio: " . $r->getContent());

                if ($verbo !== 'DELETE') {
                    $this->assertSame($propio->id, (int) $r->json('model.id'), "$verbo $url propio devuelve su model.");
                } else {
                    $this->assertNull($this->fila($ruta, $propio->id), "$ruta propio borrado.");
                }
            }
        }
    }

    /**
     * El dueño crea la venta de su pedido de Mercado Libre pago: la respuesta sigue siendo 200 con el
     * `model` (la guarda no cambia el camino feliz).
     *
     * @test
     */
    public function el_dueno_sigue_creando_la_venta_de_su_pedido_meli()
    {
        $propio = $this->pedido_meli($this->usuario_de_prueba()->id);

        $r = $this->postJson('api/meli-order/create-sale/' . $propio->id);

        $this->assertSame(200, $r->getStatusCode(), $r->getContent());
        $this->assertSame($propio->id, (int) $r->json('model.id'));
    }

    /**
     * Lo que edita el dueño se guarda de verdad (no solo el 200).
     *
     * @test
     */
    public function la_edicion_del_dueno_se_guarda()
    {
        $yo = $this->usuario_de_prueba()->id;

        $tn = $this->pedido_tienda_nube($yo);
        $this->putJson('api/tienda-nube-order/' . $tn->id, ['notes' => 'editada TN', 'tienda_nube_order_status_id' => 1, 'address_id' => null])->assertStatus(200);
        $this->assertSame('editada TN', $tn->fresh()->notes);

        $ml = $this->pedido_meli($yo);
        $this->putJson('api/meli-order/' . $ml->id, ['notes' => 'editada ML', 'meli_order_status_id' => 1, 'address_id' => null])->assertStatus(200);
        $this->assertSame('editada ML', $ml->fresh()->notes);

        $sucursal = $this->sucursal($yo);
        $this->putJson('api/address/' . $sucursal->id, ['street' => 'Calle nueva', 'street_number' => '300', 'city' => 'La Plata', 'province' => 'Buenos Aires'])->assertStatus(200);
        $this->assertSame('Calle nueva', $sucursal->fresh()->street);

        $marca = $this->marca($yo);
        $this->putJson('api/brand/' . $marca->id, ['name' => 'Marca editada'])->assertStatus(200);
        $this->assertSame('Marca editada', $marca->fresh()->name);
    }

    /**
     * La guarda de la sucursal va ANTES de la validación del ajuste de precios: un ajuste inválido
     * sobre una sucursal PROPIA sigue dando 422, y sobre una AJENA da 404 (no se filtra que existe).
     *
     * @test
     */
    public function el_ajuste_invalido_da_422_al_dueno_y_404_sobre_una_sucursal_ajena()
    {
        $propia = $this->sucursal($this->usuario_de_prueba()->id);
        $ajena  = $this->sucursal($this->otro_dueno()->id);
        $cuerpo = ['street' => 'X', 'ajuste_precio_tipo' => 'recargo', 'ajuste_precio_porcentaje' => 5000];

        $this->assertSame(422, $this->putJson('api/address/' . $propia->id, $cuerpo)->getStatusCode());
        $this->assertSame(404, $this->putJson('api/address/' . $ajena->id, $cuerpo)->getStatusCode());
    }

    /**
     * Una dirección de un COMPRADOR del dueño (`buyer_id`, sin `user_id` del dueño) sigue siendo
     * operable por él —igual que su `destroy`—; la de un comprador de otro comercio, no.
     *
     * @test
     */
    public function la_direccion_de_un_comprador_del_dueno_sigue_siendo_suya()
    {
        $yo      = $this->usuario_de_prueba()->id;
        $propio  = Buyer::create(['user_id' => $yo, 'name' => 'Comprador propio', 'email' => 'cp-' . uniqid() . '@test.local']);
        $ajeno   = Buyer::create(['user_id' => $this->otro_dueno()->id, 'name' => 'Comprador ajeno', 'email' => 'ca-' . uniqid() . '@test.local']);
        $dir_p   = Address::create(['buyer_id' => $propio->id, 'street' => 'Calle del comprador', 'street_number' => '1']);
        $dir_a   = Address::create(['buyer_id' => $ajeno->id, 'street' => 'Calle del ajeno', 'street_number' => '2']);

        $this->getJson('api/address/' . $dir_p->id)->assertStatus(200);
        $this->putJson('api/address/' . $dir_p->id, ['street' => 'Cambiada', 'street_number' => '1'])->assertStatus(200);
        $this->assertSame('Cambiada', $dir_p->fresh()->street);

        $this->getJson('api/address/' . $dir_a->id)->assertStatus(404);
        $this->putJson('api/address/' . $dir_a->id, ['street' => 'Robada', 'street_number' => '2'])->assertStatus(404);
        $this->assertSame('Calle del ajeno', $dir_a->fresh()->street);
    }

    /**
     * Un EMPLEADO del dueño opera lo del dueño (`userId()` lo resuelve al dueño); el empleado de
     * OTRO comercio, no.
     *
     * @test
     */
    public function el_empleado_opera_lo_de_su_dueno_y_el_de_otro_no()
    {
        $dueno    = $this->usuario_de_prueba();
        $marca    = $this->marca($dueno->id);
        $pedido   = $this->pedido_tienda_nube($dueno->id);
        $empleado = $this->crear_usuario('Empleado marketplaces', 'marketplaces-tenencia-empleado-', $dueno->id);

        $this->actuar_como($empleado);

        $this->getJson('api/brand/' . $marca->id)->assertStatus(200);
        $this->putJson('api/brand/' . $marca->id, ['name' => 'Del empleado'])->assertStatus(200);
        $this->assertSame('Del empleado', $marca->fresh()->name);
        $this->getJson('api/tienda-nube-order/' . $pedido->id)->assertStatus(200);

        $ajeno_empleado = $this->crear_usuario('Empleado de otro', 'marketplaces-tenencia-empleado-otro-', $this->otro_dueno()->id);

        $this->actuar_como($ajeno_empleado);

        $this->getJson('api/brand/' . $marca->id)->assertStatus(404);
        $this->putJson('api/brand/' . $marca->id, ['name' => 'Intruso'])->assertStatus(404);
        $this->deleteJson('api/brand/' . $marca->id)->assertStatus(404);
        $this->deleteJson('api/tienda-nube-order/' . $pedido->id)->assertStatus(404);

        $this->assertSame('Del empleado', $marca->fresh()->name);
        $this->assertNotNull($pedido->fresh(), 'El pedido del dueño sigue existiendo.');
    }

    /**
     * El `index` de cada controller ya filtraba por dueño: no se rompió.
     *
     * @test
     */
    public function el_listado_sigue_trayendo_solo_lo_propio()
    {
        $yo    = $this->usuario_de_prueba()->id;
        $mia   = $this->marca($yo);
        $ajena = $this->marca($this->otro_dueno()->id);

        $ids = collect($this->getJson('api/brand')->assertStatus(200)->json('models'))->pluck('id')->all();

        $this->assertContains($mia->id, $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    /**
     * El borrado masivo genérico (`PUT api/delete/{modelo}`) delega en estos `destroy()`: lo ajeno no
     * se borra, no se cuenta como eliminado ni se devuelve en `models`; lo propio se borra como siempre.
     *
     * @test
     */
    public function el_borrado_masivo_no_borra_ni_devuelve_lo_ajeno()
    {
        $yo = $this->usuario_de_prueba()->id;

        $casos = [
            'tienda_nube_order' => 'pedido_tienda_nube',
            'meli_order'        => 'pedido_meli',
            'brand'             => 'marca',
            'address'           => 'sucursal',
        ];

        foreach ($casos as $modelo => $fabrica) {
            $ajeno  = $this->{$fabrica}($this->otro_dueno()->id);
            $propio = $this->{$fabrica}($yo);

            // De a uno: con más de un id el borrado masivo corre en segundo plano (job) y la respuesta
            // no trae el detalle.
            $r_ajeno = $this->putJson('api/delete/' . $modelo, ['models_id' => [$ajeno->id]]);
            $r_ajeno->assertStatus(200);
            $this->assertSame([], $r_ajeno->json('models'), "$modelo: lo ajeno no figura como eliminado.");
            $this->assertSame([(int) $ajeno->id], collect($r_ajeno->json('not_deleted'))->pluck('id')->map(function ($i) { return (int) $i; })->all(), "$modelo: lo ajeno figura como rechazado.");

            $r_propio = $this->putJson('api/delete/' . $modelo, ['models_id' => [$propio->id]]);
            $r_propio->assertStatus(200);
            $this->assertSame([(int) $propio->id], collect($r_propio->json('models'))->pluck('id')->map(function ($i) { return (int) $i; })->all(), "$modelo: lo propio figura como eliminado.");

            $this->assertNotNull($ajeno->fresh(), "$modelo ajeno sigue existiendo.");
            $this->assertNull($propio->fresh(), "$modelo propio se borró.");
        }
    }
}
