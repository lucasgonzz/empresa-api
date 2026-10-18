<?php

namespace Tests\Feature\Compras;

use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Misión `tenencia-compra-show` (16/10/2026) — la TENENCIA de la compra a proveedor que llega por id
 * en la ruta: `GET`, `PUT` y `DELETE api/provider-order/{id}`.
 *
 * Lo encontró el chequeo adversarial de `asistente-aviso-escaneo-factura` (hallazgo 2): `show()`
 * devolvía `Controller::fullModel('ProviderOrder', $id)`, que busca por id PELADO. En una base
 * compartida (`u767360347_empresa` tiene 51 comercios y los ids son correlativos entre todos) la
 * compra de otro comercio —proveedor, artículos, costos, facturas con sus alícuotas— estaba a un
 * "+1" de distancia. Y `update()` / `destroy()` hacían lo mismo con `where('id', $id)` / `find($id)`:
 * pisaban (y borraban, y rehacían el stock y la cuenta corriente) la compra de otro comercio.
 *
 * El criterio es el de los cheques y las listas de precio: una compra ajena se contesta EXACTAMENTE
 * igual que una inexistente o que algo que no es un id (404 con mensaje de comerciante), y se
 * resuelve ANTES de escribir nada. Lo AJENO se crea a mano con el `user_id` de otro dueño (es la
 * combinación real de una base compartida); lo PROPIO, por los endpoints reales.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Tenencia_de_la_compra_Test extends ComprasTestCase
{
    /** El 404 de una compra ajena, inexistente o que no es un id. */
    const MENSAJE_404 = 'La compra no existe o no es de tu cuenta.';

    /** @var User|null El otro comercio: un dueño (sin owner_id) que vive en la misma base. */
    protected $otro_dueno = null;

    /** @var array<int,int> Usuarios creados a mano por este test. */
    protected $usuarios_creados = [];

    /** @var array<int,int> Compras creadas por el test (propias o ajenas), para la limpieza. */
    protected $compras_creadas = [];

    /**
     * El otro comercio: un dueño sin owner_id, creado una vez por test.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {
            $this->otro_dueno = $this->crear_usuario('Otro comercio compras', 'compras-tenencia-otro-', null);
        }

        return $this->otro_dueno;
    }

    /**
     * Un usuario: dueño si `$owner_id` es null, empleado de ese dueño si no.
     *
     * @param  string  $nombre
     * @param  string  $prefijo_email
     * @param  int|null  $owner_id
     * @return User
     */
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

    /**
     * Cambia el usuario de las requests que siguen. El `Auth::forgetGuards()` no es decorativo: el
     * guard de sanctum cachea el usuario que resolvió la primera vez, y sin olvidarlo el segundo
     * actingAs() no cambia nada (este archivo daría verde en falso).
     *
     * @param  User  $usuario
     * @return void
     */
    protected function actuar_como(User $usuario)
    {
        Auth::forgetGuards();

        $this->actingAs($usuario, 'web');
    }

    /**
     * Una compra de OTRO comercio, insertada a mano, con el proveedor del fixture (da igual a quién
     * pertenezca: lo que se mide es quién ve la compra).
     *
     * @return ProviderOrder
     */
    protected function compra_ajena()
    {
        $compra = ProviderOrder::create([
            'user_id'                  => $this->otro_dueno()->id,
            'provider_id'              => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'provider_order_status_id' => 1,
            'total_with_iva'           => 1,
            'numero_comprobante'       => 'AJENA-' . substr(uniqid(), -6),
        ]);

        $this->compras_creadas[] = $compra->id;

        return $compra;
    }

    /**
     * Una compra PROPIA, por el endpoint real (sin tocar stock ni precios ni cuenta corriente).
     *
     * @param  array<string,mixed>  $overrides
     * @return ProviderOrder
     */
    protected function compra_propia($overrides = [])
    {
        $payload = $this->payload_compra(array_merge([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
            'numero_comprobante'      => 'PROPIA-' . substr(uniqid(), -6),
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ], $overrides));

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $compra_id = $response->json('model.id');

        $this->compras_creadas[] = $compra_id;

        return ProviderOrder::find($compra_id);
    }

    /**
     * La compra ajena no se ve: ni el dato ni el rastro. Cubre el id ajeno, el inexistente y los
     * ids que no son un id (`true` y `12abc` terminaban en la compra 1 y en la 12).
     *
     * @test
     */
    public function una_compra_ajena_no_se_lee_por_id()
    {
        $ajena = $this->compra_ajena();
        $propia = $this->compra_propia();

        $this->assertNotSame($ajena->user_id, $propia->user_id, 'El escenario necesita dos dueños distintos.');

        $response = $this->getJson('api/provider-order/' . $ajena->id);

        $this->assertSame(404, $response->getStatusCode(), 'La compra de otro comercio: ' . $response->getContent());
        $this->assertSame(self::MENSAJE_404, $response->json('message'));
        $this->assertStringNotContainsString($ajena->numero_comprobante, $response->getContent());

        // Un id que no existe, y lo que no es un id: el MISMO 404 (no se confirma qué hay del otro lado).
        $inexistente = ProviderOrder::max('id') + 1000;

        foreach ([(string) $inexistente, 'abc', $propia->id . 'abc', '0', '-1'] as $basura) {

            $response = $this->getJson('api/provider-order/' . $basura);

            $this->assertSame(404, $response->getStatusCode(), 'GET provider-order/' . $basura . ': ' . $response->getContent());
            $this->assertSame(self::MENSAJE_404, $response->json('message'), $basura);
        }
    }

    /**
     * Lo propio sigue andando: el mismo endpoint devuelve la compra con sus relaciones (la pantalla
     * y el asistente leen `articles`, `provider`, `provider_order_afip_tickets`…), y el empleado ve
     * la compra de SU dueño (la tenencia es por cuenta, no por persona).
     *
     * @test
     */
    public function una_compra_propia_se_lee_completa_y_el_empleado_ve_la_de_su_dueno()
    {
        $propia = $this->compra_propia();

        $this->getJson('api/provider-order/' . $propia->id)
             ->assertStatus(200)
             ->assertJsonPath('model.id', $propia->id)
             ->assertJsonPath('model.numero_comprobante', $propia->numero_comprobante)
             ->assertJsonStructure(['model' => ['id', 'provider', 'articles', 'provider_order_afip_tickets', 'provider_order_status']]);

        $this->assertCount(1, $this->getJson('api/provider-order/' . $propia->id)->json('model.articles'));

        $empleado = $this->crear_usuario('Empleado compras', 'compras-tenencia-empleado-', $propia->user_id);

        $this->actuar_como($empleado);

        $this->getJson('api/provider-order/' . $propia->id)
             ->assertStatus(200)
             ->assertJsonPath('model.id', $propia->id);

        // Del otro lado del mostrador: el otro dueño no ve la nuestra.
        $this->actuar_como($this->otro_dueno());

        $this->assertSame(404, $this->getJson('api/provider-order/' . $propia->id)->getStatusCode());

        $ajena = $this->compra_ajena();

        $this->getJson('api/provider-order/' . $ajena->id)
             ->assertStatus(200)
             ->assertJsonPath('model.id', $ajena->id);
    }

    /**
     * `update()` tomaba la compra con `where('id', $id)->lockForUpdate()`, sin dueño: un PUT con el
     * id de otro comercio le reescribía la compra, le rehacía los artículos y le tocaba la cuenta
     * corriente de su proveedor. Ahora corta antes de abrir la transacción.
     *
     * @test
     */
    public function una_compra_ajena_no_se_edita()
    {
        $ajena = $this->compra_ajena();
        $foto = $ajena->fresh()->toArray();

        $payload = $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
            'numero_comprobante'      => 'PISADA',
            'articles'                => [$this->item('Marco para cama', 1000, 1)],
        ]);

        $response = $this->putJson('api/provider-order/' . $ajena->id, $payload);

        $this->assertSame(404, $response->getStatusCode(), 'PUT sobre la compra de otro comercio: ' . $response->getContent());
        $this->assertSame(self::MENSAJE_404, $response->json('message'));
        $this->assertEquals($foto, $ajena->fresh()->toArray(), 'La compra ajena quedó como estaba.');
        $this->assertSame(0, $ajena->articles()->count(), 'No se le colgaron artículos a la compra ajena.');

        // Un id que no existe: el mismo 404 (antes era un 500 por `null->provider_id`).
        $response = $this->putJson('api/provider-order/' . (ProviderOrder::max('id') + 1000), $payload);

        $this->assertSame(404, $response->getStatusCode(), $response->getContent());
        $this->assertSame(self::MENSAJE_404, $response->json('message'));

        // Lo propio se edita.
        $propia = $this->compra_propia();
        $payload['numero_comprobante'] = 'EDITADA-' . substr(uniqid(), -6);

        $this->putJson('api/provider-order/' . $propia->id, $payload)
             ->assertStatus(200)
             ->assertJsonPath('model.numero_comprobante', $payload['numero_comprobante']);
    }

    /**
     * `destroy()` hacía `ProviderOrder::find($id)`: borraba de verdad (la compra no tiene
     * SoftDeletes), devolvía el stock y rehacía la cuenta corriente de la compra de otro comercio.
     *
     * @test
     */
    public function una_compra_ajena_no_se_borra()
    {
        $ajena = $this->compra_ajena();

        $response = $this->deleteJson('api/provider-order/' . $ajena->id);

        $this->assertSame(404, $response->getStatusCode(), 'DELETE sobre la compra de otro comercio: ' . $response->getContent());
        $this->assertSame(self::MENSAJE_404, $response->json('message'));
        $this->assertNotNull(ProviderOrder::find($ajena->id), 'La compra ajena sigue existiendo.');

        $response = $this->deleteJson('api/provider-order/' . (ProviderOrder::max('id') + 1000));

        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        // Lo propio se borra.
        $propia = $this->compra_propia();

        $this->deleteJson('api/provider-order/' . $propia->id)->assertStatus(200);

        $this->assertNull(ProviderOrder::find($propia->id));
    }

    /**
     * El import de Excel de una compra buscaba la compra con `find($request->provider_order_id)`: con
     * el id de otro comercio le adjuntaba los artículos y le movía el stock. Corta con el mismo 404
     * antes de crear ningún registro de importación.
     *
     * @test
     */
    public function el_import_de_excel_sobre_una_compra_ajena_no_hace_nada()
    {
        $ajena = $this->compra_ajena();
        $historias_antes = (int) \App\Models\ImportHistory::max('id');
        $estados_antes = (int) \App\Models\ImportStatus::max('id');

        $archivo = \Illuminate\Http\UploadedFile::fake()->create('compra.xlsx', 10);

        $response = $this->post('api/provider-order/excel/import', [
            'models'            => $archivo,
            'provider_order_id' => $ajena->id,
            'start_row'         => 2,
            'finish_row'        => 5,
        ], ['Accept' => 'application/json']);

        $this->assertSame(404, $response->getStatusCode(), $response->getContent());
        $this->assertSame(0, \App\Models\ImportHistory::where('id', '>', $historias_antes)->count());
        $this->assertSame(0, \App\Models\ImportStatus::where('id', '>', $estados_antes)->count());
        $this->assertSame(0, $ajena->articles()->count());
    }

    /**
     * El test que sostiene la tenencia en el tiempo: toda ruta de `provider-order/{id}` que NO sea
     * una de las tres cerradas acá tiene que estar declarada, con quién resuelve su tenencia. Una
     * ruta nueva con un id pelado hace fallar este test en vez de pasar desapercibida.
     *
     * @test
     */
    public function toda_ruta_de_compra_con_id_declara_como_resuelve_la_tenencia()
    {
        $declaradas = [
            'GET provider-order/{provider_order}'                 => 'show: compra_del_dueno_o_404()',
            'PUT provider-order/{provider_order}'                 => 'update: compra_del_dueno_o_404()',
            'PATCH provider-order/{provider_order}'               => 'update: compra_del_dueno_o_404()',
            'DELETE provider-order/{provider_order}'              => 'destroy: compra_del_dueno_o_404()',
            'GET provider-order/{provider_order}/edit'            => 'sin método: la ruta la registra Route::resource y el controller no tiene edit()',
            'GET provider-order/{id}/import-diff'                 => 'import_diff: where user_id (misión anterior)',
            'GET provider-order/from-date/{from_date?}/{until_date?}' => 'index: where user_id',
        ];

        $encontradas = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $ruta) {

            $uri = $ruta->uri();

            if (strpos($uri, 'api/provider-order/') !== 0 || strpos($uri, '{') === false) {
                continue;
            }

            foreach ($ruta->methods() as $metodo) {

                if ($metodo === 'HEAD') {
                    continue;
                }

                $encontradas[] = $metodo . ' ' . substr($uri, strlen('api/'));
            }
        }

        $sin_declarar = array_values(array_diff($encontradas, array_keys($declaradas)));

        $this->assertSame([], $sin_declarar, 'Rutas de compra con id sin declarar cómo resuelven la tenencia: ' . implode(' | ', $sin_declarar));
    }

    /**
     * Borra todo lo que crearon los tests: compras (propias y ajenas), facturas con sus alícuotas,
     * movimientos de cuenta corriente y usuarios.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $ids = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->pluck('id')->all();

            ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();
            ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
            CurrentAcount::where('provider_order_id', $compra_id)->delete();
            \Illuminate\Support\Facades\DB::table('article_provider_order')->where('provider_order_id', $compra_id)->delete();
            ProviderOrder::where('id', $compra_id)->delete();
        }

        if (count($this->usuarios_creados) > 0) {
            User::whereIn('id', $this->usuarios_creados)->delete();
        }

        parent::tearDown();
    }
}
