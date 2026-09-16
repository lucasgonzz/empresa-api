<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\FichaArticuloIaHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\PermissionEmpresa;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — bloque C2 y C3: lo que abren las menciones del chat.
 *
 * C2, `GET api/articles/{id}/ficha-asistente`: todo lo que la tarjeta del hover necesita en UN
 * request, con el recorte por `article.stock_only_sucursal` hecho del lado del API —la SPA dibuja
 * lo que llega, así que si el API no filtra, filtra data—.
 *
 * C3, `GET api/clients/{id}/para-cuenta-corriente`: el cliente con sus cuentas para abrir el modal.
 * 🔴 Existe porque `GET api/client/{id}` resuelve por id PELADO (Controller::fullModel()) y
 * devuelve el cliente de cualquier comercio; acá la tenencia se verifica de verdad.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Ficha_y_cuenta_de_mencion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita el módulo IA. */
    const SLUG = 'asistente_ia';

    /** Slug de la extensión que habilita la cuenta corriente en dólares. */
    const SLUG_DOLARES = 'ventas_en_dolares';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio ficha C2',
            'company_name' => 'Ferreteria ficha C2',
            'email'        => 'ficha-c2-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otro comercio ficha C2',
            'company_name' => 'Ajeno ficha C2',
            'email'        => 'ficha-c2-ajeno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->dar_extension(self::SLUG);
    }

    /**
     * Asigna una extensión al comercio (creando la fila del catálogo si la base del slot todavía
     * no la tiene sembrada).
     *
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => $slug,
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Un artículo completo del comercio: foto, código, precio, proveedor y stock.
     *
     * @return Article
     */
    protected function articulo_completo()
    {
        $proveedor = Provider::create(['name' => 'Candela C2', 'user_id' => $this->comercio->id]);

        $articulo = Article::create([
            'name'        => 'LAMPARA DICROICA LEDS 7W GU10',
            'user_id'     => $this->comercio->id,
            'bar_code'    => '7798123456789',
            'final_price' => 4145.08,
            'price'       => 3000,
            'stock'       => 32,
            'provider_id' => $proveedor->id,
        ]);

        Image::create([
            'imageable_id'   => $articulo->id,
            /*
             * 'article' y no 'App\Models\Article': AppServiceProvider::boot() llama a
             * Relation::enforceMorphMap(['article' => 'App\Models\Article', ...]), así que el
             * alias ES el valor que Eloquent guarda y por el que después busca. Con el nombre de
             * clase completo la fila se inserta igual y la relación devuelve vacío en silencio —
             * el test pasaba a verde sembrando un dato que en producción no existe.
             */
            'imageable_type' => 'article',
            'hosting_url'    => 'https://cdn.test.local/storage/lampara.webp',
        ]);

        return $articulo;
    }

    /**
     * 🔴 TODO EN UN REQUEST: la tarjeta no puede encadenar pedidos mientras el mouse está encima.
     *
     * @group chat-ia
     * @test
     */
    public function la_ficha_trae_todo_lo_que_la_tarjeta_dibuja_en_un_request()
    {
        $articulo = $this->articulo_completo();

        $central = Address::create(['street' => 'Casa central C2', 'user_id' => $this->comercio->id]);
        $norte   = Address::create(['street' => 'Deposito norte C2', 'user_id' => $this->comercio->id]);

        // Una sucursal de OTRO comercio, con stock del mismo artículo: no puede aparecer.
        $ajena = Address::create(['street' => 'Deposito ajeno C2', 'user_id' => $this->otro_comercio->id]);

        $articulo->addresses()->attach($central->id, ['amount' => 20]);
        $articulo->addresses()->attach($norte->id, ['amount' => 12]);

        $mayorista = PriceType::create(['name' => 'Lista mayorista C2', 'user_id' => $this->comercio->id]);
        $sin_precio = PriceType::create(['name' => 'Lista sin precio C2', 'user_id' => $this->comercio->id]);

        $articulo->price_types()->attach($mayorista->id, ['final_price' => 3800]);
        $articulo->price_types()->attach($sin_precio->id, ['final_price' => null]);

        $this->actingAs($this->comercio, 'web');

        $respuesta = $this->getJson('api/articles/' . $articulo->id . '/ficha-asistente');

        $respuesta->assertStatus(200);

        $ficha = $respuesta->json('model');

        $this->assertEquals($articulo->id, $ficha['id']);
        $this->assertEquals('LAMPARA DICROICA LEDS 7W GU10', $ficha['nombre']);
        $this->assertEquals('7798123456789', $ficha['codigo']);
        $this->assertEquals(4145.08, $ficha['precio']);
        $this->assertEquals('Candela C2', $ficha['proveedor']);
        $this->assertEquals(32.0, $ficha['stock_total']);
        $this->assertStringContainsString('lampara.webp', (string) $ficha['imagen_url']);

        $this->assertTrue($ficha['tiene_depositos'], 'Con dos o más sucursales, la sección de depósitos existe.');
        $this->assertEquals(
            [
                ['nombre' => 'Casa central C2',   'stock' => 20.0],
                ['nombre' => 'Deposito norte C2', 'stock' => 12.0],
            ],
            $ficha['depositos'],
            'La sucursal del otro comercio no puede aparecer.'
        );

        $this->assertEquals(
            [['nombre' => 'Lista mayorista C2', 'precio' => 3800.0]],
            $ficha['listas_de_precios'],
            'Una lista sin precio final cargado no es un dato: es un renglón que confunde.'
        );

        // La sucursal ajena existe pero no se usó: la aserción de arriba ya la cubre.
        $this->assertEquals($this->otro_comercio->id, $ajena->user_id);
    }

    /**
     * ⚠️ `embedding` son 1536 floats por fila. No puede viajar en una tarjeta de hover ni en
     * ningún lado (Article::$hidden, misión optimizacion-vps-fase1).
     *
     * @group chat-ia
     * @test
     */
    public function la_ficha_no_devuelve_el_embedding_ni_nada_fuera_del_contrato()
    {
        $articulo = $this->articulo_completo();

        $this->actingAs($this->comercio, 'web');

        $ficha = $this->getJson('api/articles/' . $articulo->id . '/ficha-asistente')->json('model');

        $this->assertEquals(
            ['id', 'nombre', 'imagen_url', 'codigo', 'precio', 'proveedor', 'stock_total',
             'tiene_depositos', 'depositos', 'listas_de_precios'],
            array_keys($ficha),
            'La ficha devuelve las diez claves del contrato y nada más.'
        );
    }

    /**
     * Con una sola sucursal no hay reparto que mostrar: repetir el stock total abajo del stock
     * total sería el mismo número dos veces.
     *
     * @group chat-ia
     * @test
     */
    public function sin_depositos_la_ficha_no_dibuja_la_seccion()
    {
        $articulo = $this->articulo_completo();

        $unica = Address::create(['street' => 'Local unico C2', 'user_id' => $this->comercio->id]);
        $articulo->addresses()->attach($unica->id, ['amount' => 32]);

        $this->actingAs($this->comercio, 'web');

        $ficha = $this->getJson('api/articles/' . $articulo->id . '/ficha-asistente')->json('model');

        $this->assertFalse($ficha['tiene_depositos']);
        $this->assertSame([], $ficha['depositos']);
    }

    /**
     * Un artículo sin código de barras cae al código del proveedor, y sin ninguno de los dos manda
     * null: la tarjeta simplemente no dibuja el renglón.
     *
     * @group chat-ia
     * @test
     */
    public function el_codigo_cae_al_del_proveedor_y_despues_a_null()
    {
        $con_proveedor = Article::create([
            'name'          => 'Articulo con codigo de proveedor C2',
            'user_id'       => $this->comercio->id,
            'provider_code' => 'PRV-9981',
        ]);

        $pelado = Article::create([
            'name'    => 'Articulo sin codigos C2',
            'user_id' => $this->comercio->id,
        ]);

        $this->actingAs($this->comercio, 'web');

        $this->assertEquals(
            'PRV-9981',
            $this->getJson('api/articles/' . $con_proveedor->id . '/ficha-asistente')->json('model.codigo')
        );

        $this->assertNull(
            $this->getJson('api/articles/' . $pelado->id . '/ficha-asistente')->json('model.codigo')
        );
    }

    /**
     * 🔴 404 si el artículo no es del dueño. Es la tenencia, no una cortesía: el id de la mención
     * viaja por la URL y una pestaña vieja podría pedir cualquiera.
     *
     * @group chat-ia
     * @test
     */
    public function un_articulo_de_otro_comercio_da_404()
    {
        $ajeno = Article::create(['name' => 'Lampara ajena C2', 'user_id' => $this->otro_comercio->id]);

        $this->actingAs($this->comercio, 'web');

        $this->getJson('api/articles/' . $ajeno->id . '/ficha-asistente')->assertStatus(404);
        $this->getJson('api/articles/999999999/ficha-asistente')->assertStatus(404);
    }

    /**
     * 🔴 EL RECORTE POR PERMISO LO HACE EL API. Un empleado con `article.stock_only_sucursal` ve
     * SOLO la suya, igual que en el buscador de artículos y en el listado
     * (article_dynamic_table_columns.js::puede_ver_address).
     *
     * ⚠️ Se prueba sobre el helper y no sobre la ruta a propósito: el gate `solo_el_dueno_ia` no
     * deja a un empleado raso llegar al endpoint. El recorte queda igual como defensa en
     * profundidad, que es lo que este test sostiene — si mañana el gate se afloja, el filtro ya
     * está.
     *
     * @group chat-ia
     * @test
     */
    public function el_permiso_stock_only_sucursal_recorta_los_depositos()
    {
        $articulo = $this->articulo_completo();

        $central = Address::create(['street' => 'Casa central perm C2', 'user_id' => $this->comercio->id]);
        $norte   = Address::create(['street' => 'Deposito norte perm C2', 'user_id' => $this->comercio->id]);

        $articulo->addresses()->attach($central->id, ['amount' => 20]);
        $articulo->addresses()->attach($norte->id, ['amount' => 12]);

        /*
         * PermissionEmpresa y no Permission: User::permissions() es
         * belongsToMany(PermissionEmpresa::class) (app/Models/User.php:199-201), o sea la tabla
         * `permission_empresas` con el pivote `permission_empresa_user`. Hay TRES tablas de
         * permisos en esta base (`permissions`, `permission_betas`, `permission_empresas`) y
         * atachear el id de la equivocada no falla: inserta la fila igual, y después
         * PermisosIaHelper::puede() recorre una colección que no tiene ese slug. El recorte no se
         * aplicaba y el test creía estar probándolo.
         */
        $permiso = PermissionEmpresa::where('slug', FichaArticuloIaHelper::SOLO_MI_SUCURSAL)->first();

        if (!$permiso) {
            $permiso = PermissionEmpresa::forceCreate([
                'slug' => FichaArticuloIaHelper::SOLO_MI_SUCURSAL,
                'name' => 'Ver stock solo de su sucursal',
            ]);
        }

        $empleado = User::create([
            'name'       => 'Empleado sucursal C2',
            'email'      => 'ficha-c2-empleado-' . uniqid() . '@test.local',
            'password'   => Hash::make('secret'),
            'owner_id'   => $this->comercio->id,
            'address_id' => $norte->id,
        ]);

        $empleado->permissions()->attach($permiso->id);
        $empleado->load('permissions');

        $recortada = FichaArticuloIaHelper::ficha($articulo->id, $this->comercio->id, $empleado);

        $this->assertTrue($recortada['tiene_depositos']);
        $this->assertEquals(
            [['nombre' => 'Deposito norte perm C2', 'stock' => 12.0]],
            $recortada['depositos'],
            'Con el permiso, el empleado ve SOLO su sucursal.'
        );

        // El dueño sigue viendo las dos.
        $completa = FichaArticuloIaHelper::ficha($articulo->id, $this->comercio->id, $this->comercio);

        $this->assertCount(2, $completa['depositos']);
    }

    /**
     * Un encargado con admin_access no tiene recorte, aunque tenga el permiso: es el mismo `is_admin`
     * de la SPA.
     *
     * @group chat-ia
     * @test
     */
    public function un_encargado_con_admin_access_ve_todos_los_depositos()
    {
        $articulo = $this->articulo_completo();

        $central = Address::create(['street' => 'Casa central admin C2', 'user_id' => $this->comercio->id]);
        $norte   = Address::create(['street' => 'Deposito norte admin C2', 'user_id' => $this->comercio->id]);

        $articulo->addresses()->attach($central->id, ['amount' => 20]);
        $articulo->addresses()->attach($norte->id, ['amount' => 12]);

        $encargado = User::create([
            'name'         => 'Encargado ficha C2',
            'email'        => 'ficha-c2-encargado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->comercio->id,
            'admin_access' => 1,
            'address_id'   => $norte->id,
        ]);

        $ficha = FichaArticuloIaHelper::ficha($articulo->id, $this->comercio->id, $encargado);

        $this->assertCount(2, $ficha['depositos']);
    }

    // ---------------------------------------------------------------------------------------
    // C3 — el cliente para el modal de cuenta corriente
    // ---------------------------------------------------------------------------------------

    /**
     * Un cliente del comercio con su cuenta corriente.
     *
     * @param  string  $nombre
     * @param  int     $moneda_id
     * @param  float   $saldo
     * @param  float|null  $limite
     * @return array{0: Client, 1: CreditAccount}
     */
    protected function cliente_con_cuenta($nombre, $moneda_id, $saldo, $limite = null)
    {
        $cliente = Client::create(['name' => $nombre, 'user_id' => $this->comercio->id]);

        $cuenta = CreditAccount::create([
            'model_name'     => 'client',
            'model_id'       => $cliente->id,
            'user_id'        => $this->comercio->id,
            'moneda_id'      => $moneda_id,
            'saldo'          => $saldo,
            'limite_credito' => $limite,
        ]);

        return [$cliente, $cuenta];
    }

    /**
     * 🔴 Las dos claves que el agente de la SPA agregó al contrato el 16/9 midiendo contra el modal
     * real: `limite_credito` (sin ella no se dibuja "Límite de crédito · Disponible") y
     * `current_acounts_count` (sin ella el botón "Saldo inicial" no aparece nunca).
     *
     * @group chat-ia
     * @test
     */
    public function el_cliente_trae_sus_cuentas_con_limite_y_el_conteo_de_movimientos()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Ferreteria Tucumana C3', 1, 687610.88, 900000);

        CurrentAcount::create([
            'user_id'   => $this->comercio->id,
            'client_id' => $cliente->id,
            'detalle'   => 'Venta C3',
            'debe'      => 687610.88,
            'saldo'     => 687610.88,
        ]);

        $this->actingAs($this->comercio, 'web');

        $respuesta = $this->getJson('api/clients/' . $cliente->id . '/para-cuenta-corriente');

        $respuesta->assertStatus(200);

        $model = $respuesta->json('model');

        $this->assertEquals($cliente->id, $model['id']);
        $this->assertEquals('Ferreteria Tucumana C3', $model['name']);
        $this->assertEquals(1, $model['current_acounts_count']);
        $this->assertEquals(
            [[
                'id'             => $cuenta->id,
                'moneda_id'      => 1,
                'saldo'          => 687610.88,
                'limite_credito' => 900000.0,
            ]],
            $model['credit_accounts']
        );
    }

    /**
     * `limite_credito` null es un valor válido —es lo que hay cuando el cliente no tiene límite— y
     * el componente lo distingue de "no vino".
     *
     * @group chat-ia
     * @test
     */
    public function un_cliente_sin_limite_manda_null_y_no_la_omite()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente sin limite C3', 1, 100);

        $this->actingAs($this->comercio, 'web');

        $cuentas = $this->getJson('api/clients/' . $cliente->id . '/para-cuenta-corriente')
            ->json('model.credit_accounts');

        $this->assertCount(1, $cuentas);
        $this->assertArrayHasKey('limite_credito', $cuentas[0]);
        $this->assertNull($cuentas[0]['limite_credito']);
    }

    /**
     * ⚠️ `moneda_id = 0` ES PESOS, igual que el 1 (RecolectorBase::MONEDAS_PESOS). En producción
     * hay cuentas con 0 por altas donde no se eligió moneda: filtrarlas dejaría a esos clientes sin
     * cuenta corriente. Es un defecto real que ya costó caro el 15/9/2026.
     *
     * @group chat-ia
     * @test
     */
    public function una_cuenta_con_moneda_cero_es_pesos_y_viaja_sin_la_extension_de_dolares()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente moneda cero C3', 0, 4500);

        $this->actingAs($this->comercio, 'web');

        $cuentas = $this->getJson('api/clients/' . $cliente->id . '/para-cuenta-corriente')
            ->json('model.credit_accounts');

        $this->assertCount(1, $cuentas);
        $this->assertEquals($cuenta->id, $cuentas[0]['id']);
        $this->assertEquals(0, $cuentas[0]['moneda_id']);
    }

    /**
     * 🔴 La cuenta en dólares solo se ofrece con la extensión `ventas_en_dolares`: sin ella el
     * comercio no la tiene en ninguna pantalla y el chat no puede ser la excepción.
     *
     * @group chat-ia
     * @test
     */
    public function la_cuenta_en_dolares_solo_viaja_con_la_extension()
    {
        $cliente = Client::create(['name' => 'Cliente bimoneda C3', 'user_id' => $this->comercio->id]);

        $pesos = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'user_id'    => $this->comercio->id,
            'moneda_id'  => 1,
            'saldo'      => 1000,
        ]);

        CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'user_id'    => $this->comercio->id,
            'moneda_id'  => 2,
            'saldo'      => 50,
        ]);

        $this->actingAs($this->comercio, 'web');

        $sin = $this->getJson('api/clients/' . $cliente->id . '/para-cuenta-corriente')
            ->json('model.credit_accounts');

        $this->assertCount(1, $sin, 'Sin la extensión viaja solo la de pesos.');
        $this->assertEquals($pesos->id, $sin[0]['id']);

        $this->dar_extension(self::SLUG_DOLARES);

        $con = $this->getJson('api/clients/' . $cliente->id . '/para-cuenta-corriente')
            ->json('model.credit_accounts');

        $this->assertCount(2, $con);
        $this->assertEquals(1, $con[0]['moneda_id'], 'La de pesos va primera: es la que el modal abre por defecto.');
        $this->assertEquals(2, $con[1]['moneda_id']);
    }

    /**
     * 🔴 404 si el cliente no es del dueño. ES EL MOTIVO DE QUE ESTE ENDPOINT EXISTA: el
     * `GET api/client/{id}` de siempre resuelve por id pelado (Controller::fullModel(), sin
     * `user_id`) y en una base compartida devuelve el cliente de otro comercio con su deuda.
     *
     * @group chat-ia
     * @test
     */
    public function un_cliente_de_otro_comercio_da_404()
    {
        $ajeno = Client::create(['name' => 'Cliente ajeno C3', 'user_id' => $this->otro_comercio->id]);

        CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $ajeno->id,
            'user_id'    => $this->otro_comercio->id,
            'moneda_id'  => 1,
            'saldo'      => 999999,
        ]);

        $this->actingAs($this->comercio, 'web');

        $this->getJson('api/clients/' . $ajeno->id . '/para-cuenta-corriente')->assertStatus(404);
        $this->getJson('api/clients/999999999/para-cuenta-corriente')->assertStatus(404);
    }
}
