<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Misión escaneo-factura-compra — archivo 3: los 8 endpoints y, sobre todo, la confirmación.
 *
 * Las tres aserciones que sostienen la misión y no se pueden sacar:
 *   - confirmar_agrega_articulos_sin_borrar_los_que_ya_estaban: protege el caso de la factura de
 *     varias páginas. Si alguien "parametriza" el attach_articles(false), este test se pone rojo.
 *   - en_modo_sin_factura_no_se_guarda_la_factura_salvo_que_se_pase_a_manual: la trampa del
 *     modo_facturacion. ModoFacturacionHelper borra TODAS las facturas de la compra en ese modo:
 *     guardar la factura antes de procesar_pedido() la borraría en el mismo request y en silencio.
 *   - el_gate_de_la_extencion_existe: sin la extensión el endpoint tiene que dar 403. Vive en este
 *     archivo y no en el 1 porque, sin la clase del controlador, Laravel devolvería 500 y no 403.
 *
 * 🔴 En el setUp van Notification::fake(), Queue::fake() y la clave de Anthropic en null. Sin eso
 * la suite sale a la API real de Anthropic (la clave vive en el .env.testing del slot) y cada
 * corrida gasta plata de verdad.
 */
class Endpoints_y_confirmacion_Test extends EmpresaTestCase
{
    /** Slug de la extensión que gatea todos los endpoints del escaneo. */
    const SLUG = 'escaneo_factura_compra';

    /**
     * Carpetas de storage creadas por los tests, para borrarlas en el tearDown: los archivos de
     * disco no los revierte `DatabaseTransactions`.
     *
     * @var array<int,string>
     */
    protected $carpetas_a_limpiar = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        /* Red de seguridad: ningún camino de este archivo puede salir a la API real de Claude. */
        config(['services.anthropic.api_key' => null]);

