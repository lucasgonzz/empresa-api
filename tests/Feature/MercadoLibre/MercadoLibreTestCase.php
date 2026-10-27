<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeTag;
use App\Models\MeliAttributeValue;
use App\Models\MeliCategory;
use App\Models\MeliOrder;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\SyncToMeliArticle;
use App\Models\User;
use App\Services\MercadoLibre\ProductService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Base de las suites de la misión `mercado-libre-integracion` (27/10/2026).
 *
 * - Usuario dueño del fixture (`TestingFerreteriaSeeder`), autenticado, y todo adentro de la
 *   transacción de `EmpresaTestCase`: conectores, artículos, categorías y pedidos se revierten.
 * - NADA de red: `fake_ml()` registra UN `Http::fake()` que contesta según `$this->rutas_ml`
 *   (`'METODO path-con-*' => respuesta`), que cada test puede cambiar entre un paso y el
 *   siguiente (los stubs de `Http::fake()` se acumulan y gana el primero, así que no se registra
 *   otro). Lo que no está en la tabla contesta 599 y el test lo ve.
 * - Sin esperas: pausa entre llamadas, backoff y espera del 409 en 0.
 *
 * PHP 7.4: sin match, ?->, str_contains, argumentos nombrados ni union types.
 */
abstract class MercadoLibreTestCase extends EmpresaTestCase
{
    /** Id del vendedor en ML del conector de prueba. */
    const SELLER_ID = '123456789';

    /** Access token del conector de prueba. Nunca tiene que aparecer en un log ni en una respuesta. */
    const TOKEN = 'APP_USR-TOKEN-ML-DE-PRUEBA';

    /** Refresh token del conector de prueba. */
    const REFRESH = 'TG-REFRESH-ML-DE-PRUEBA';

    /** URL del relay del admin en los tests. */
    const RELAY = 'https://api-admin.comerciocity.test/api/mercadolibre/callback';

    /** Redirect legacy propia del backend en los tests. */
    const REDIRECT_LEGACY = 'https://api-cliente.comerciocity.test/platform-connector/mercadolibre/callback';

    /** @var \App\Models\User Dueño del fixture. */
    protected $owner;

    /** @var array<string, mixed> Tabla de respuestas del Http::fake() de ML. */
    protected $rutas_ml = [];

    /** @var bool Si ya se registró el Http::fake() de este test. */
    protected $fake_registrado = false;

    /** @var array<string, mixed>|null Valores previos de USA_MERCADO_LIBRE para restaurar. */
    protected $entorno_previo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        config([
            'app.USER_ID'                                  => $this->owner->id,
            'services.mercadolibre.client_id'              => 'APP-ML-DE-PRUEBA',
            'services.mercadolibre.client_secret'          => 'SECRET-ML-DE-PRUEBA',
            'services.mercadolibre.redirect_uri'           => self::REDIRECT_LEGACY,
            'services.mercadolibre.oauth_relay_url'        => self::RELAY,
            'services.mercadolibre.crear_venta_automatica' => true,
            'services.mercadolibre.ms_entre_llamadas'      => 0,
            'services.mercadolibre.backoff_ms'             => 0,
            'services.mercadolibre.espera_409_ms'          => 0,
            'services.mercadolibre.max_por_corrida'        => 30,
        ]);

        ProductService::olvidar_cache_del_modulo();

        // Punto de partida fijo: el módulo PRENDIDO (la extensión `usa_mercado_libre` del dueño),
        // que es lo que asumen casi todos los tests. No se depende de lo que tenga la base del slot
        // (la sesión de la raíz se la prendió al usuario 500 para probar la pantalla, y otra base
        // puede no tenerla). Los tests del módulo apagado la sacan con
        // `extension_usa_mercado_libre(false)`.
        $this->extension_usa_mercado_libre(true);

        // La fila de platforms sin credenciales: las del .env (config) son las que valen en los
        // tests, salvo el que pruebe explícitamente lo contrario.
        Platform::updateOrCreate(
            ['slug' => Platform::SLUG_MERCADO_LIBRE],
            ['name' => 'Mercado Libre', 'client_id' => null, 'client_secret' => null]
        );

