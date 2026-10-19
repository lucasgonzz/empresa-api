<?php

namespace Tests\Feature\Tienda;

use App\Models\Buyer;
use App\Models\DeliveryDay;
use App\Models\DeliveryZone;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\PaymentMethod;
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
}
