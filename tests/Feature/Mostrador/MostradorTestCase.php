<?php

namespace Tests\Feature\Mostrador;

use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Base de los tests del mostrador (misión modulo-ia-mostrador): un comercio propio
 * por test (nunca el del fixture: los números que se aseveran tienen que ser los que
 * sembró el test y nada más), la extensión asistente_ia a mano, y los helpers para
 * sembrar ventas, pagos, artículos, sucursales y eventos de la tienda con fecha.
 *
 * DatabaseTransactions: todo lo sembrado se revierte al terminar cada test.
 */
abstract class MostradorTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'asistente_ia';

    /** @var User Dueño de la cuenta del test */
    protected $comercio;

    /** @var Carbon Ayer a las 00:00 */
    protected $ayer;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.admin_api.require_api_key' => false]);
        config(['app.USER_ID' => null]);

        $this->ayer = Carbon::now()->subDay()->startOfDay();

        $this->comercio = User::create([
            'name'         => 'Dueño mostrador',
            'company_name' => 'Ferretería Mostrador',
            'email'        => 'mostrador-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Asigna una extensión al comercio (creando la fila del catálogo si la base del
     * slot todavía no la tiene sembrada). Por defecto la del módulo (asistente_ia);
     * los tests de la tienda piden también tracking_buyers.
     *
     * @param User|null $user
     * @param string $slug
     * @return void
     */
    protected function dar_extension($user = null, $slug = self::SLUG)
    {
        $user = $user ?: $this->comercio;

        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => ucfirst(str_replace('_', ' ', $slug)),
            ]);
        }

        $user->extencions()->attach($extencion->id);
        $user->load('extencions');
    }

    /**
     * Autentica a una persona para las rutas Sanctum. A diferencia de actingAs() a
     * secas, sirve para CAMBIAR de persona adentro de un mismo test: el guard de
     * Sanctum (RequestGuard) cachea al usuario resuelto en el primer request y un
     * segundo actingAs no lo pisa — hay que olvidar los guards primero.
     *
     * @param User $persona
     * @return void
     */
    protected function entrar_como(User $persona)
    {
        $this->app['auth']->forgetGuards();

        $this->actingAs($persona, 'web');
    }

    /**
     * Un empleado del comercio del test.
     *
     * @param bool $admin_access
     * @return User
     */
    protected function empleado($admin_access = false)
    {
        return User::create([
            'name'         => 'Empleado mostrador',
            'email'        => 'mostrador-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->comercio->id,
            'admin_access' => $admin_access ? 1 : 0,
        ]);
    }

    /**
     * Ayer a una hora dada.
     *
     * @param int $hora
     * @return Carbon
     */
    protected function ayer_a_las($hora)
    {
        return $this->ayer->copy()->setTime($hora, 0, 0);
    }

    /**
     * Una sucursal del comercio.
     *
     * @param string $nombre
     * @param bool $designada
     * @return Address
     */
    protected function sucursal($nombre, $designada = false)
    {
        return Address::create([
            'street'             => $nombre,
            'user_id'            => $this->comercio->id,
            'es_deposito_origen' => $designada,
        ]);
    }

    /**
     * Un artículo del comercio (activo), con stock y costo en la fila.
     *
     * @param string $nombre
     * @param array $extra
     * @return Article
     */
    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'status'  => 'active',
            'stock'   => 10,
            'cost'    => 100,
            'price'   => 150,
            'final_price' => 150,
        ], $extra));
    }

    /**
     * Una foto real para un artículo (misión mostrador-fotos-y-modales): pasa por el
     * mismo camino que ArticleHelper::getFirstImage() — el morph type 'article' lo
     * fuerza Relation::enforceMorphMap() en AppServiceProvider::boot(), así que no
     * alcanza con crear la fila a mano con imageable_type = Article::class.
     *
     * @param \App\Models\Article $articulo
     * @param string $hosting_url
     * @return \App\Models\Image
     */
    protected function imagen_de($articulo, $hosting_url = 'https://cdn.test.local/storage/foto.webp')
    {
        return \App\Models\Image::create([
            'hosting_url'    => $hosting_url,
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);
    }

    /**
     * Un cliente del comercio.
     *
     * @param string $nombre
     * @return Client
     */
    protected function cliente($nombre)
    {
        return Client::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Un proveedor del comercio.
     *
     * @param string $nombre
     * @return Provider
     */
    protected function proveedor_nuevo($nombre)
    {
        return Provider::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'status'  => 'active',
        ]);
    }

    /**
     * Una venta terminada del comercio con sus renglones (article_purchases).
     *
     * @param Carbon $momento
     * @param array $renglones [[Article, cantidad, precio_unitario], ...]
     * @param array $extra Columnas de la venta (client_id, employee_id, address_id, total_cost...)
     * @return Sale
     */
    protected function venta(Carbon $momento, array $renglones, array $extra = [])
    {
        $total = 0.0;

        foreach ($renglones as $renglon) {
            $total += $renglon[1] * $renglon[2];
        }

        $sale = Sale::create(array_merge([
            'user_id'    => $this->comercio->id,
            'total'      => $total,
            'terminada'  => 1,
            'created_at' => $momento,
            'updated_at' => $momento,
        ], $extra));

        foreach ($renglones as $renglon) {
            ArticlePurchase::create([
                'sale_id'    => $sale->id,
                'article_id' => $renglon[0]->id,
                'amount'     => $renglon[1],
                'price'      => $renglon[2],
                'address_id' => isset($extra['address_id']) ? $extra['address_id'] : null,
                'created_at' => $momento,
                'updated_at' => $momento,
            ]);
        }

        return $sale;
    }

    /**
     * Un evento de tracking de la tienda del comercio.
     *
     * @param string $tipo
     * @param Carbon $momento
     * @param array $extra buyer_id, visitor_id, article_id, search_term, results_count, quantity, amount, dwell_ms
     * @return int id del evento
     */
    protected function evento($tipo, Carbon $momento, array $extra = [])
    {
        return DB::table('buyer_tracking_events')->insertGetId(array_merge([
            'user_id'     => $this->comercio->id,
            'buyer_id'    => null,
            'visitor_id'  => 'visitante-' . uniqid(),
            'session_id'  => 'sesion-' . uniqid(),
            'event_type'  => $tipo,
            'article_id'  => null,
            'occurred_at' => $momento,
            'created_at'  => $momento,
            'updated_at'  => $momento,
        ], $extra));
    }

    /**
     * Un contenido válido mínimo para depositar en un informe.
     *
     * @param string $resumen
     * @return array
     */
    protected function contenido_valido($resumen = 'Ayer se vendió bien y quedaron dos cobranzas pendientes.')
    {
        return [
            'version' => 1,
            'bloques' => [
                ['tipo' => 'resumen', 'texto' => $resumen],
                ['tipo' => 'cifras', 'items' => [
                    ['etiqueta' => 'Ventas', 'valor' => '$ 12.000', 'detalle' => '3 ventas', 'variacion' => '+10 % vs. semana pasada', 'tono' => 'ok'],
                ]],
                ['tipo' => 'seccion', 'titulo' => 'Cobranzas'],
                ['tipo' => 'parrafo', 'texto' => 'Entraron dos pagos de cuenta corriente.'],
                ['tipo' => 'lista', 'titulo' => 'Para mirar', 'items' => [['texto' => 'El cliente Pérez debe hace 40 días', 'tono' => 'alerta']]],
                ['tipo' => 'tabla', 'titulo' => 'Más vendidos', 'columnas' => ['Artículo', 'Cantidad'], 'filas' => [['Martillo', '3']]],
                ['tipo' => 'articulos', 'titulo' => 'Sin stock', 'items' => [['article_id' => 1, 'nombre' => 'Martillo', 'imagen_url' => null, 'linea_1' => 'Stock: 0', 'linea_2' => 'Se vendieron 3 ayer', 'tono' => 'alerta']]],
                ['tipo' => 'acciones', 'titulo' => 'Qué conviene hacer hoy', 'items' => [['texto' => 'Llamar a Pérez', 'tipo' => 'cobrar']]],
            ],
        ];
    }
}
