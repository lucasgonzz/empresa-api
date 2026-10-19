<?php

namespace Tests\Feature\Tienda;

use App\Models\Buyer;
use App\Models\DeliveryDay;
use App\Models\DeliveryZone;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodInstallment;
use App\Models\Title;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión `tenencia-tienda-online` (19/10/2026) — la TENENCIA de los registros de la tienda online
 * que llegan por id de ruta.
 *
 * `DeliveryZoneController`, `OnlineConfigurationController`, `DeliveryDayController`,
 * `TitleController`, `PaymentMethodController`, `BuyerController` y `OrderController` resolvían
 * `show`/`update`/`destroy` con `Model::find($id)` (o `fullModel`) sin cruzar el id con el dueño de
 * la sesión. En una base compartida entre comercios (ids correlativos) un usuario podía leer, editar
 * o borrar la forma de envío, la configuración online, el banner, el método de pago (con sus
 * credenciales de Mercado Pago), el comprador o el pedido de OTRO comercio mandando su id.
 *
 * Criterio: lo ajeno se contesta EXACTAMENTE igual que lo inexistente o que lo que no es un id (404)
 * y se resuelve ANTES de leer o escribir. Lo AJENO se crea a mano con el `user_id` de otro dueño; lo
 * PROPIO, también a mano pero con el dueño de la sesión, y se opera por los endpoints reales. Para el
 * dueño —y para su empleado— la respuesta es la de siempre.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group tienda
 */
