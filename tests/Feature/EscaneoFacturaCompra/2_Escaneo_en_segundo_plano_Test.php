<?php

namespace Tests\Feature\EscaneoFacturaCompra;

use App\Jobs\RunProviderOrderScanJob;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Misión escaneo-factura-compra — Bloque B: el motor del escaneo en segundo plano.
 *
 * Lo que protege este archivo es el mecanismo, que es donde está el riesgo:
 *
 * - Que un escaneo SIEMPRE termine en un estado final con aviso. El usuario sacó la foto y
 *   se fue a otra pantalla: un error sin aviso es una espera infinita.
 * - 🔴 Que las N páginas viajen JUNTAS en UNA sola llamada. Es el requisito textual de
 *   Lucas y la única forma de reconstruir una tabla cortada entre la página 1 y la 2.
 * - 🔴 Que el matcheo NUNCA cree un artículo. Un código mal leído por OCR que crea un
 *   artículo fantasma en el catálogo del cliente es el peor resultado posible de esta
 *   funcionalidad.
 * - Que una respuesta mal formada de la IA no rompa nada ni meta basura en el resultado.
 *
 * 🔴 SEGURO DE GASTO: el setUp apaga `services.anthropic.api_key`. La clave de verdad vive
 * en el `.env.testing` del slot, así que un test que salga a la red gasta plata real en
 * cada corrida de la suite. Los tests que necesitan probar la llamada la prenden ELLOS y
 * registran su `Http::fake()` en el mismo paso (ver fake_de_anthropic()).
 *
 * Ojo con `Http::fake()`: acumula stubs y gana el PRIMERO que matchea. Por eso no hay un
 * `Http::fake()` catch-all en el setUp: taparía los stubs puntuales de cada test.
 */
class Escaneo_en_segundo_plano_Test extends EmpresaTestCase
{
    /**
     * Dueño de los datos (la empresa del fixture).
     *
     * @var \App\Models\User
     */
    protected $owner;

    /**
     * Compra a la que pertenecen los escaneos de este archivo.
     *
     * @var \App\Models\ProviderOrder
     */
    protected $compra;

    /**
     * Rutas absolutas de las fotos escritas en disco por los tests, para borrarlas al
     * terminar. `DatabaseTransactions` revierte la base, pero no los archivos.
     *
     * @var array
     */
    protected $archivos_creados = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* 🔴 Nunca la clave real del .env.testing: cada test prende la suya si la necesita. */
        config(['services.anthropic.api_key' => null]);

        Notification::fake();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        $this->compra = ProviderOrder::create([
            'provider_id' => $proveedor->id,
            'user_id'     => $this->owner->id,
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_creados as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
                @rmdir(dirname($ruta));
            }
        }

        $this->archivos_creados = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     *  Helpers de fixture
     * ------------------------------------------------------------------ */

    /**
     * Crea un escaneo de la compra del fixture.
     *
     * @param  array  $overrides  Campos a pisar (estado, auth_user_id, resultado, etc.)
     * @return \App\Models\ProviderOrderScan
     */
    protected function escaneo(array $overrides = [])
    {
        return ProviderOrderScan::create(array_merge([
            'uuid'              => Str::uuid()->toString(),
            'user_id'           => $this->owner->id,
            'auth_user_id'      => $this->owner->id,
            'provider_order_id' => $this->compra->id,
            'estado'            => 'pendiente',
            'progreso'          => 0,
        ], $overrides));
    }

    /**
     * Escribe una foto real (un JPEG chiquito hecho con GD) en el disco local y crea su
     * fila hija. Tiene que ser una imagen de verdad: el servicio la abre con Intervention
     * antes de mandarla.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @param  int                            $orden  Número de página
     * @return \App\Models\ProviderOrderScanImage
     */
    protected function foto(ProviderOrderScan $scan, int $orden)
    {
        $ruta_relativa = 'provider_order_scans/' . $scan->user_id . '/' . $scan->uuid . '/' . $orden . '.jpg';
        $ruta_absoluta = storage_path('app/' . $ruta_relativa);

        $carpeta = dirname($ruta_absoluta);

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $gd = imagecreatetruecolor(120, 90);
        imagefilledrectangle($gd, 0, 0, 119, 89, imagecolorallocate($gd, 235, 235, 235));
        /* Un par de trazos oscuros para que no sea un rectángulo plano. */
        imagefilledrectangle($gd, 10, 20 + ($orden * 5), 110, 24 + ($orden * 5), imagecolorallocate($gd, 30, 30, 30));

        ob_start();
        imagejpeg($gd);
        $binario = ob_get_clean();

        imagedestroy($gd);

        file_put_contents($ruta_absoluta, $binario);

        $this->archivos_creados[] = $ruta_absoluta;

        return ProviderOrderScanImage::create([
            'provider_order_scan_id' => $scan->id,
            'provider_order_id'      => $scan->provider_order_id,
            'user_id'                => $scan->user_id,
            'orden'                  => $orden,
            'path'                   => $ruta_relativa,
            'mime'                   => 'image/jpeg',
            'bytes'                  => strlen($binario),
            'nombre_original'        => 'pagina' . $orden . '.jpg',
        ]);
    }