        $this->carpetas_a_limpiar = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->carpetas_a_limpiar as $carpeta) {
            Storage::disk('local')->deleteDirectory($carpeta);
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Asigna la extensión al usuario dado, creando la fila del catálogo si la base del slot
     * todavía no la tiene sembrada (el seeder es del bloque A).
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function dar_extension($user)
    {
        $extencion = $this->extencion_del_escaneo();

        if (!collect($user->extencions)->contains('slug', self::SLUG)) {
            $user->extencions()->attach($extencion->id);
        }

        /*
         * El middleware lee la colección de extenciones del user: sin el load() el attach recién
         * hecho no se ve en este mismo request de test.
         */
        $user->load('extencions');
    }

    /**
     * Le saca la extensión al usuario, para poder probar el 403 aunque la base del slot ya la
     * tenga sembrada y asignada.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function quitar_extension($user)
    {
        $user->extencions()->detach($this->extencion_del_escaneo()->id);

        $user->load('extencions');
    }

    /**
     * @return \App\Models\ExtencionEmpresa
     */
    protected function extencion_del_escaneo()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            /* forceCreate: el modelo no declara $fillable. */
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Escaneo de facturas con IA',
            ]);
        }

        return $extencion;
    }

    /**
     * Usuario autenticado por EmpresaTestCase (el comercio del fixture de ferretería).
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        return auth()->user();
    }

    /**
     * Crea una compra "liviana": sin actualizar stock, ni precios, ni cuenta corriente, para que
     * confirmar() no arrastre efectos que este archivo no está probando.
     *
     * Se usa el proveedor Rosario y no Buenos Aires porque Buenos Aires es el que el fixture dejó
     * con bonificaciones a propósito, y no queremos descuentos de catálogo metiéndose en los
     * totales de estos tests.
     *
     * @param  array  $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra(array $overrides = [])
    {
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        return ProviderOrder::create(array_merge([
            'num'                      => mt_rand(9000000, 9999999),
            'provider_id'              => $proveedor->id,
            'provider_order_status_id' => 1,
            'modo_facturacion'         => 'manual',
            'update_stock'             => 0,
            'update_prices'            => 0,
            'generate_current_acount'  => 0,
            'precios_incluyen_iva'     => false,
            'moneda_id'                => 1,
            'user_id'                  => $this->comercio()->id,
        ], $overrides));
    }

    /**
     * Artículo propio del test (no del fixture): así ningún test contamina el catálogo compartido.
     *
     * @param  string  $nombre
     * @param  array   $overrides
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, array $overrides = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'status'  => 'active',
            'user_id' => $this->comercio()->id,
        ], $overrides));
    }

    /**
     * Crea un escaneo ya terminado (estado 'listo') para la compra dada.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  array                      $overrides
     * @return \App\Models\ProviderOrderScan
     */
    protected function crear_escaneo($compra, array $overrides = [])
    {
        $defaults = [
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->comercio()->id,
            'auth_user_id'      => $this->comercio()->id,
            'provider_order_id' => $compra->id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado'         => $this->resultado_de_ejemplo(),
        ];

        return ProviderOrderScan::create(array_merge($defaults, $overrides));
    }

    /**
     * Resultado con la forma del contrato §2, con dos artículos: uno encontrado y uno sin match.
     *
     * @return array
     */
    protected function resultado_de_ejemplo()
    {
        return [
            'version'             => 1,
            'paginas_procesadas'  => 1,
            'columnas_detectadas' => [
                ['clave' => 'codigo_proveedor', 'etiqueta_en_factura' => 'CÓDIGO', 'confianza' => 0.94],
                ['clave' => 'nombre',           'etiqueta_en_factura' => 'DESCRIPCIÓN', 'confianza' => 0.97],
            ],
            'articulos' => [
                [
                    'fila'             => 1,
                    'pagina'           => 1,
                    'bar_code'         => null,
                    'codigo_proveedor' => 'ESC-1',
                    'nombre'           => 'ARTICULO ESCANEADO 1',
                    'cantidad'         => 3,
                    'costo_unitario'   => 100,
                    'notas'            => null,
                    'confianza_fila'   => 0.9,
                    'campos_dudosos'   => [],
                    'match'            => ['estado' => 'sin_match', 'article_id' => null, 'criterio' => null],
                    'incluir'          => true,
                    'crear_en_catalogo' => false,
                ],
                [
                    'fila'             => 2,
                    'pagina'           => 1,
                    'bar_code'         => null,
                    'codigo_proveedor' => 'ESC-2',
                    'nombre'           => 'ARTICULO ESCANEADO 2',
                    'cantidad'         => 5,
                    'costo_unitario'   => 250,
                    'notas'            => null,
                    'confianza_fila'   => 0.8,
                    'campos_dudosos'   => [],
                    'match'            => ['estado' => 'sin_match', 'article_id' => null, 'criterio' => null],
                    'incluir'          => true,
                    'crear_en_catalogo' => false,
                ],
            ],
            'factura' => ['es_factura_afip' => true],
            'avisos'  => [],
        ];
    }

    /**
     * Escribe un archivo real en el disco local y crea su fila de imagen, para poder probar el
     * endpoint que sirve el binario.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $orden
     * @return \App\Models\ProviderOrderScanImage
     */
    protected function crear_imagen($scan, $orden = 1)
    {
        $carpeta = 'provider_order_scans/' . $scan->user_id . '/' . $scan->uuid;
        $path    = $carpeta . '/' . $orden . '.webp';

        Storage::disk('local')->put($path, 'binario-de-prueba-' . $orden);

        $this->carpetas_a_limpiar[] = $carpeta;

        return ProviderOrderScanImage::create([
            'provider_order_scan_id' => $scan->id,
            'provider_order_id'      => $scan->provider_order_id,
            'user_id'                => $scan->user_id,
            'orden'                  => $orden,
            'path'                   => $path,
            'mime'                   => 'image/webp',
            'bytes'                  => 20,
            'nombre_original'        => 'pagina' . $orden . '.jpg',
        ]);
    }

    /**
     * Otro comercio, para los tests de tenencia.
     *
     * @return \App\Models\User
     */
    protected function otro_comercio()
    {
        return User::create([
            'name'     => 'Otro comercio escaneo',
            'email'    => 'escaneo-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Payload de confirmación para un artículo ya existente en el catálogo.
     *
     * @param  \App\Models\Article  $article
     * @param  float|int            $cantidad
     * @param  float|int            $costo
     * @return array
     */
    protected function item_existente($article, $cantidad, $costo)
    {
        return [
            'article_id'        => $article->id,
            'crear_en_catalogo' => false,
            'bar_code'          => $article->bar_code,
            'codigo_proveedor'  => $article->provider_code,
            'nombre'            => $article->name,
            'cantidad'          => $cantidad,
            'costo_unitario'    => $costo,
            'notas'             => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* 1 — El gate de la extensión                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Sin la extensión, los endpoints del escaneo no existen para el comercio. Es la única
     * aserción que prueba que el gate está realmente conectado a las rutas.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function el_gate_de_la_extencion_existe()
    {
        $comercio = $this->comercio();

        $this->quitar_extension($comercio);

        $respuesta = $this->postJson('api/provider-order-scan', ['provider_order_id' => 1]);

        $respuesta->assertStatus(403);

        $this->dar_extension($comercio);

        $con_extencion = $this->postJson('api/provider-order-scan', ['provider_order_id' => 1]);

        $this->assertNotEquals(
            403,
            $con_extencion->getStatusCode(),
            'Con la extensión asignada el endpoint no puede seguir devolviendo 403.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 2 — Tenencia                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Un escaneo de otro comercio no se puede leer, confirmar, descartar, ni mirarle las fotos.
     * Sin el filtro por user_id, iterar UUIDs dejaría leer facturas ajenas con CUIT, razón social
     * y precios adentro.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function un_escaneo_de_otro_comercio_devuelve_404_en_todos_los_endpoints()
    {
        $this->dar_extension($this->comercio());

        $ajeno = $this->otro_comercio();

        $compra_ajena = $this->crear_compra(['user_id' => $ajeno->id]);

        $scan_ajeno = ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $ajeno->id,
            'auth_user_id'      => $ajeno->id,
            'provider_order_id' => $compra_ajena->id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado'         => $this->resultado_de_ejemplo(),
        ]);

        $this->crear_imagen($scan_ajeno, 1);

        $base = 'api/provider-order-scan/' . $scan_ajeno->uuid;

        $this->getJson($base)->assertStatus(404);
        $this->postJson($base . '/confirmar', ['articulos' => []])->assertStatus(404);
        $this->postJson($base . '/descartar')->assertStatus(404);
        $this->postJson($base . '/visto')->assertStatus(404);
        $this->getJson($base . '/imagen/1')->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */
    /* 3, 4, 5 — store                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Subir dos fotos deja una sola corrida y una fila por página, en el orden en que se
     * eligieron, y encola el job. El 202 es lo que permite que el usuario siga trabajando.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function store_encola_el_escaneo_y_guarda_una_fila_por_pagina()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();

        $respuesta = $this->post('api/provider-order-scan', [
            'provider_order_id' => $compra->id,
            'imagenes'          => [
                UploadedFile::fake()->image('pagina1.jpg', 900, 1200),
                UploadedFile::fake()->image('pagina2.jpg', 900, 1200),
            ],
        ], ['Accept' => 'application/json']);

        $respuesta->assertStatus(202);
        $respuesta->assertJson(['estado' => 'pendiente', 'cantidad_imagenes' => 2]);

        $scans = ProviderOrderScan::where('provider_order_id', $compra->id)->get();

        $this->assertCount(1, $scans, 'Una subida tiene que dejar UN solo escaneo, con N páginas adentro.');

        $scan = $scans->first();

        $this->carpetas_a_limpiar[] = 'provider_order_scans/' . $scan->user_id . '/' . $scan->uuid;

        $this->assertEquals('pendiente', $scan->estado);
        $this->assertEquals($this->comercio()->id, $scan->user_id);
        $this->assertEquals(
            $this->comercio()->id,
            $scan->auth_user_id,
            'Sin auth_user_id el aviso de "terminó" le llega a todos los empleados del comercio.'
        );

        $imagenes = ProviderOrderScanImage::where('provider_order_scan_id', $scan->id)
                                            ->orderBy('orden', 'ASC')
                                            ->get();

        $this->assertCount(2, $imagenes);
        $this->assertEquals(1, (int) $imagenes[0]->orden);
        $this->assertEquals(2, (int) $imagenes[1]->orden);
        $this->assertEquals('pagina1.jpg', $imagenes[0]->nombre_original);
        $this->assertEquals($this->comercio()->id, $imagenes[0]->user_id);
        $this->assertEquals($compra->id, $imagenes[0]->provider_order_id);

        Queue::assertPushed(RunProviderOrderScanJob::class);
    }

    /**
     * Más páginas que el máximo configurado se rechazan antes de tocar storage o la cola.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function store_con_mas_paginas_que_el_maximo_devuelve_422_sin_crear_nada()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();

        $maximo = (int) config('services.escaneo_factura_compra.max_imagenes', 6);
        $maximo = $maximo > 0 ? $maximo : 6;

        $imagenes = [];

        for ($i = 1; $i <= ($maximo + 1); $i++) {
            $imagenes[] = UploadedFile::fake()->image('pagina' . $i . '.jpg', 400, 500);
        }

        $respuesta = $this->post('api/provider-order-scan', [
            'provider_order_id' => $compra->id,
            'imagenes'          => $imagenes,
        ], ['Accept' => 'application/json']);

        $respuesta->assertStatus(422);

        $this->assertEquals(0, ProviderOrderScan::where('provider_order_id', $compra->id)->count());

        Queue::assertNotPushed(RunProviderOrderScanJob::class);
    }

    /**
     * Dos escaneos en curso sobre la misma compra se pisarían: el segundo se rechaza con 409.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function store_con_un_escaneo_en_curso_de_la_misma_compra_devuelve_409()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();

        $this->crear_escaneo($compra, ['estado' => 'procesando', 'progreso' => 40, 'resultado' => null]);

        $respuesta = $this->post('api/provider-order-scan', [
            'provider_order_id' => $compra->id,
            'imagenes'          => [UploadedFile::fake()->image('pagina1.jpg', 400, 500)],
        ], ['Accept' => 'application/json']);

        $respuesta->assertStatus(409);

        $this->assertEquals(
            1,
            ProviderOrderScan::where('provider_order_id', $compra->id)->count(),
            'El 409 no puede dejar una corrida nueva creada.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 6 — pendientes                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * "Pendiente de revisar" es estado = 'listo' Y gestionado_at IS NULL: ni más ni menos. Un
     * escaneo en 'procesando' no enciende el botón rojo, y confirmar o descartar lo apagan.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function pendientes_devuelve_los_listos_sin_gestionar_y_los_saca_al_resolverlos()
    {
        $this->dar_extension($this->comercio());

        $articulo = $this->crear_articulo('ART PENDIENTES ESCANEO');

        $compra_lista      = $this->crear_compra();
        $compra_procesando = $this->crear_compra();

        $scan_listo = $this->crear_escaneo($compra_lista);

        $this->crear_escaneo($compra_procesando, [
            'estado'    => 'procesando',
            'progreso'  => 30,
            'resultado' => null,
        ]);

        $ids = $this->ids_de_pendientes();

        $this->assertContains($compra_lista->id, $ids);
        $this->assertNotContains(
            $compra_procesando->id,
            $ids,
            'Un escaneo en procesando no puede encender el botón rojo: todavía no hay nada para revisar.'
        );

        /* La cantidad de artículos sale del resultado, sin devolver el resultado entero. */
        $respuesta = $this->getJson('api/provider-order-scan/pendientes');
        $respuesta->assertStatus(200);

        foreach ($respuesta->json('models') as $fila) {
            if ($fila['provider_order_id'] == $compra_lista->id) {
                $this->assertEquals(2, $fila['cantidad_articulos']);
                $this->assertArrayNotHasKey('resultado', $fila);
            }
        }

        /* Confirmar apaga el botón. */
        $this->postJson('api/provider-order-scan/' . $scan_listo->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo, 2, 100)],
            'factura'   => ['guardar' => false],
        ])->assertStatus(200);

        $this->assertNotContains($compra_lista->id, $this->ids_de_pendientes());

        /* Descartar también. */
        $compra_descartada = $this->crear_compra();
        $scan_descartado   = $this->crear_escaneo($compra_descartada);

        $this->assertContains($compra_descartada->id, $this->ids_de_pendientes());

        $this->postJson('api/provider-order-scan/' . $scan_descartado->uuid . '/descartar')->assertStatus(200);

        $this->assertNotContains($compra_descartada->id, $this->ids_de_pendientes());
    }

    /**
     * provider_order_id de cada escaneo pendiente de revisar.
     *
     * @return array<int,int>
     */
    protected function ids_de_pendientes()
    {
        $respuesta = $this->getJson('api/provider-order-scan/pendientes');

        $respuesta->assertStatus(200);

        $ids = [];

        foreach ($respuesta->json('models') as $fila) {
            $ids[] = (int) $fila['provider_order_id'];
        }

        return $ids;
    }

    /* ------------------------------------------------------------------ */
    /* 7 — 🔴 Confirmar AGREGA, no reemplaza                               */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 El test que protege el caso de la factura de varias páginas.
     *
     * La compra arranca con el artículo A adjunto; se confirma un escaneo que trae solo el
     * artículo B; la compra tiene que quedar con A **y** B, y el pivot de A tiene que conservar su
     * cantidad original. Con attach_articles(true) (sync) el artículo A desaparecería: confirmar
     * la página 2 de una factura borraría lo que cargó la página 1.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function confirmar_agrega_articulos_sin_borrar_los_que_ya_estaban()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();

        $articulo_a = $this->crear_articulo('ART PAGINA UNO ESCANEO');
        $articulo_b = $this->crear_articulo('ART PAGINA DOS ESCANEO');

        /* La página 1 ya está cargada en la compra. */
        $compra->articles()->attach($articulo_a->id, ['amount' => 7, 'cost' => 111]);

        $scan = $this->crear_escaneo($compra);

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo_b, 4, 222)],
            'factura'   => ['guardar' => false],
        ]);

        $respuesta->assertStatus(200);

        $compra->load('articles');

        $ids = $compra->articles->pluck('id')->all();

        $this->assertContains(
            $articulo_a->id,
            $ids,
            'El artículo de la página 1 no puede desaparecer al confirmar la página 2 (attach_articles va con false).'
        );
        $this->assertContains($articulo_b->id, $ids);

        $pivot_a = $compra->articles->firstWhere('id', $articulo_a->id)->pivot;

        $this->assertEquals(7, (float) $pivot_a->amount, 'El pivot del artículo que ya estaba no se puede pisar.');

        $pivot_b = $compra->articles->firstWhere('id', $articulo_b->id)->pivot;

        $this->assertEquals(4, (float) $pivot_b->amount);
        $this->assertEquals(222, (float) $pivot_b->cost);
    }

    /* ------------------------------------------------------------------ */
    /* 8 — Alta de artículo nuevo                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Decisión 3 de Lucas: un artículo sin article_id NUNCA se crea solo. Un código mal leído por
     * el OCR que crea un artículo fantasma en el catálogo del cliente es el peor resultado
     * posible, porque nadie lo nota. Solo se crea si el usuario tildó la casilla.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function un_articulo_sin_match_se_crea_solo_si_el_usuario_lo_pidio()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();
        $scan   = $this->crear_escaneo($compra);

        $nombre_no_pedido = 'ART FANTASMA NO PEDIDO ESCANEO';
        $nombre_pedido    = 'ART NUEVO PEDIDO ESCANEO';

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [
                [
                    'article_id'        => null,
                    'crear_en_catalogo' => false,
                    'bar_code'          => null,
                    'codigo_proveedor'  => 'ESC-NO',
                    'nombre'            => $nombre_no_pedido,
                    'cantidad'          => 2,
                    'costo_unitario'    => 50,
                    'notas'             => null,
                ],
                [
                    'article_id'        => null,
                    'crear_en_catalogo' => true,
                    'bar_code'          => '7791234567890',
                    'codigo_proveedor'  => 'ESC-SI',
                    'nombre'            => $nombre_pedido,
                    'cantidad'          => 6,
                    'costo_unitario'    => 80,
                    'notas'             => null,
                ],
            ],
            'factura' => ['guardar' => false],
        ]);

        $respuesta->assertStatus(200);

        $this->assertEquals(
            0,
            Article::where('name', $nombre_no_pedido)->count(),
            'Sin la casilla tildada no se crea nada: se saltea en silencio (decisión 3).'
        );

        $creado = Article::where('name', $nombre_pedido)->first();

        $this->assertNotNull($creado, 'Con la casilla tildada el artículo se tiene que crear.');
        $this->assertEquals('inactive', $creado->status);
        $this->assertEquals($compra->provider_id, $creado->provider_id);
        $this->assertEquals($this->comercio()->id, $creado->user_id);
        $this->assertEquals('ESC-SI', $creado->provider_code);

        $compra->load('articles');

        $ids = $compra->articles->pluck('id')->all();

        $this->assertContains($creado->id, $ids, 'El artículo creado se tiene que adjuntar a la compra.');

        $resumen = $respuesta->json('resumen');

        $this->assertEquals(1, $resumen['articulos_creados']);
        $this->assertEquals(1, $resumen['articulos_agregados']);
        $this->assertEquals(1, $resumen['articulos_omitidos']);
    }

    /* ------------------------------------------------------------------ */
    /* 9 — La factura                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Con la compra en facturación manual, confirmar guarda el comprobante completo: número,
     * fecha, emisor, percepciones, retenciones, el desglose de IVA y el total_iva sumado.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function confirmar_guarda_la_factura_con_sus_ivas_en_una_compra_manual()
    {
        $this->dar_extension($this->comercio());

        $compra   = $this->crear_compra(['modo_facturacion' => 'manual']);
        $articulo = $this->crear_articulo('ART FACTURA ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $iva = Iva::first();

        $this->assertNotNull($iva, 'La base de testing tiene que tener alícuotas de IVA sembradas.');

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo, 3, 1000)],
            'factura'   => [
                'guardar'             => true,
                'pasar_a_manual'      => false,
                'code'                => '0003-00012345',
                'issued_at'           => '2026-08-14',
                'emisor_cuit'         => '30712345678',
                'emisor_razon_social' => 'DISTRIBUIDORA DEL SUR S.A.',
                'total'               => 124300,
                'percepcion_iibb'     => 2500,
                'percepcion_iva'      => 800,
                'retencion_iibb'      => null,
                'retencion_iva'       => null,
                'retencion_ganancias' => null,
                'ivas'                => [
                    ['iva_id' => $iva->id, 'neto' => 100000, 'iva_importe' => 21000],
                ],
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertEquals('completa', $respuesta->json('resumen.factura_guardada'));

        $ticket = ProviderOrderAfipTicket::where('provider_order_id', $compra->id)
                                            ->whereNull('provider_order_extra_cost_id')
                                            ->first();

        $this->assertNotNull($ticket, 'La factura tiene que quedar guardada en la compra.');
        $this->assertEquals('0003-00012345', $ticket->code);
        $this->assertEquals('30712345678', $ticket->emisor_cuit);
        $this->assertEquals('DISTRIBUIDORA DEL SUR S.A.', $ticket->emisor_razon_social);
        $this->assertEquals(2500, (float) $ticket->percepcion_iibb);
        $this->assertEquals(800, (float) $ticket->percepcion_iva);
        $this->assertEquals(124300, (float) $ticket->total);
        $this->assertNotNull($ticket->issued_at);

        $ivas = ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket->id)->get();

        $this->assertCount(1, $ivas);
        $this->assertEquals(100000, (float) $ivas[0]->neto);
        $this->assertEquals(21000, (float) $ivas[0]->iva_importe);

        $this->assertEquals(
            21000,
            (float) $ticket->total_iva,
            'total_iva se recalcula sumando el iva_importe de las filas, igual que set_total_iva().'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 10 — 🔴 La trampa del modo_facturacion                              */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 ModoFacturacionHelper, con modo_facturacion = 'sin factura', hace
     * ProviderOrderAfipTicket::where('provider_order_id', ...)->delete(): borra TODAS las facturas
     * de la compra. Por eso la factura se guarda DESPUÉS de procesar_pedido(), y por eso en ese
     * modo no se guarda sin permiso explícito del usuario.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function en_modo_sin_factura_no_se_guarda_la_factura_salvo_que_se_pase_a_manual()
    {
        $this->dar_extension($this->comercio());

        $iva = Iva::first();

        $datos_factura = [
            'guardar'             => true,
            'code'                => '0004-00099999',
            'issued_at'           => '2026-08-14',
            'emisor_cuit'         => '30712345678',
            'emisor_razon_social' => 'DISTRIBUIDORA DEL SUR S.A.',
            'total'               => 50000,
            'ivas'                => [
                ['iva_id' => is_null($iva) ? null : $iva->id, 'neto' => 41322.31, 'iva_importe' => 8677.69],
            ],
        ];

        /* --- Sin tildar la casilla: los artículos entran, la factura no. --- */
        $compra_sin  = $this->crear_compra(['modo_facturacion' => 'sin factura']);
        $articulo    = $this->crear_articulo('ART SIN FACTURA ESCANEO');
        $scan_sin    = $this->crear_escaneo($compra_sin);

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan_sin->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo, 2, 500)],
            'factura'   => $datos_factura,
        ]);

        $respuesta->assertStatus(200);

        $compra_sin->load('articles');

        $this->assertContains(
            $articulo->id,
            $compra_sin->articles->pluck('id')->all(),
            'Los artículos entran igual: lo que no se guarda es la factura.'
        );

        $this->assertEquals(
            0,
            ProviderOrderAfipTicket::where('provider_order_id', $compra_sin->id)->count(),
            'En modo "sin factura" no puede quedar ningún comprobante.'
        );

        $this->assertEquals('no', $respuesta->json('resumen.factura_guardada'));
        $this->assertNotNull(
            $respuesta->json('resumen.factura_motivo'),
            'El usuario tiene que enterarse de POR QUÉ no se guardó la factura.'
        );

        $compra_sin->refresh();
        $this->assertEquals(
            'sin factura',
            $compra_sin->modo_facturacion,
            'Sin permiso explícito no se le cambia la configuración de facturación a la compra.'
        );

        /* --- Tildando la casilla: la compra pasa a manual y la factura sobrevive. --- */
        $compra_manual = $this->crear_compra(['modo_facturacion' => 'sin factura']);
        $articulo_2    = $this->crear_articulo('ART PASA A MANUAL ESCANEO');
        $scan_manual   = $this->crear_escaneo($compra_manual);

        $datos_factura['pasar_a_manual'] = true;

        $respuesta_2 = $this->postJson('api/provider-order-scan/' . $scan_manual->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo_2, 1, 700)],
            'factura'   => $datos_factura,
        ]);

        $respuesta_2->assertStatus(200);

        $compra_manual->refresh();

        $this->assertEquals('manual', $compra_manual->modo_facturacion);

        $this->assertEquals(
            1,
            ProviderOrderAfipTicket::where('provider_order_id', $compra_manual->id)->count(),
            'Con la compra ya en manual, la factura tiene que sobrevivir a check_modo_facturacion().'
        );

        $this->assertEquals('completa', $respuesta_2->json('resumen.factura_guardada'));
    }

    /* ------------------------------------------------------------------ */
    /* 11, 12 — Idempotencia y registro                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Confirmar dos veces el mismo escaneo duplicaría los artículos y la deuda con el proveedor:
     * la segunda vez devuelve 409 y no toca nada.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function confirmar_dos_veces_devuelve_409_y_no_duplica_los_articulos()
    {
        $this->dar_extension($this->comercio());

        $compra   = $this->crear_compra();
        $articulo = $this->crear_articulo('ART DOBLE CONFIRMACION ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $payload = [
            'articulos' => [$this->item_existente($articulo, 3, 150)],
            'factura'   => ['guardar' => false],
        ];

        $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', $payload)->assertStatus(200);

        $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', $payload)->assertStatus(409);

        $compra->load('articles');

        $this->assertCount(
            1,
            $compra->articles->where('id', $articulo->id),
            'El artículo no puede quedar dos veces en la compra.'
        );

        $pivot = $compra->articles->firstWhere('id', $articulo->id)->pivot;

        $this->assertEquals(3, (float) $pivot->amount, 'La cantidad tampoco se puede duplicar.');
    }

    /**
     * Después de confirmar, el escaneo queda cerrado y con el registro de auditoría de lo que se
     * asentó de verdad.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function despues_de_confirmar_el_escaneo_queda_gestionado_con_su_resumen()
    {
        $this->dar_extension($this->comercio());

        $compra   = $this->crear_compra();
        $articulo = $this->crear_articulo('ART RESUMEN ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo, 9, 333)],
            'factura'   => ['guardar' => false],
        ])->assertStatus(200);

        $scan->refresh();

        $this->assertNotNull($scan->gestionado_at, 'gestionado_at es lo que apaga el botón rojo.');
        $this->assertEquals('confirmado', $scan->resultado_gestion);

        $this->assertIsArray($scan->aplicado);
        $this->assertEquals(1, $scan->aplicado['articulos_agregados']);
        $this->assertEquals(0, $scan->aplicado['articulos_creados']);
        $this->assertEquals('no', $scan->aplicado['factura_guardada']);

        /* El resultado se pisa con lo que el usuario dejó, no con lo que leyó la IA. */
        $this->assertIsArray($scan->resultado);
        $this->assertCount(1, $scan->resultado['articulos']);
        $this->assertEquals($articulo->id, $scan->resultado['articulos'][0]['article_id']);
    }

    /* ------------------------------------------------------------------ */
    /* 13, 14, 15 — descartar, visto, imagen                               */
    /* ------------------------------------------------------------------ */

    /**
     * Descartar apaga el botón rojo sin asentar nada: existe para que un escaneo que salió mal no
     * deje el botón prendido para siempre.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function descartar_deja_el_escaneo_cerrado_sin_tocar_la_compra()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();
        $scan   = $this->crear_escaneo($compra);

        $this->postJson('api/provider-order-scan/' . $scan->uuid . '/descartar')->assertStatus(200);

        $scan->refresh();

        $this->assertNotNull($scan->gestionado_at);
        $this->assertEquals('descartado', $scan->resultado_gestion);

        $compra->load('articles');

        $this->assertCount(0, $compra->articles, 'Descartar no puede asentar ningún artículo.');

        /* Y no se puede descartar dos veces. */
        $this->postJson('api/provider-order-scan/' . $scan->uuid . '/descartar')->assertStatus(409);
    }

    /**
     * Marcar el aviso como visto hace que la SPA deje de ofrecer esa corrida al arrancar. Sin
     * esto, cada recarga le vuelve a tirar el mismo modal de "terminó", para siempre.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function visto_marca_el_aviso_y_saca_la_corrida_de_en_curso()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();
        $scan   = $this->crear_escaneo($compra);

        $antes = $this->getJson('api/provider-order-scan/en-curso');
        $antes->assertStatus(200);

        $this->assertEquals($scan->uuid, $antes->json('run.uuid'));

        $this->postJson('api/provider-order-scan/' . $scan->uuid . '/visto')->assertStatus(200);

        $scan->refresh();

        $this->assertNotNull($scan->visto_at);

        $despues = $this->getJson('api/provider-order-scan/en-curso');
        $despues->assertStatus(200);

        $this->assertNull($despues->json('run'), 'Una corrida ya vista no se vuelve a ofrecer al arrancar la SPA.');
    }

    /**
     * La foto se sirve al comercio dueño y a nadie más: una factura tiene CUIT, razón social y
     * precios, y no puede salir por una URL adivinable.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function la_imagen_se_sirve_al_owner_y_no_a_otro_comercio()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();
        $scan   = $this->crear_escaneo($compra);

        $this->crear_imagen($scan, 1);

        $propia = $this->get('api/provider-order-scan/' . $scan->uuid . '/imagen/1');

        $propia->assertStatus(200);

        /* Una foto de otro comercio no existe para este usuario. */
        $ajeno       = $this->otro_comercio();
        $compra_ajena = $this->crear_compra(['user_id' => $ajeno->id]);

        $scan_ajeno = ProviderOrderScan::create([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $ajeno->id,
            'auth_user_id'      => $ajeno->id,
            'provider_order_id' => $compra_ajena->id,
            'estado'            => 'listo',
            'progreso'          => 100,
            'resultado'         => $this->resultado_de_ejemplo(),
        ]);

        $this->crear_imagen($scan_ajeno, 1);

        $this->getJson('api/provider-order-scan/' . $scan_ajeno->uuid . '/imagen/1')->assertStatus(404);
    }

    /**
     * El detalle devuelve el resultado solo cuando el escaneo terminó bien, y las páginas con su
     * nombre original para poder mostrarlas al lado de la tabla.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function show_devuelve_el_resultado_solo_cuando_el_escaneo_esta_listo()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();

        $procesando = $this->crear_escaneo($compra, [
            'estado'    => 'procesando',
            'progreso'  => 55,
            'paso'      => 'Analizando la factura con IA…',
            'resultado' => $this->resultado_de_ejemplo(),
        ]);

        $respuesta = $this->getJson('api/provider-order-scan/' . $procesando->uuid);

        $respuesta->assertStatus(200);
        $this->assertNull(
            $respuesta->json('resultado'),
            'Mientras no esté listo, el resultado va en null (mismo criterio que analysisStatus).'
        );

        $procesando->update(['estado' => 'listo', 'progreso' => 100, 'paso' => null]);

        $this->crear_imagen($procesando, 1);

        $listo = $this->getJson('api/provider-order-scan/' . $procesando->uuid);

        $listo->assertStatus(200);
        $this->assertCount(2, $listo->json('resultado.articulos'));
        $this->assertEquals(1, $listo->json('imagenes.0.orden'));
        $this->assertEquals('pagina1.jpg', $listo->json('imagenes.0.nombre_original'));
    }

    /* ------------------------------------------------------------------ */
    /* 16 — 🔴 La factura tiene que existir ANTES de que se calculen los    */
    /*      totales, o la deuda del proveedor queda mal                    */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 EL test que blinda el orden del paso 8.
     *
     * `procesar_pedido()` → `set_totales()` arma `provider_order.total_iva` sumando
     * `provider_order_afip_tickets.total_iva`, y después `set_current_acount()` escribe
     * `current_acount.debe = provider_order.total`. Si la factura se guarda DESPUÉS de
     * `procesar_pedido()`, todo eso se calcula con una factura que todavía no existe: la compra
     * queda con `total_iva = 0` y el proveedor con la deuda de menos.
     *
     * Escenario medido: 1 artículo (3 u. × $1.000 = $3.000) + factura con $21.000 de IVA, con
     * `total_with_iva = 1`. El proveedor tiene que quedar debiendo $24.000, no $3.000.
     *
     * ⚠️ A diferencia del resto del archivo, esta compra va con `generate_current_acount = 1` y
     * `total_with_iva = 1` a propósito: con los efectos apagados el defecto no se ve, que es
     * exactamente por qué pasó en verde la primera vez.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function confirmar_deja_el_iva_y_la_deuda_del_proveedor_con_los_importes_de_la_factura()
    {
        $this->dar_extension($this->comercio());

        /*
         * El total solo suma el IVA por encima si el comercio no es Monotributista (ver
         * NewProviderOrderHelper::set_totales + get_condicion_iva_precios). Se fija explícito
         * para que la cuenta del test no dependa de cómo quedó el fixture.
         */
        $comercio = $this->comercio();
        $comercio->condicion_iva_precios = 'RRII';
        $comercio->save();

        $compra = $this->crear_compra([
            'modo_facturacion'        => 'manual',
            'generate_current_acount' => 1,
            'total_with_iva'          => 1,
            'precios_incluyen_iva'    => 0,
        ]);

        /*
         * Sin credit_account del proveedor para esta moneda, set_current_acount() no tiene dónde
         * asentar la deuda. En producción la crea el alta del proveedor; acá se fuerza porque la
         * compra del test se inserta directo. Es idempotente.
         */
        CreditAccountHelper::crear_credit_accounts('provider', $compra->provider_id, $comercio->id);

        $articulo = $this->crear_articulo('ART DEUDA PROVEEDOR ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $iva = Iva::first();

        $this->assertNotNull($iva, 'La base de testing tiene que tener alícuotas de IVA sembradas.');

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo, 3, 1000)],
            'factura'   => [
                'guardar'             => true,
                'pasar_a_manual'      => false,
                'code'                => '0003-00077777',
                'issued_at'           => '2026-08-14',
                'emisor_cuit'         => '30712345678',
                'emisor_razon_social' => 'DISTRIBUIDORA DEL SUR S.A.',
                'total'               => 124300,
                'ivas'                => [
                    ['iva_id' => $iva->id, 'neto' => 100000, 'iva_importe' => 21000],
                ],
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertEquals('completa', $respuesta->json('resumen.factura_guardada'));

        $compra->refresh();

        $this->assertEquals(
            21000,
            (float) $compra->total_iva,
            'set_totales() suma el total_iva de las facturas: si la factura nace después, la compra queda en 0.'
        );

        $this->assertEquals(
            24000,
            (float) $compra->total,
            'Total = $3.000 de artículos + $21.000 de IVA de la factura.'
        );

        $current_acount = CurrentAcount::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull(
            $current_acount,
            'Con generate_current_acount = 1 la compra tiene que dejar su movimiento de cuenta corriente.'
        );

        $this->assertEquals(
            24000,
            (float) $current_acount->debe,
            'La deuda del proveedor es el total de la compra CON el IVA de la factura adentro.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 17 — 🔴 Descuento e IVA por renglón                                 */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 La bonificación de cada renglón ("Bonif. 10%") tiene que llegar al pivot como
     * `discount`, que es la columna que NewProviderOrderHelper::get_total_article() usa de
     * verdad. Leerla en la foto y no mandarla al pivot es cargar el renglón por su precio de
     * lista: 12 u. a $2.450,50 con 10% entraban como $29.406 en vez de $26.465,40.
     *
     * Y la alícuota del renglón tiene que llegar al pivot Y al artículo que se crea: un artículo
     * nuevo con `iva_id` null no aporta IVA en modo automático aunque el comprobante diga 21%.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function el_descuento_y_el_iva_de_cada_renglon_llegan_al_pivot_y_al_articulo_creado()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra([
            'modo_facturacion' => 'manual',
            'total_with_iva'   => 0,
        ]);

        $articulo = $this->crear_articulo('ART BONIFICADO ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $iva = Iva::first();

        $this->assertNotNull($iva, 'La base de testing tiene que tener alícuotas de IVA sembradas.');

        $nombre_nuevo = 'ART NUEVO CON IVA ESCANEO';

        $item_bonificado = $this->item_existente($articulo, 12, 2450.50);

        $item_bonificado['descuento_porcentaje'] = 10;
        $item_bonificado['iva_id']               = $iva->id;

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [
                $item_bonificado,
                [
                    'article_id'           => null,
                    'crear_en_catalogo'    => true,
                    'bar_code'             => null,
                    'codigo_proveedor'     => 'ESC-IVA',
                    'nombre'               => $nombre_nuevo,
                    'cantidad'             => 2,
                    'costo_unitario'       => 100,
                    'descuento_porcentaje' => null,
                    'iva_id'               => $iva->id,
                    'notas'                => null,
                ],
            ],
            'factura' => ['guardar' => false],
        ]);

        $respuesta->assertStatus(200);

        $compra->load('articles');

        $pivot = $compra->articles->firstWhere('id', $articulo->id)->pivot;

        $this->assertEquals(
            10,
            (float) $pivot->discount,
            'El descuento por renglón que leyó la IA tiene que quedar en el pivot: es la columna que usa el costeo.'
        );

        $this->assertEquals(
            $iva->id,
            (int) $pivot->iva_id,
            'La alícuota del renglón manda sobre la del catálogo: la factura es el dato fiscal de esta compra.'
        );

        /*
         * La plata. El renglón bonificado son 12 u. × $2.450,50 = $29.406 de lista, menos el 10%
         * = $26.465,40; el renglón nuevo son 2 u. × $100 = $200. Total de la compra: $26.665,40.
         * Sin el `discount` en el pivot la compra registraría $29.606 — $2.940,60 de más en la
         * cuenta corriente del proveedor por un solo renglón.
         */
        $compra->refresh();

        $this->assertEquals(
            2940.60,
            round((float) $compra->descuentos_individuales, 2),
            'El 10% de bonificación tiene que aparecer como descuento individual de la compra.'
        );

        $this->assertEquals(
            26465.40 + 200,
            round((float) $compra->total, 2),
            'Sin el discount en el pivot, el renglón bonificado entra por su precio de lista.'
        );

        /* Y el artículo creado nace con su alícuota, no en null. */
        $creado = Article::where('name', $nombre_nuevo)->first();

        $this->assertNotNull($creado);
        $this->assertEquals(
            $iva->id,
            (int) $creado->iva_id,
            'Un artículo nuevo sin iva_id no aporta IVA en modo automático, aunque el comprobante diga 21%.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 18 — pasar_a_manual sin factura que guardar                         */
    /* ------------------------------------------------------------------ */

    /**
     * Tildar "pasar a manual" no puede cambiar la configuración de facturación de la compra si
     * no se va a guardar ninguna factura.
     *
     * Es el caso del remito: `es_factura_afip = false`, así que el modal no manda `guardar`. Si
     * el cambio de modo no mira eso, la compra queda en 'manual' para siempre —con la factura
     * automática que el sistema venía recalculando congelada— a cambio de nada.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function pasar_a_manual_no_cambia_la_compra_si_no_hay_factura_para_guardar()
    {
        $this->dar_extension($this->comercio());

        $compra   = $this->crear_compra(['modo_facturacion' => 'sin factura']);
        $articulo = $this->crear_articulo('ART REMITO ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [$this->item_existente($articulo, 2, 500)],
            'factura'   => [
                'guardar'        => false,
                'pasar_a_manual' => true,
            ],
        ]);

        $respuesta->assertStatus(200);

        $compra->refresh();

        $this->assertEquals(
            'sin factura',
            $compra->modo_facturacion,
            'Sin factura que guardar, el cambio de modo_facturacion no se paga: es un efecto permanente a cambio de nada.'
        );

        $this->assertEquals(
            0,
            ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(),
            'Y no puede quedar ningún comprobante, porque no se pidió guardar ninguno.'
        );

        /* Los artículos del remito entran igual: eso es lo que sí se pidió. */
        $compra->load('articles');

        $this->assertContains($articulo->id, $compra->articles->pluck('id')->all());
    }

    /* ------------------------------------------------------------------ */
    /* 19 — El bloqueo del 409 vence                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Un escaneo trabado en 'pendiente' no puede bloquear esa compra para siempre.
     *
     * El único desatascador es `failed()` del job, que solo corre si el job llegó a ejecutarse:
     * con el worker caído al despachar, o con el job perdido, la fila queda en 'pendiente' sin
     * vencimiento. Y no hay salida por la interfaz — 'descartar' solo se alcanza desde el modal
     * de revisión, que solo se abre con el botón rojo, que solo se enciende con estado 'listo'.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function un_escaneo_viejo_en_pendiente_no_bloquea_la_compra_para_siempre()
    {
        $this->dar_extension($this->comercio());

        $compra = $this->crear_compra();

        /* Un escaneo abandonado hace tres horas: el job tiene timeout de 10 minutos. */
        $viejo = now()->subHours(3);

        $this->crear_escaneo($compra, [
            'estado'     => 'pendiente',
            'progreso'   => 0,
            'resultado'  => null,
            'created_at' => $viejo,
            'updated_at' => $viejo,
        ]);

        $respuesta = $this->post('api/provider-order-scan', [
            'provider_order_id' => $compra->id,
            'imagenes'          => [UploadedFile::fake()->image('pagina1.jpg', 400, 500)],
        ], ['Accept' => 'application/json']);

        $respuesta->assertStatus(
            202,
            'Un escaneo en pendiente más viejo que la ventana está abandonado: no puede tapiar la compra.'
        );

        $nuevo = ProviderOrderScan::where('provider_order_id', $compra->id)
                                    ->where('estado', 'pendiente')
                                    ->orderBy('id', 'DESC')
                                    ->first();

        $this->carpetas_a_limpiar[] = 'provider_order_scans/' . $nuevo->user_id . '/' . $nuevo->uuid;

        $this->assertEquals(
            2,
            ProviderOrderScan::where('provider_order_id', $compra->id)->count(),
            'El escaneo nuevo tiene que haberse creado igual.'
        );

        Queue::assertPushed(RunProviderOrderScanJob::class);
    }

    /* ------------------------------------------------------------------ */
    /* 20 — 🔴 La carrera de la doble confirmación                         */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 El chequeo de `gestionado_at` de afuera de la transacción no alcanza: entre ese SELECT
     * y el UPDATE final pasa toda la confirmación (movimientos de stock de N artículos), y dos
     * requests concurrentes —dos pestañas, o un reintento después de un timeout— pasan los dos.
     * Sin lock, los artículos con `crear_en_catalogo` se crean dos veces y se crean DOS
     * comprobantes con el mismo `code`.
     *
     * Cómo se simula la carrera sin dos procesos: se escucha `TransactionBeginning`, que es
     * exactamente la ventana entre el chequeo de afuera (ya pasado) y la relectura de adentro.
     * Cuando arranca la transacción, otro "request" deja el escaneo gestionado; la relectura con
     * lockForUpdate() lo tiene que ver y salir por 409 sin tocar nada.
     *
     * La aserción de que la consulta con `for update` existe es la que distingue este camino del
     * chequeo rápido de afuera: si el 409 hubiera salido antes de abrir la transacción, no
     * habría ninguna consulta bloqueante en el log.
     *
     * @group escaneo-factura-compra
     * @test
     * @return void
     */
    public function confirmar_revalida_el_escaneo_con_lock_adentro_de_la_transaccion()
    {
        $this->dar_extension($this->comercio());

        $compra   = $this->crear_compra();
        $articulo = $this->crear_articulo('ART CARRERA ESCANEO');
        $scan     = $this->crear_escaneo($compra);

        $consultas = [];

        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = strtolower($query->sql);
        });

        $ya_disparado = false;

        Event::listen(TransactionBeginning::class, function () use ($scan, &$ya_disparado) {

            if ($ya_disparado) {
                return;
            }

            $ya_disparado = true;

            /* El otro request gana la carrera justo acá. */
            DB::table('provider_order_scans')
                ->where('id', $scan->id)
                ->update([
                    'gestionado_at'     => now(),
                    'resultado_gestion' => 'confirmado',
                ]);
        });

        $respuesta = $this->postJson('api/provider-order-scan/' . $scan->uuid . '/confirmar', [
            'articulos' => [
                [
                    'article_id'        => null,
                    'crear_en_catalogo' => true,
                    'bar_code'          => null,
                    'codigo_proveedor'  => 'ESC-CARRERA',
                    'nombre'            => 'ART FANTASMA DE LA CARRERA ESCANEO',
                    'cantidad'          => 4,
                    'costo_unitario'    => 90,
                    'notas'             => null,
                ],
                $this->item_existente($articulo, 3, 150),
            ],
            'factura' => ['guardar' => false],
        ]);

        $respuesta->assertStatus(
            409,
            'El request que perdió la carrera no puede asentar nada: tiene que salir por 409.'
        );

        $hubo_lock = false;

        foreach ($consultas as $sql) {
            if (strpos($sql, 'provider_order_scans') !== false && strpos($sql, 'for update') !== false) {
                $hubo_lock = true;
            }
        }

        $this->assertTrue(
            $hubo_lock,
            'La confirmación tiene que releer el escaneo con lockForUpdate() adentro de la transacción.'
        );

        /* Y el rollback tiene que haber dejado la compra y el catálogo como estaban. */
        $compra->load('articles');

        $this->assertCount(0, $compra->articles, 'La confirmación perdedora no puede adjuntar artículos.');

        $this->assertEquals(
            0,
            Article::where('name', 'ART FANTASMA DE LA CARRERA ESCANEO')->count(),
            'Sin el lock, los artículos con crear_en_catalogo se crean dos veces en el catálogo del cliente.'
        );
    }
}