class Tenencia_de_la_tienda_online_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** @var User|null */
    protected $otro_dueno = null;

    /** @var array<int,int> */
    protected $usuarios_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {
            $this->otro_dueno = $this->crear_usuario('Otro comercio tienda', 'tienda-tenencia-otro-', null);
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

    protected function usuario_de_prueba()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    // ------------------------------------------------------------------ fábricas

    protected function forma_de_envio($user_id)
    {
        return DeliveryZone::create([
            'user_id'          => $user_id,
            'name'             => 'Moto en el día',
            'description'      => 'Entrega en 2 horas',
            'price'            => 1200,
            'costo_a_convenir' => 0,
        ]);
    }

    protected function configuracion_online($user_id)
    {
        return OnlineConfiguration::create(['user_id' => $user_id]);
    }

    protected function dia_de_entrega($user_id)
    {
        return DeliveryDay::create(['user_id' => $user_id, 'day_of_week' => 2]);
    }

    protected function banner($user_id)
    {
        return Title::create([
            'user_id' => $user_id,
            'header'  => 'Ofertas de octubre',
            'lead'    => 'Hasta el 31',
        ]);
    }

    protected function metodo_de_pago($user_id)
    {
        return PaymentMethod::create([
            'user_id'      => $user_id,
            'name'         => 'Mercado Pago',
            'access_token' => 'token-secreto-' . $user_id,
        ]);
    }

    protected function comprador($user_id)
    {
        return Buyer::create([
            'user_id' => $user_id,
            'name'    => 'Comprador ' . $user_id,
            'phone'   => '2216000000',
            'email'   => 'comprador-tenencia-' . uniqid() . '@test.local',
        ]);
    }

    protected function pedido($user_id)
    {
        $comprador = $this->comprador($user_id);

        return Order::create([
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => $this->estado('Sin confirmar')->id,
            'user_id'         => $user_id,
            'address_id'      => $this->deposito()->id,
            'total'           => 0,
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
            'delivery-zone' => [
                'fabrica' => 'forma_de_envio',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['name' => 'Cambiada', 'description' => 'x', 'price' => 900],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
            'online-configuration' => [
                'fabrica' => 'configuracion_online',
                'verbos'  => ['PUT'],
                'put'     => ['instagram' => 'cambiado'],
                'exito'   => ['PUT' => 200],
            ],
            'delivery-day' => [
                'fabrica' => 'dia_de_entrega',
                'verbos'  => ['PUT', 'DELETE'],
                'put'     => ['day_of_week' => 5],
                'exito'   => ['PUT' => 200, 'DELETE' => 200],
            ],
            'title' => [
                'fabrica' => 'banner',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['header' => 'Cambiado', 'lead' => 'otro'],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
            'payment-method' => [
                'fabrica' => 'metodo_de_pago',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['name' => 'Cambiado', 'discount' => 5],
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
            'buyer' => [
                'fabrica' => 'comprador',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => ['name' => 'Cambiado', 'email' => 'cambiado-tenencia@test.local', 'phone' => '1'],
                'exito'   => ['GET' => 200, 'PUT' => 201, 'DELETE' => 200],
            ],
            'order' => [
                'fabrica' => 'pedido',
                'verbos'  => ['GET', 'PUT', 'DELETE'],
                'put'     => null, // se arma con el estado actual, ver payload_de_put()
                'exito'   => ['GET' => 200, 'PUT' => 200, 'DELETE' => 200],
            ],
        ];
    }

    protected function payload_de_put($ruta, $caso, $fila = null)
    {
        if ($ruta === 'order') {
            return $this->payload_de_estado('Sin confirmar');
        }

        // El PUT de la configuración online escribe TODAS las columnas del request (las que faltan
        // llegan en null y rompen las NOT NULL): la pantalla manda el modelo entero.
        if ($ruta === 'online-configuration' && !is_null($fila)) {
            return array_merge($fila->fresh()->toArray(), $caso['put']);
        }

        return $caso['put'];
    }

    protected function llamar($verbo, $url, array $payload = [])
    {
        if ($verbo === 'GET') {
            return $this->getJson($url);
        }
        if ($verbo === 'PUT') {
            return $this->putJson($url, $payload);
        }

        return $this->deleteJson($url);
    }

    protected function fila($ruta, $id)
    {
        $clases = [
            'delivery-zone'        => DeliveryZone::class,
            'online-configuration' => OnlineConfiguration::class,
            'delivery-day'         => DeliveryDay::class,
            'title'                => Title::class,
            'payment-method'       => PaymentMethod::class,
            'buyer'                => Buyer::class,
            'order'                => Order::class,
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
                $r   = $this->llamar($verbo, $url, $this->payload_de_put($ruta, $caso, $ajeno));

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
                    $r   = $this->llamar($verbo, $url, $this->payload_de_put($ruta, $caso));

                    $this->assertSame(404, $r->getStatusCode(), "$verbo $url: " . $r->getContent());
                }
            }
        }
    }

    /**
     * El dueño sigue pudiendo todo, con la forma de respuesta de siempre: leer (200 con `model`),
     * editar (con `model`) y borrar (la fila desaparece).
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
                $r      = $this->llamar($verbo, $url, $this->payload_de_put($ruta, $caso, $propio));

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
     * Lo que edita el dueño se guarda de verdad (no solo el 200).
     *
     * @test
     */
    public function la_edicion_del_dueno_se_guarda()
    {
        $yo = $this->usuario_de_prueba()->id;

        $forma = $this->forma_de_envio($yo);
        $this->putJson('api/delivery-zone/' . $forma->id, ['name' => 'Retiro en local', 'description' => 'x', 'price' => 0])->assertStatus(200);
        $this->assertSame('Retiro en local', $forma->fresh()->name);

        $config = $this->configuracion_online($yo);
        $this->putJson('api/online-configuration/' . $config->id, array_merge($config->fresh()->toArray(), ['instagram' => 'mi_tienda']))->assertStatus(200);
        $this->assertSame('mi_tienda', $config->fresh()->instagram);

        $metodo = $this->metodo_de_pago($yo);
        $this->putJson('api/payment-method/' . $metodo->id, ['name' => 'Transferencia'])->assertStatus(200);
        $this->assertSame('Transferencia', $metodo->fresh()->name);
        $this->assertSame('token-secreto-' . $yo, $metodo->fresh()->access_token, 'Un PUT sin access_token no lo borra (comportamiento previo).');
    }

    /**
     * Un EMPLEADO del dueño ve y opera lo del dueño (`userId()` lo resuelve al dueño); el empleado
     * de OTRO comercio, no.
     *
     * @test
     */
    public function el_empleado_opera_lo_de_su_dueno_y_el_de_otro_no()
    {
        $dueno    = $this->usuario_de_prueba();
        $forma    = $this->forma_de_envio($dueno->id);
        $banner   = $this->banner($dueno->id);
        $empleado = $this->crear_usuario('Empleado tienda', 'tienda-tenencia-empleado-', $dueno->id);

        $this->actuar_como($empleado);

        $this->getJson('api/delivery-zone/' . $forma->id)->assertStatus(200);
        $this->putJson('api/title/' . $banner->id, ['header' => 'Del empleado', 'lead' => 'x'])->assertStatus(200);
        $this->assertSame('Del empleado', $banner->fresh()->header);

        $ajeno_empleado = $this->crear_usuario('Empleado de otro', 'tienda-tenencia-empleado-otro-', $this->otro_dueno()->id);

        $this->actuar_como($ajeno_empleado);

        $this->getJson('api/delivery-zone/' . $forma->id)->assertStatus(404);
        $this->putJson('api/title/' . $banner->id, ['header' => 'Intruso', 'lead' => 'x'])->assertStatus(404);
        $this->deleteJson('api/delivery-zone/' . $forma->id)->assertStatus(404);

        $this->assertSame('Del empleado', $banner->fresh()->header);
        $this->assertNotNull($forma->fresh(), 'La forma de envío del dueño sigue existiendo.');
    }

    /**
     * El `index` de cada controller ya filtraba por dueño: no se rompió.
     *
     * @test
     */
    public function el_listado_sigue_trayendo_solo_lo_propio()
    {
        $yo    = $this->usuario_de_prueba()->id;
        $mia   = $this->forma_de_envio($yo);
        $ajena = $this->forma_de_envio($this->otro_dueno()->id);

        $ids = collect($this->getJson('api/delivery-zone')->assertStatus(200)->json('models'))->pluck('id')->all();

        $this->assertContains($mia->id, $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    /**
     * Las cuotas de un método de pago (`payment-method-installment`) no tienen `user_id`: su dueño es
     * el del método del que cuelgan. Ajeno → 404 sin tocar nada; propio → como siempre; y un alta
     * colgada de un método ajeno → 404 sin crear nada.
     *
     * @test
     */
    public function las_cuotas_de_un_metodo_de_pago_ajeno_dan_404()
    {
        $yo      = $this->usuario_de_prueba()->id;
        $ajeno   = $this->metodo_de_pago($this->otro_dueno()->id);
        $propio  = $this->metodo_de_pago($yo);
        $cuota_a = PaymentMethodInstallment::create(['payment_method_id' => $ajeno->id, 'name' => 'Ajena', 'installments' => 3]);
        $foto    = $cuota_a->fresh()->toArray();

        $this->getJson('api/payment-method-installment/' . $cuota_a->id)->assertStatus(404);
        $this->putJson('api/payment-method-installment/' . $cuota_a->id, ['name' => 'Robada', 'installments' => 12])->assertStatus(404);
        $this->deleteJson('api/payment-method-installment/' . $cuota_a->id)->assertStatus(404);
        $this->getJson('api/payment-method-installment/99999999')->assertStatus(404);
        $this->getJson('api/payment-method-installment/abc')->assertStatus(404);
        $this->assertEquals($foto, $cuota_a->fresh()->toArray(), 'La cuota ajena quedó como estaba.');

        $antes = PaymentMethodInstallment::where('payment_method_id', $ajeno->id)->count();
        $this->postJson('api/payment-method-installment', ['model_id' => $ajeno->id, 'name' => 'Colgada', 'installments' => 6])->assertStatus(404);
        $this->assertSame($antes, PaymentMethodInstallment::where('payment_method_id', $ajeno->id)->count(), 'No se le colgó nada al método ajeno.');

        $alta = $this->postJson('api/payment-method-installment', ['model_id' => $propio->id, 'name' => 'Propia', 'installments' => 6]);
        $alta->assertStatus(201);
        $id = (int) $alta->json('model.id');

        $this->getJson('api/payment-method-installment/' . $id)->assertStatus(200);
        $this->putJson('api/payment-method-installment/' . $id, ['name' => 'Editada', 'installments' => 9])->assertStatus(200);
        $this->assertSame('Editada', PaymentMethodInstallment::find($id)->name);
        $this->deleteJson('api/payment-method-installment/' . $id)->assertStatus(200);
        $this->assertNull(PaymentMethodInstallment::find($id));
    }

    /**
     * La guarda va ANTES de la validación del vínculo de cuotas de `PaymentMethodController@update`:
     * un vínculo inválido sobre un método PROPIO sigue dando 422, y sobre uno AJENO da 404.
     *
     * @test
     */
    public function el_vinculo_invalido_da_422_al_dueno_y_404_sobre_un_metodo_ajeno()
    {
        $propio = $this->metodo_de_pago($this->usuario_de_prueba()->id);
        $ajeno  = $this->metodo_de_pago($this->otro_dueno()->id);
        $cuerpo = ['name' => 'X', 'current_acount_payment_method_id' => 99999999];

        $this->putJson('api/payment-method/' . $propio->id, $cuerpo)->assertStatus(422);
        $this->putJson('api/payment-method/' . $ajeno->id, $cuerpo)->assertStatus(404);
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
            'delivery_zone'  => 'forma_de_envio',
            'delivery_day'   => 'dia_de_entrega',
            'title'          => 'banner',
            'payment_method' => 'metodo_de_pago',
            'buyer'          => 'comprador',
            'order'          => 'pedido',
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

    /**
     * El empleado del dueño opera también pedidos, compradores y métodos de pago del dueño; el de otro
     * comercio no ve ninguno.
     *
     * @test
     */
    public function el_empleado_tambien_opera_pedidos_compradores_y_metodos_de_pago_del_dueno()
    {
        $dueno    = $this->usuario_de_prueba();
        $pedido   = $this->pedido($dueno->id);
        $comprador = $this->comprador($dueno->id);
        $metodo   = $this->metodo_de_pago($dueno->id);

        $this->actuar_como($this->crear_usuario('Empleado tienda 2', 'tienda-tenencia-emp2-', $dueno->id));

        $this->getJson('api/order/' . $pedido->id)->assertStatus(200);
        $this->getJson('api/buyer/' . $comprador->id)->assertStatus(200);
        $this->getJson('api/payment-method/' . $metodo->id)->assertStatus(200);

        $this->actuar_como($this->crear_usuario('Empleado de otro 2', 'tienda-tenencia-emp2-otro-', $this->otro_dueno()->id));

        $this->getJson('api/order/' . $pedido->id)->assertStatus(404);
        $this->getJson('api/buyer/' . $comprador->id)->assertStatus(404);
        $this->getJson('api/payment-method/' . $metodo->id)->assertStatus(404);
    }
}
