<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\LinkDePdfIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\SaleController;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-capacidades-y-hilos (22/9/2026) — el presupuesto que el asistente arma y el link
 * del PDF que pasa.
 *
 * De dónde sale cada test, en la conversación de demo3 del 22/9:
 *  - #56 "no puedo armar presupuestos desde acá" → proponer_presupuesto.
 *  - #72 "el PDF de la venta se genera desde la pantalla de Ventas" y #74 "no tengo un link para
 *    pasarte" → consultar_link_de_pdf.
 *
 * Lo que protege:
 *  - Que el presupuesto se cree por `BudgetController::store()` y que el `total` del payload pase
 *    la validación de margen 3 contra `BudgetHelper::getTotal()`: si no cuadra, el controller tira
 *    Exception → rollback → 500, o sea un 500 en producción cada vez que alguien lo pida.
 *  - Que NO toque stock, ni caja, ni cuenta corriente, ni cree la venta.
 *  - 🔴 Que el link de la venta sea `sale/pdf/{id}` y NO `sale/ticket-pdf/{id}`, que es una ruta
 *    muerta (el método no existe y da 500).
 *  - Que un `api_url` corrupto (`/public/public`) se normalice, y que sin ninguna URL se DIGA en
 *    vez de devolver un link sin dominio.
 *  - Que un número de otro comercio no devuelva link: las rutas del PDF son públicas.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Presupuesto_y_link_de_pdf_Test extends EmpresaTestCase
{
    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    /** @var string|null api_url original del dueño, para restaurarla. */
    protected $api_url_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p53']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->api_url_original = $this->dueno->api_url;

        $this->dar_extension('asistente_ia');
        $this->dar_extension(PropuestaPresupuestoIaHelper::EXTENSION);

        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['api_url' => $this->api_url_original]);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->extensiones_enganchadas[] = $extencion;
    }

    /**
     * Un artículo del dueño con precio y stock fijos.
     *
     * @param  string  $nombre
     * @param  float  $precio
     * @param  float  $stock
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $precio = 2000, $stock = 10)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => $precio,
            'cost'        => $precio / 2,
            'stock'       => $stock,
            'iva_id'      => 2,
        ]);
    }

    /**
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Hacé un presupuesto')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  int  $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * Llama a la tool de lectura por el registro real del service, que es como la llama el loop.
     *
     * @param  AiConversation  $conversation
     * @param  array  $input
     * @return array
     */
    protected function link_de_pdf($conversation, array $input)
    {
        $service = new AsistenteIaService();

        $resultados = $service->execute_tool_calls([
            ['type' => 'tool_use', 'id' => 'toolu_p53', 'name' => 'consultar_link_de_pdf', 'input' => $input],
        ], $conversation);

        return json_decode($resultados[0]['content'], true);
    }

    // =====================================================================
    // (c) El presupuesto — el #56
    // =====================================================================

    /**
     * 🔴 EL CAMINO CRÍTICO: el presupuesto se crea, con el total que cuadra.
     *
     * Si el `total` del payload no coincide con `BudgetHelper::getTotal()` dentro de un margen de 3,
     * `store()` lanza Exception, hace rollback y devuelve 500. Este test es el que fija que la
     * cuenta del helper es la misma que la del back.
     *
     * @test
     */
    public function el_presupuesto_se_crea_con_el_total_que_cuadra()
    {
        $martillo = $this->articulo_de_prueba('zz-p53 Martillo presupuesto', 2000);
        $pinza = $this->articulo_de_prueba('zz-p53 Pinza presupuesto', 500);

        $cliente = Client::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();

        $stock_antes = Article::find($martillo->id)->stock;
        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();

        list($conversation, $assistant) = $this->conversacion('Hacele un presupuesto de 3 martillos y 2 pinzas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [
                ['articulo' => 'zz-p53 Martillo presupuesto', 'cantidad' => 3],
                ['articulo' => 'zz-p53 Pinza presupuesto', 'cantidad' => 2],
            ],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $http->assertStatus(200);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado(), (string) $accion->error_mensaje);

        $presupuesto = Budget::find((int) $accion->resultado->presupuesto_id);

        $this->assertNotNull($presupuesto, 'No quedó ningún presupuesto en la base.');

        $this->assertSame((int) $cliente->id, (int) $presupuesto->client_id);
        $this->assertSame((int) $this->dueno->id, (int) $presupuesto->user_id);

        // 3 × 2000 + 2 × 500 = 7000.
        $this->assertEqualsWithDelta(7000, (float) $presupuesto->total, self::DELTA);

        // Y el back saca el mismo número de los renglones: eso es lo que el margen de 3 compara.
        $this->assertEqualsWithDelta(7000, (float) BudgetHelper::getTotal($presupuesto), 3);

        $this->assertCount(2, $presupuesto->articles);

        // 🔴 Un presupuesto sin confirmar NO descuenta stock ni crea la venta.
        $this->assertSame(PropuestaPresupuestoIaHelper::ESTADO_SIN_CONFIRMAR, (int) $presupuesto->budget_status_id);
        $this->assertNull($presupuesto->sale, 'El presupuesto no puede nacer con una venta colgada.');
        $this->assertEqualsWithDelta((float) $stock_antes, (float) Article::find($martillo->id)->stock, self::DELTA);
        $this->assertSame($ventas_antes, Sale::where('user_id', $this->dueno->id)->count());

        // El número que el modelo tiene para decir sale del resultado y es el de la base.
        $this->assertSame((int) $presupuesto->num, (int) $accion->resultado->numero);
        $this->assertStringContainsString('Presupuesto N° ' . $presupuesto->num, $accion->resultado->texto);
    }

    /**
     * Con un descuento de venta el total también cuadra: `getTotal()` lo aplica renglón por renglón
     * y el helper sobre el sub total, y las dos cuentas tienen que caer adentro del margen.
     *
     * @test
     */
    public function con_descuento_de_venta_el_total_sigue_cuadrando()
    {
        $this->articulo_de_prueba('zz-p53 Martillo descuento', 2000);

        list($conversation, $assistant) = $this->conversacion('Presupuesto con el descuento e2e');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'                => [['articulo' => 'zz-p53 Martillo descuento', 'cantidad' => 4]],
            'cliente'              => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'descuento_porcentaje' => TestingFerreteriaSeeder::DESCUENTO_VENTA_PORCENTAJE,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado(), (string) $accion->error_mensaje);

        $presupuesto = Budget::find((int) $accion->resultado->presupuesto_id);

        // 4 × 2000 = 8000, menos 15 % = 6800.
        $this->assertEqualsWithDelta(6800, (float) $presupuesto->total, self::DELTA);
        $this->assertEqualsWithDelta((float) $presupuesto->total, (float) BudgetHelper::getTotal($presupuesto), 3);
        $this->assertCount(1, $presupuesto->discounts);
    }

    /**
     * Sin cliente no hay presupuesto: el toggle de la pantalla ni se dibuja, y de ahí sale la lista
     * de precios.
     *
     * @test
     */
    public function sin_cliente_pregunta_para_quien_es()
    {
        $this->articulo_de_prueba('zz-p53 Martillo sin cliente', 2000);

        list($conversation, $assistant) = $this->conversacion('Hacé un presupuesto de 2 martillos');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items' => [['articulo' => 'zz-p53 Martillo sin cliente', 'cantidad' => 2]],
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertSame(0, Budget::where('user_id', $this->dueno->id)->where('observations', 'zz-p53')->count());
    }

    /**
     * Sin la extensión `budgets` no se propone nada. 🔴 El backend NO la chequea en ningún lado
     * (`BudgetController::store()` no la mira), así que este espejo es lo único que impide que una
     * cuenta sin el módulo termine con presupuestos que no puede ver en ninguna pantalla.
     *
     * @test
     */
    public function sin_la_extension_de_presupuestos_no_se_propone_nada()
    {
        $extencion = ExtencionEmpresa::where('slug', PropuestaPresupuestoIaHelper::EXTENSION)->first();

        $this->dueno->extencions()->detach($extencion->id);
        $this->dueno->load('extencions');

        $this->articulo_de_prueba('zz-p53 Martillo sin extension', 2000);

        list($conversation, $assistant) = $this->conversacion('Hacé un presupuesto');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zz-p53 Martillo sin extension', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('módulo', (string) $respuesta['error']);
    }

    /**
     * El payload es el de `vender_presupuestos.js::crear()`: los artículos van PLANOS (sin `pivot`,
     * que es la forma del update) y los cuatro buckets que el asistente no arma viajan vacíos —
     * `getTotal()` los suma, así que uno con contenido y sin sumar sería el 500 del total.
     *
     * @test
     */
    public function el_payload_es_el_de_la_pantalla()
    {
        $articulo = $this->articulo_de_prueba('zz-p53 Martillo payload', 1000);

        $cliente = Client::where('user_id', $this->dueno->id)->where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();

        $renglones = [[
            'article'        => $articulo,
            'cantidad'       => 2.0,
            'precio'         => 1000.0,
            'precio_dictado' => null,
            'origen'         => 'lista',
        ]];

        $payload = PropuestaPresupuestoIaHelper::payload($renglones, $cliente, null, null, null, '', ['sub_total' => 2000.0, 'total' => 2000.0, 'descuento_monto' => 0.0]);

        $this->assertSame(PropuestaPresupuestoIaHelper::ESTADO_SIN_CONFIRMAR, $payload['budget_status_id']);
        $this->assertSame(0, $payload['omitir_en_cuenta_corriente'], 'Un presupuesto va SIEMPRE a la cuenta corriente al confirmarse.');
        $this->assertSame([], $payload['services']);
        $this->assertSame([], $payload['promocion_vinotecas']);
        $this->assertSame([], $payload['combos']);
        $this->assertSame([], $payload['surchages']);
        $this->assertNull($payload['forzar_total_monto']);

        $this->assertArrayNotHasKey('pivot', $payload['articles'][0], 'Los artículos del ALTA van planos; con `pivot` es la forma del update.');
        $this->assertSame(2.0, $payload['articles'][0]['amount']);
        $this->assertSame(1000.0, $payload['articles'][0]['price']);
    }

    // =====================================================================
    // (d) y (e) Los links de PDF — los #72 y #74
    // =====================================================================

    /**
     * 🔴 EL LINK DE UNA VENTA ES `sale/pdf/{id}` Y NADA MÁS.
     *
     * `sale/ticket-pdf/{id}` (`routes/web.php:448`) apunta a `SaleController@ticketPdf`, un método
     * que NO EXISTE: esa URL da 500. Este test fija las dos cosas de una.
     *
     * @test
     */
    public function el_link_de_la_venta_es_sale_pdf_y_no_la_ruta_muerta()
    {
        $this->assertFalse(
            method_exists(SaleController::class, 'ticketPdf'),
            'Si ticketPdf existe, la ruta muerta dejó de serlo y este comentario hay que revisarlo.'
        );

        User::where('id', $this->dueno->id)->update(['api_url' => 'https://api-p53.comerciocity.com']);

        $venta = Sale::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($venta, 'El fixture tiene que tener al menos una venta.');

        list($conversation) = $this->conversacion('Pasame el PDF de esa venta');

        $respuesta = $this->link_de_pdf($conversation, ['tipo' => 'venta', 'numero' => (int) $venta->num]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->assertStringContainsString('/sale/pdf/' . $venta->id, $respuesta['link']);
        $this->assertStringNotContainsString('ticket-pdf', $respuesta['link']);
        $this->assertStringNotContainsString('afip-ticket', $respuesta['link']);
        $this->assertStringStartsWith('https://api-p53.comerciocity.com', $respuesta['link']);
    }

    /**
     * El del presupuesto lleva los dos flags obligatorios de la ruta, con el combo que la SPA usa
     * para WhatsApp (con precios, sin imágenes).
     *
     * @test
     */
    public function el_link_del_presupuesto_lleva_los_dos_flags()
    {
        User::where('id', $this->dueno->id)->update(['api_url' => 'https://api-p53.comerciocity.com']);

        $cliente = Client::where('user_id', $this->dueno->id)->first();

        $presupuesto = Budget::create([
            'num'              => 990053,
            'client_id'        => $cliente->id,
            'user_id'          => $this->dueno->id,
            'total'            => 1234,
            'budget_status_id' => 1,
        ]);

        list($conversation) = $this->conversacion('Pasame el PDF del presupuesto');

        $respuesta = $this->link_de_pdf($conversation, ['tipo' => 'presupuesto', 'numero' => 990053]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->assertStringContainsString('/budget/pdf/' . $presupuesto->id . '/1/0', $respuesta['link']);
    }

    /**
     * 🔴 UN `api_url` CORRUPTO NO SE PASA TAL CUAL. Hay historia de valores con `/public/public`
     * persistidos (existe el comando `normalizar_api_url` por eso), y `build_pdf_url()` los
     * concatena sin mirar.
     *
     * @test
     */
    public function un_api_url_con_public_repetido_se_normaliza()
    {
        User::where('id', $this->dueno->id)->update(['api_url' => 'https://api-p53.comerciocity.com/public/public']);

        $base = LinkDePdfIaHelper::base_publica($this->dueno->id);

        $this->assertStringNotContainsString('/public/public', $base);
        $this->assertStringStartsWith('https://api-p53.comerciocity.com', $base);
    }

    /**
     * 🔴 Y SIN NINGUNA URL SE DICE, en vez de devolver `/sale/pdf/123` — un link sin dominio que no
     * le abre a nadie y que nadie detecta hasta que el cliente lo reclama.
     *
     * @test
     */
    public function sin_ninguna_url_configurada_no_se_inventa_un_link()
    {
        User::where('id', $this->dueno->id)->update(['api_url' => null]);

        config(['app.APP_URL' => '']);

        $venta = Sale::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        list($conversation) = $this->conversacion('Pasame el PDF');

        $respuesta = $this->link_de_pdf($conversation, ['tipo' => 'venta', 'numero' => (int) $venta->num]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertNull($respuesta['link']);
        $this->assertStringContainsString('dirección pública', (string) $respuesta['error']);
    }

    /**
     * 🔴 LAS RUTAS DEL PDF SON PÚBLICAS, así que la pertenencia la chequea el asistente: un número
     * que no es de este negocio se contesta como "no encontré", nunca con un link que funciona.
     *
     * @test
     */
    public function un_comprobante_de_otro_negocio_no_devuelve_link()
    {
        User::where('id', $this->dueno->id)->update(['api_url' => 'https://api-p53.comerciocity.com']);

        $otro = User::create([
            'name'     => 'Otro comercio p53',
            'email'    => 'otro-p53-' . uniqid() . '@test.local',
            'password' => bcrypt('secreto'),
        ]);

        $cliente_ajeno = Client::create(['name' => 'Cliente ajeno p53', 'user_id' => $otro->id]);

        Budget::create([
            'num'              => 990153,
            'client_id'        => $cliente_ajeno->id,
            'user_id'          => $otro->id,
            'total'            => 999,
            'budget_status_id' => 1,
        ]);

        list($conversation) = $this->conversacion('Pasame el PDF del 990153');

        $respuesta = $this->link_de_pdf($conversation, ['tipo' => 'presupuesto', 'numero' => 990153]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertNull($respuesta['link']);
    }

    /**
     * Un tipo que no es ninguno de los dos, o un número que no es número, se contestan y no se
     * adivinan.
     *
     * @test
     */
    public function el_tipo_y_el_numero_se_validan()
    {
        list($conversation) = $this->conversacion('Pasame el PDF');

        $sin_tipo = $this->link_de_pdf($conversation, ['tipo' => 'remito', 'numero' => 1]);

        $this->assertFalse(!empty($sin_tipo['ok']));
        $this->assertNull($sin_tipo['link']);

        $sin_numero = $this->link_de_pdf($conversation, ['tipo' => 'venta', 'numero' => 0]);

        $this->assertFalse(!empty($sin_numero['ok']));
        $this->assertNull($sin_numero['link']);
    }

    /**
     * La herramienta del presupuesto está declarada, se despacha y pasa por la puerta de
     * auto-confirmación; la del PDF es de LECTURA y por eso no tiene tipo de tarjeta.
     *
     * @test
     */
    public function las_dos_herramientas_estan_donde_corresponde()
    {
        $this->assertContains('proponer_presupuesto', HerramientasDeCarga::nombres());

        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        $this->assertStringContainsString("case 'proponer_presupuesto':", $contenido);

        $this->assertContains(AiMessageAction::TIPO_PRESUPUESTO, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertNotContains(AiMessageAction::TIPO_PRESUPUESTO, HerramientasDeCarga::AUTO_CONFIRMABLES);

        $service = new AsistenteIaService();

        $this->assertContains('consultar_link_de_pdf', $service->nombres_de_lectura());
        $this->assertNotContains('consultar_link_de_pdf', HerramientasDeCarga::nombres(true));
    }
}