    /**
     * Prende la clave y registra el stub de Anthropic con el texto de respuesta indicado.
     *
     * @param  string  $texto_de_respuesta  Lo que devuelve el bloque de texto del modelo
     * @param  array   $usage               Bloque `usage` de la respuesta
     * @return void
     */
    protected function fake_de_anthropic(string $texto_de_respuesta, array $usage = ['input_tokens' => 4210, 'output_tokens' => 1980])
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'   => 'claude-modelo-fake',
                'content' => [
                    ['type' => 'text', 'text' => $texto_de_respuesta],
                ],
                'usage'   => $usage,
            ], 200),
        ]);
    }

    /**
     * JSON de una factura leída, con los valores por defecto del camino feliz.
     *
     * @param  array  $overrides  Claves de primer nivel a pisar (articulos, columnas_detectadas, …)
     * @return string
     */
    protected function json_de_factura(array $overrides = []): string
    {
        $base = [
            'columnas_detectadas' => [
                ['clave' => 'nombre', 'etiqueta_en_factura' => 'DESCRIPCIÓN', 'confianza' => 0.97],
                ['clave' => 'cantidad', 'etiqueta_en_factura' => 'CANT.', 'confianza' => 0.91],
            ],
            'articulos' => [
                [
                    'fila'                 => 1,
                    'pagina'               => 1,
                    'bar_code'             => null,
                    'codigo_proveedor'     => null,
                    'nombre'               => TestingFerreteriaSeeder::ARTICULO_CENTINELA,
                    'cantidad'             => 12,
                    'costo_unitario'       => 2450.5,
                    'descuento_porcentaje' => 10,
                    'iva_porcentaje'       => 21,
                    'total_linea'          => 26465.4,
                    'notas'                => null,
                    'confianza_fila'       => 0.93,
                    'campos_dudosos'       => ['costo_unitario'],
                ],
            ],
            'factura' => [
                'es_factura_afip'     => true,
                'confianza'           => 0.9,
                'tipo_comprobante'    => 'A',
                'punto_venta'         => '0003',
                'numero'              => '00012345',
                'issued_at'           => '2026-08-14',
                'emisor_cuit'         => '30-71234567-8',
                'emisor_razon_social' => 'DISTRIBUIDORA DEL SUR S.A.',
                'receptor_cuit'       => null,
                'neto_gravado'        => 100000,
                'total_iva'           => 21000,
                'total'               => '$ 124.300,00',
                'ivas'                => [
                    ['porcentaje' => 21, 'neto' => 100000, 'importe' => 21000],
                ],
                'percepcion_iibb'     => 2500,
                'percepcion_iva'      => null,
                'retencion_iibb'      => null,
                'retencion_iva'       => null,
                'retencion_ganancias' => null,
                'campos_dudosos'      => [],
            ],
            'avisos' => [],
        ];

        return json_encode(array_merge($base, $overrides), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Corre el job sobre un escaneo y lo devuelve refrescado.
     *
     * @param  \App\Models\ProviderOrderScan  $scan
     * @return \App\Models\ProviderOrderScan
     */
    protected function correr_el_job(ProviderOrderScan $scan)
    {
        (new RunProviderOrderScanJob($scan->id))->handle();

        return $scan->fresh();
    }

    /* ------------------------------------------------------------------ *
     *  1. Sin clave de API: error legible, y avisa igual
     * ------------------------------------------------------------------ */

    /**
     * Sin ANTHROPIC_API_KEY el escaneo no puede correr, pero tiene que terminar igual: en
     * error, con un motivo que se entienda, y con el aviso mandado.
     *
     * Lo que se protege es que el escaneo nunca quede colgado en "procesando": el modal de
     * la SPA giraría para siempre esperando un estado que no va a cambiar.
     *
     * @test
     * @return void
     */
    public function sin_clave_de_api_el_escaneo_queda_en_error_y_avisa_igual()
    {
        /* La clave quedó en null desde el setUp: no hay IA contratada. */
        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('error', $scan->estado, 'Sin clave de API el escaneo tiene que terminar en error, no quedar colgado.');
        $this->assertNotNull($scan->error);
        $this->assertNotEquals('', trim((string) $scan->error), 'El usuario nunca ve un error vacío.');
        $this->assertNull($scan->resultado, 'Un escaneo en error no deja resultado.');

        Notification::assertSentTo($this->owner, GlobalNotification::class);
    }

    /* ------------------------------------------------------------------ *
     *  2. El aviso es el correcto y NO lleva el resultado
     * ------------------------------------------------------------------ */

    /**
     * El aviso abre el modal nuevo, va dirigido solo a quien sacó la foto, y lleva lo
     * mínimo para poder ir a buscar el detalle.
     *
     * 🔴 `resultado` NO viaja en el aviso: el aviso solo dice que terminó. El resumen se
     * pide recién si el usuario aprieta el botón, que es justamente lo que le permite
     * ignorarlo sin haber pagado nada por él.
     *
     * @test
     * @return void
     */
    public function el_aviso_dice_que_termino_y_no_lleva_el_resultado()
    {
        $this->fake_de_anthropic($this->json_de_factura());

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado);
        $this->assertEquals(100, (int) $scan->progreso);

        Notification::assertSentTo(
            $this->owner,
            GlobalNotification::class,
            function ($notification) use ($scan) {
                /* Tiene que abrir el modal de esta funcionalidad, no el genérico. */
                $this->assertEquals('provider_order_scan_ready', $notification->notification_modal);

                /* Dirigido solo a quien sacó la foto, no a los cuatro compañeros. */
                $this->assertEquals($scan->auth_user_id, $notification->is_only_for_auth_user);

                $this->assertEquals($scan->uuid, $notification->provider_order_scan['uuid']);
                $this->assertEquals($scan->provider_order_id, $notification->provider_order_scan['provider_order_id']);
                $this->assertEquals('listo', $notification->provider_order_scan['estado']);

                /* 🔴 El resumen no viaja en el aviso. */
                $this->assertArrayNotHasKey('resultado', $notification->provider_order_scan);

                return true;
            }
        );
    }

    /* ------------------------------------------------------------------ *
     *  3. Sin auth_user_id no se le avisa a nadie
     * ------------------------------------------------------------------ */

    /**
     * Un escaneo sin `auth_user_id` no dispara ningún aviso.
     *
     * El canal `global_notification.{owner_id}` lo escuchan TODOS los empleados del
     * comercio. Sin saber a quién avisarle, avisar significa interrumpir a todos por una
     * foto que sacó uno solo: mejor mudo que a todos. El resultado sigue estando y aparece
     * igual en el listado de compras.
     *
     * @test
     * @return void
     */
    public function sin_auth_user_id_no_le_avisa_a_nadie()
    {
        $this->fake_de_anthropic($this->json_de_factura());

        $scan = $this->escaneo(['auth_user_id' => null]);
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado, 'El escaneo corre igual: lo que no se hace es avisar.');

        Notification::assertNothingSent();
    }

    /* ------------------------------------------------------------------ *
     *  4. 🔴 Una sola llamada, con las tres páginas juntas
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Las tres páginas van JUNTAS en UNA sola llamada.
     *
     * Esta es la aserción que protege el requisito textual de Lucas. Una tabla que empieza
     * en la página 1 y sigue en la 2 solo se puede reconstruir viendo las dos a la vez: si
     * alguien "optimiza" esto a una llamada por página, el escaneo sigue andando y
     * devolviendo artículos, pero parte en dos los renglones cortados y pierde los totales
     * de la última página. Falla en silencio, que es lo peor que puede pasar.
     *
     * Se chequea también el orden: rótulo de página antes de cada imagen, e instrucciones
     * al final, después de TODAS las imágenes (§3.1).
     *
     * @test
     * @return void
     */
    public function manda_las_tres_paginas_juntas_en_una_sola_llamada()
    {
        $this->fake_de_anthropic($this->json_de_factura());

        $scan = $this->escaneo();
        $this->foto($scan, 1);
        $this->foto($scan, 2);
        $this->foto($scan, 3);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado);
        $this->assertEquals(3, (int) $scan->resultado['paginas_procesadas']);

        /* Exactamente UNA request: ni una por página, ni un reintento. */
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }

            $data = $request->data();

            $this->assertArrayHasKey('messages', $data);
            $this->assertCount(1, $data['messages'], 'Es un solo mensaje de rol user con todo adentro.');

            $content = $data['messages'][0]['content'];

            $imagenes = 0;
            foreach ($content as $bloque) {
                if (isset($bloque['type']) && $bloque['type'] === 'image') {
                    $imagenes++;
                }
            }

            $this->assertEquals(3, $imagenes, 'Las tres páginas tienen que ir en el mismo content.');

            /* Rótulo antes de cada imagen: es lo que le dice a la IA qué página está viendo. */
            $this->assertEquals('text', $content[0]['type']);
            $this->assertEquals('PÁGINA 1 de 3', $content[0]['text']);
            $this->assertEquals('image', $content[1]['type']);
            $this->assertEquals('PÁGINA 2 de 3', $content[2]['text']);
            $this->assertEquals('PÁGINA 3 de 3', $content[4]['text']);

            /* Las instrucciones van al final, después de todas las imágenes. */
            $ultimo = $content[count($content) - 1];
            $this->assertEquals('text', $ultimo['type']);

            return true;
        });
    }

    /* ------------------------------------------------------------------ *
     *  5. Parseo defensivo: markdown y texto alrededor
     * ------------------------------------------------------------------ */

    /**
     * El JSON envuelto en backticks y con un párrafo adelante se parsea igual.
     *
     * El prompt pide JSON pelado, pero los modelos agregan backticks y una frase de cortesía
     * cada tanto. Si eso rompiera el escaneo, la funcionalidad fallaría de forma aleatoria
     * para el usuario, sin ninguna diferencia visible entre la corrida que anduvo y la que no.
     *
     * @test
     * @return void
     */
    public function parsea_el_json_aunque_venga_con_backticks_y_texto_alrededor()
    {
        $respuesta = "Claro, acá va lo que leí de la factura:\n```json\n" . $this->json_de_factura() . "\n```";

        $this->fake_de_anthropic($respuesta);

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado, 'Los backticks no pueden voltear un escaneo.');
        $this->assertCount(1, $scan->resultado['articulos']);
        $this->assertEquals(TestingFerreteriaSeeder::ARTICULO_CENTINELA, $scan->resultado['articulos'][0]['nombre']);

        /* "$ 124.300,00" pasa por parseNumericValue y llega como número. */
        $this->assertEquals(124300, $scan->resultado['factura']['total']);

        /* El CUIT queda solo con dígitos, sin los guiones que trajo la IA. */
        $this->assertEquals('30712345678', $scan->resultado['factura']['emisor_cuit']);

        /* `code` se arma con punto de venta + número. */
        $this->assertEquals('0003-00012345', $scan->resultado['factura']['code']);
    }

    /* ------------------------------------------------------------------ *
     *  6. Parseo defensivo: respuesta que no tiene JSON
     * ------------------------------------------------------------------ */

    /**
     * Una respuesta sin JSON deja el escaneo en error con un mensaje legible, y avisa.
     *
     * @test
     * @return void
     */
    public function una_respuesta_sin_json_deja_el_escaneo_en_error_y_avisa()
    {
        $this->fake_de_anthropic('No pude leer la factura: la foto está muy borrosa.');

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('error', $scan->estado);
        $this->assertNotEquals('', trim((string) $scan->error));
        $this->assertNull($scan->resultado);

        Notification::assertSentTo($this->owner, GlobalNotification::class);
    }

    /* ------------------------------------------------------------------ *
     *  7. Filas basura descartadas
     * ------------------------------------------------------------------ */

    /**
     * Una fila sin nombre, sin código de proveedor y sin código de barras no es un
     * artículo: es un subtotal, un "SON PESOS…" o una leyenda de AFIP que la IA metió en la
     * tabla. Se descarta.
     *
     * Si no se descartara, el usuario vería filas fantasma con un importe y sin nombre, y
     * confirmarlas le adjuntaría a la compra un artículo creado de la nada.
     *
     * @test
     * @return void
     */
    public function las_filas_sin_identificador_se_descartan()
    {
        $articulos = [
            [
                'fila'           => 1,
                'pagina'         => 1,
                'nombre'         => TestingFerreteriaSeeder::ARTICULO_CENTINELA,
                'cantidad'       => 3,
                'costo_unitario' => 100,
                'campos_dudosos' => [],
            ],
            [
                /* Basura: el renglón del subtotal. */
                'fila'             => 2,
                'pagina'           => 1,
                'bar_code'         => null,
                'codigo_proveedor' => null,
                'nombre'           => null,
                'cantidad'         => null,
                'total_linea'      => 124300,
                'campos_dudosos'   => [],
            ],
        ];

        $this->fake_de_anthropic($this->json_de_factura(['articulos' => $articulos]));

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado);
        $this->assertCount(1, $scan->resultado['articulos'], 'La fila sin ningún identificador no es un artículo.');
        $this->assertEquals(TestingFerreteriaSeeder::ARTICULO_CENTINELA, $scan->resultado['articulos'][0]['nombre']);
    }

    /* ------------------------------------------------------------------ *
     *  8. 🔴 Matcheo contra el catálogo, sin crear nada
     * ------------------------------------------------------------------ */

    /**
     * 🔴 El matcheo encuentra lo que existe y NUNCA crea lo que no existe.
     *
     * El import de Excel, cuando no encuentra un artículo, lo crea inactivo. Acá no: se
     * marca `sin_match` con `crear_en_catalogo` en false y decide el usuario en el modal de
     * revisión. Un código mal leído por OCR creando un artículo fantasma en el catálogo del
     * cliente es el peor resultado posible de esta funcionalidad, porque nadie se entera
     * hasta que el catálogo está lleno de basura.
     *
     * @test
     * @return void
     */
    public function matchea_contra_el_catalogo_y_nunca_crea_un_articulo()
    {
        $nombre_inexistente = 'XYZ INEXISTENTE';

        $articulos = [
            [
                'fila'             => 1,
                'pagina'           => 1,
                'bar_code'         => null,
                'codigo_proveedor' => null,
                'nombre'           => TestingFerreteriaSeeder::ARTICULO_CENTINELA,
                'cantidad'         => 12,
                'costo_unitario'   => 2450.5,
                'campos_dudosos'   => [],
            ],
            [
                'fila'             => 2,
                'pagina'           => 1,
                'bar_code'         => null,
                'codigo_proveedor' => null,
                'nombre'           => $nombre_inexistente,
                'cantidad'         => 6,
                'costo_unitario'   => 1180,
                'campos_dudosos'   => [],
            ],
        ];

        $this->fake_de_anthropic($this->json_de_factura(['articulos' => $articulos]));

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado);

        $encontrado = $scan->resultado['articulos'][0];
        $sin_match  = $scan->resultado['articulos'][1];

        $this->assertEquals('encontrado', $encontrado['match']['estado']);
        $this->assertEquals('name', $encontrado['match']['criterio'], 'Sin código de barras ni de proveedor, la cascada tiene que llegar al nombre.');
        $this->assertEquals(
            $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->id,
            $encontrado['match']['article_id']
        );

        $this->assertEquals('sin_match', $sin_match['match']['estado']);
        $this->assertNull($sin_match['match']['article_id']);
        $this->assertNull($sin_match['match']['criterio']);

        /* 🔴 Ni siquiera el sin_match viene marcado para crear: lo decide el usuario. */
        $this->assertFalse($sin_match['crear_en_catalogo']);
        $this->assertFalse($encontrado['crear_en_catalogo']);

        /* Y arrancan todos tildados para incluir. */
        $this->assertTrue($encontrado['incluir']);
        $this->assertTrue($sin_match['incluir']);

        /* 🔴 La prueba de fuego: el job no creó ningún artículo. */
        $this->assertEquals(
            0,
            Article::where('name', $nombre_inexistente)->count(),
            'El job NUNCA puede crear un artículo en el catálogo del cliente.'
        );
    }

    /* ------------------------------------------------------------------ *
     *  9. Confianzas clampeadas
     * ------------------------------------------------------------------ */

    /**
     * Una confianza fuera de rango se clampea a [0,1], con el mismo criterio que
     * AiProviderAnalyzer::enrich_column_mapping().
     *
     * Importa porque el frontend pinta la celda según ese número: un 7.5 le daría a una
     * lectura dudosa el mismo verde que a una segura.
     *
     * @test
     * @return void
     */
    public function las_confianzas_fuera_de_rango_se_clampean()
    {
        $columnas = [
            ['clave' => 'nombre', 'etiqueta_en_factura' => 'DESCRIPCIÓN', 'confianza' => 7.5],
            ['clave' => 'cantidad', 'etiqueta_en_factura' => 'CANT.', 'confianza' => -1],
            /* Clave que no está en el contrato: se descarta la columna entera. */
            ['clave' => 'inventada_por_la_ia', 'etiqueta_en_factura' => 'RARO', 'confianza' => 0.8],
        ];

        $this->fake_de_anthropic($this->json_de_factura(['columnas_detectadas' => $columnas]));

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado);
        $this->assertCount(2, $scan->resultado['columnas_detectadas'], 'La clave que no está en el contrato se descarta.');
        $this->assertEquals(1, $scan->resultado['columnas_detectadas'][0]['confianza']);
        $this->assertEquals(0, $scan->resultado['columnas_detectadas'][1]['confianza']);
    }

    /* ------------------------------------------------------------------ *
     *  10. Registro de consumo
     * ------------------------------------------------------------------ */

    /**
     * Cada escaneo exitoso deja su fila en `ai_token_usages`.
     *
     * Es la contabilidad con la que Lucas va a decidir si esta funcionalidad sigue incluida
     * en la suscripción. Sin la fila, el escaneo es gasto invisible.
     *
     * @test
     * @return void
     */
    public function registra_el_consumo_de_tokens()
    {
        $this->fake_de_anthropic($this->json_de_factura(), ['input_tokens' => 4210, 'output_tokens' => 1980]);

        $scan = $this->escaneo();
        $this->foto($scan, 1);

        $scan = $this->correr_el_job($scan);

        $this->assertEquals('listo', $scan->estado);

        $consumo = AiTokenUsage::where('proceso', 'escaneo_factura_compra')
            ->where('referencia_id', $scan->id)
            ->first();

        $this->assertNotNull($consumo, 'Todo escaneo exitoso tiene que dejar su fila de consumo.');
        $this->assertEquals($this->owner->id, $consumo->user_id);
        $this->assertEquals($scan->auth_user_id, $consumo->auth_user_id);
        $this->assertEquals(4210, $consumo->input_tokens);
        $this->assertEquals(1980, $consumo->output_tokens);

        /* Los mismos números quedan en el resultado, para mostrarlos si algún día hace falta. */
        $this->assertEquals(4210, $scan->resultado['tokens']['input']);
        $this->assertEquals(1980, $scan->resultado['tokens']['output']);
    }

    /* ------------------------------------------------------------------ *
     *  11. failed(): el escaneo nunca queda colgado en "procesando"
     * ------------------------------------------------------------------ */

    /**
     * `failed()` rescata el caso en que el worker mata el job por timeout o por un error
     * fatal de PHP, y el `handle()` nunca llega a su propio catch. Sin esto, el escaneo
     * quedaría en "procesando" para siempre y el modal de la SPA giraría sin fin.
     *
     * Y no pisa un escaneo que ya había terminado bien: en una carrera rarísima entre el
     * worker y el guardado, marcar como error algo que ya está listo le haría perder al
     * usuario un resultado que sí tiene.
     *
     * @test
     * @return void
     */
    public function failed_deja_el_escaneo_en_error_salvo_que_ya_estuviera_listo()
    {
        $colgado = $this->escaneo(['estado' => 'procesando', 'progreso' => 40]);

        (new RunProviderOrderScanJob($colgado->id))->failed(new \Exception('el worker lo mató por timeout'));

        $colgado = $colgado->fresh();

        $this->assertEquals('error', $colgado->estado);
        $this->assertNotEquals('', trim((string) $colgado->error));

        /* Y avisa, como cualquier otra salida de error. */
        Notification::assertSentTo($this->owner, GlobalNotification::class);

        $terminado = $this->escaneo([
            'estado'    => 'listo',
            'progreso'  => 100,
            'resultado' => ['version' => 1, 'articulos' => []],
        ]);

        (new RunProviderOrderScanJob($terminado->id))->failed(new \Exception('llegó tarde'));

        $terminado = $terminado->fresh();

        $this->assertEquals('listo', $terminado->estado, 'Un escaneo ya terminado no se pisa con error.');
        $this->assertNull($terminado->error);
    }
}