        // Cada test arma su propio estado de ML del dueño.
        PlatformConnector::where('user_id', $this->owner->id)
            ->whereHas('platform', function ($query) {
                $query->where('slug', Platform::SLUG_MERCADO_LIBRE);
            })
            ->delete();
        SyncToMeliArticle::where('user_id', $this->owner->id)->delete();
    }

    protected function tearDown(): void
    {
        if (!is_null($this->entorno_previo)) {
            $this->restaurar_env_usa_mercado_libre();
        }

        ProductService::olvidar_cache_del_modulo();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ red fakeada

    /**
     * Registra (una vez por test) el Http::fake() que contesta según `$this->rutas_ml`, y suma las
     * rutas pedidas a la tabla.
     *
     * Cada valor puede ser: `[status, body, headers?]`, un `\Closure($request)` que devuelve una
     * respuesta, o `['secuencia' => [[status, body, headers?], ...]]` (se consume en orden; la
     * última se repite).
     *
     * @param array<string, mixed> $rutas
     * @return void
     */
    protected function fake_ml(array $rutas)
    {
        foreach ($rutas as $clave => $valor) {
            $this->rutas_ml[$clave] = $valor;
        }

        if ($this->fake_registrado) {
            return;
        }

        $this->fake_registrado = true;

        Http::fake(function ($request) {
            $metodo = strtoupper($request->method());
            $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

            foreach ($this->rutas_ml as $clave => $valor) {
                $partes = explode(' ', $clave, 2);

                if ($partes[0] !== $metodo || !Str::is($partes[1], $path)) {
                    continue;
                }

                if ($valor instanceof \Closure) {
                    return $valor($request);
                }

                if (is_array($valor) && isset($valor['secuencia'])) {
                    $paso = count($this->rutas_ml[$clave]['secuencia']) > 1
                        ? array_shift($this->rutas_ml[$clave]['secuencia'])
                        : $this->rutas_ml[$clave]['secuencia'][0];

                    return Http::response($paso[1], $paso[0], isset($paso[2]) ? $paso[2] : []);
                }

                return Http::response($valor[1], $valor[0], isset($valor[2]) ? $valor[2] : []);
            }

            return Http::response(['message' => 'ruta no fakeada en el test: '.$metodo.' '.$path], 599);
        });
    }

    /**
     * Pedidos a ML que hizo el test, filtrados por método y patrón de path.
     *
     * @param string $metodo
     * @param string $patron
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    protected function pedidos_ml($metodo, $patron)
    {
        $pedidos = [];

        foreach (Http::recorded() as $par) {
            $request = $par[0];
            $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

            if (strtoupper($request->method()) === strtoupper($metodo) && Str::is($patron, $path)) {
                $pedidos[] = $request;
            }
        }

        return $pedidos;
    }

    /**
     * Respuesta típica de `users/me`.
     *
     * @param array<int, string> $tags
     * @return array<string, mixed>
     */
    protected function usuario_ml(array $tags = ['normal'])
    {
        return [
            'id'       => (int) self::SELLER_ID,
            'nickname' => 'VENDEDOR_PRUEBA',
            'site_id'  => 'MLA',
            'tags'     => $tags,
            'status'   => ['list' => ['allow' => true, 'codes' => []]],
            'seller_reputation' => [
                'level_id'            => '4_light_green',
                'power_seller_status' => null,
                'transactions'        => ['completed' => 40, 'canceled' => 1, 'period' => 'historic'],
                'metrics'             => [
                    'sales'                 => ['period' => '365 days', 'completed' => 40],
                    'claims'                => ['rate' => 0.01],
                    'delayed_handling_time' => ['rate' => 0.05],
                    'cancellations'         => ['rate' => 0.0],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ entorno

    /**
     * Fuerza `USA_MERCADO_LIBRE` a un valor (el .env.testing del slot lo trae prendido) para probar
     * que el gate NO depende de él. Se restaura en tearDown.
     *
     * @param string $valor 'true' o 'false'.
     * @return void
     */
    protected function forzar_env_usa_mercado_libre($valor)
    {
        $this->entorno_previo = [
            'env'    => array_key_exists('USA_MERCADO_LIBRE', $_ENV) ? $_ENV['USA_MERCADO_LIBRE'] : null,
            'server' => array_key_exists('USA_MERCADO_LIBRE', $_SERVER) ? $_SERVER['USA_MERCADO_LIBRE'] : null,
            'getenv' => getenv('USA_MERCADO_LIBRE'),
        ];

        $_ENV['USA_MERCADO_LIBRE'] = $valor;
        $_SERVER['USA_MERCADO_LIBRE'] = $valor;
        putenv('USA_MERCADO_LIBRE='.$valor);

        ProductService::olvidar_cache_del_modulo();
    }

    /**
     * @return void
     */
    protected function restaurar_env_usa_mercado_libre()
    {
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $clave => $global) {
            $previo = $this->entorno_previo[$clave];

            if ($global === '_ENV') {
                if (is_null($previo)) {
                    unset($_ENV['USA_MERCADO_LIBRE']);
                } else {
                    $_ENV['USA_MERCADO_LIBRE'] = $previo;
                }
            } else {
                if (is_null($previo)) {
                    unset($_SERVER['USA_MERCADO_LIBRE']);
                } else {
                    $_SERVER['USA_MERCADO_LIBRE'] = $previo;
                }
            }
        }

        if ($this->entorno_previo['getenv'] === false) {
            putenv('USA_MERCADO_LIBRE');
        } else {
            putenv('USA_MERCADO_LIBRE='.$this->entorno_previo['getenv']);
        }

        $this->entorno_previo = null;
    }

    /**
     * Le da (o le saca) al dueño la extensión `usa_mercado_libre`.
     *
     * @param bool $dar
     * @return void
     */
    protected function extension_usa_mercado_libre($dar = true)
    {
        $extencion = ExtencionEmpresa::where('slug', 'usa_mercado_libre')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['name' => 'usa_mercado_libre', 'slug' => 'usa_mercado_libre']);
        }

        if ($dar) {
            $this->owner->extencions()->syncWithoutDetaching([$extencion->id]);
        } else {
            $this->owner->extencions()->detach($extencion->id);
        }

        ProductService::olvidar_cache_del_modulo();
    }

    // ------------------------------------------------------------------ fábricas

    /**
     * Conector de ML del dueño, conectado y con el token vigente.
     *
     * @param array<string, mixed> $atributos
     * @param array<string, mixed>|null $meli `extra_config.meli`.
     * @return PlatformConnector
     */
    protected function conector_ml(array $atributos = [], $meli = null)
    {
        $platform = Platform::where('slug', Platform::SLUG_MERCADO_LIBRE)->first();

        if (is_null($meli)) {
            $meli = [
                'nickname'            => 'VENDEDOR_PRUEBA',
                'user_product_seller' => false,
                'multiorigen'         => false,
                'site_id'             => 'MLA',
                'conectado_en'        => Carbon::now()->subDays(30)->toIso8601String(),
            ];
        }

        return PlatformConnector::create(array_merge([
            'user_id'          => $this->owner->id,
            'platform_id'      => $platform->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => self::TOKEN,
            'refresh_token'    => self::REFRESH,
            'expires_at'       => Carbon::now()->addHours(5),
            'platform_user_id' => self::SELLER_ID,
            'extra_config'     => ['meli' => $meli],
        ], $atributos));
    }

    /**
     * Categoría local de ML (hoja) con atributos: BRAND (requerido), MODEL (requerido), GTIN
     * (condicional), SELLER_SKU, COLOR (con valores) y uno de solo lectura.
     *
     * @param string $meli_category_id
     * @return MeliCategory
     */
    protected function categoria_local($meli_category_id = 'MLA5232')
    {
        $categoria = MeliCategory::create([
            'meli_category_id'   => $meli_category_id,
            'meli_category_name' => 'Martillos',
        ]);

        $atributos = [
            ['BRAND', 'Marca', ['required' => true]],
            ['MODEL', 'Modelo', ['required' => true]],
            ['GTIN', 'Código universal de producto', ['conditional_required' => true]],
            ['SELLER_SKU', 'SKU', []],
            ['COLOR', 'Color', []],
            ['ITEM_CONDITION', 'Condición', ['read_only' => true]],
        ];

        foreach ($atributos as $fila) {
            $atributo = MeliAttribute::create([
                'meli_id'          => $fila[0],
                'name'             => $fila[1],
                'value_type'       => 'string',
                'meli_category_id' => $categoria->id,
            ]);

            foreach ($fila[2] as $tag => $valor) {
                MeliAttributeTag::create([
                    'key'               => $tag,
                    'value'             => $valor ? '1' : '0',
                    'meli_attribute_id' => $atributo->id,
                ]);
            }

            if ($fila[0] === 'COLOR') {
                MeliAttributeValue::create(['meli_id' => '52049', 'meli_name' => 'Negro', 'meli_attribute_id' => $atributo->id]);
                MeliAttributeValue::create(['meli_id' => '52055', 'meli_name' => 'Blanco', 'meli_attribute_id' => $atributo->id]);
            }
        }

        return $categoria;
    }

    /**
     * Atributo local de la categoría por id de ML.
     *
     * @param MeliCategory $categoria
     * @param string $meli_id
     * @return MeliAttribute
     */
    protected function atributo(MeliCategory $categoria, $meli_id)
    {
        return MeliAttribute::where('meli_category_id', $categoria->id)->where('meli_id', $meli_id)->firstOrFail();
    }

    /**
     * Artículo del dueño listo para publicar (categoría, foto, stock y precio).
     *
     * @param MeliCategory|null $categoria
     * @param array<string, mixed> $atributos
     * @param bool $con_foto
     * @return Article
     */
    protected function articulo_ml($categoria = null, array $atributos = [], $con_foto = true)
    {
        $article = Article::create(array_merge([
            'name'             => 'Martillo de prueba ML '.uniqid(),
            'user_id'          => $this->owner->id,
            'status'           => 'active',
            'mercado_libre'    => 1,
            'meli_category_id' => $categoria ? $categoria->id : null,
            'stock'            => 10,
            'final_price'      => 15000,
            'bar_code'         => '7790001234567',
            'sku'              => 'SKU-PRUEBA-1',
        ], $atributos));

        if ($con_foto) {
            Image::create([
                'hosting_url'    => 'https://imagenes.comerciocity.test/martillo-'.$article->id.'.jpg',
                'imageable_type' => 'article',
                'imageable_id'   => $article->id,
            ]);
        }

        return Article::find($article->id);
    }

    /**
     * Sincronización pendiente para el artículo.
     *
     * @param Article $article
     * @return SyncToMeliArticle
     */
    protected function sync_pendiente(Article $article)
    {
        return SyncToMeliArticle::create([
            'article_id' => $article->id,
            'user_id'    => $article->user_id,
            'status'     => 'pendiente',
        ]);
    }

    /**
     * Respuesta de `categories/{id}` de una hoja.
     *
     * @param string $id
     * @param int $max_title_length
     * @return array<string, mixed>
     */
    protected function categoria_ml_hoja($id = 'MLA5232', $max_title_length = 60)
    {
        return [
            'id'                  => $id,
            'name'                => 'Martillos',
            'path_from_root'      => [
                ['id' => 'MLA1500', 'name' => 'Construcción'],
                ['id' => 'MLA2000', 'name' => 'Herramientas'],
                ['id' => $id, 'name' => 'Martillos'],
            ],
            'children_categories' => [],
            'settings'            => ['listing_allowed' => true, 'max_title_length' => $max_title_length],
        ];
    }

    /**
     * Pedido de ML con la forma de `orders/search`.
     *
     * @param string $id
     * @param string $status
     * @param array<int, array<string, mixed>> $renglones `[[item_id, cantidad, precio, titulo]]`
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function pedido_ml($id, $status, array $renglones, array $extra = [])
    {
        $order_items = [];
        $total = 0;

        foreach ($renglones as $renglon) {
            $order_items[] = [
                'item'       => ['id' => $renglon[0], 'title' => isset($renglon[3]) ? $renglon[3] : 'Publicación '.$renglon[0]],
                'quantity'   => $renglon[1],
                'unit_price' => $renglon[2],
            ];
            $total += $renglon[1] * $renglon[2];
        }

        return array_merge([
            'id'           => $id,
            'status'       => $status,
            'date_created' => Carbon::now()->subHour()->toIso8601String(),
            'date_closed'  => Carbon::now()->subHour()->toIso8601String(),
            'total_amount' => $total,
            'pack_id'      => '2000009999'.substr($id, -3),
            'shipping'     => ['id' => '4400012345'],
            'buyer'        => ['id' => 777001, 'nickname' => 'COMPRADOR_PRUEBA'],
            'order_items'  => $order_items,
            'tags'         => ['paid'],
        ], $extra);
    }

    /**
     * Pedidos de ML del dueño con ese id.
     *
     * @param string $id
     * @return \Illuminate\Support\Collection
     */
    protected function pedidos_locales($id)
    {
        return MeliOrder::where('meli_order_id', $id)->where('user_id', $this->owner->id)->get();
    }
}
