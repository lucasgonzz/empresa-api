<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaStockIaHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Stock\SetArticleStock\CheckToAddress;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-capacidades-y-hilos (22/9/2026) — el stock por depósito que el asistente SÍ
 * puede tocar, y el listado de depósitos que lo negaba.
 *
 * De dónde sale cada test, en la conversación de demo3 del 22/9:
 *  - #24 "ese movimiento de stock entre sucursales no lo puedo hacer" → proponer_movimiento_de_stock.
 *  - #42 "no puedo cargarle stock a Florida desde acá" → proponer_stock_en_deposito.
 *  - #38-#42 "la sucursal Florida no me figura" y dos mensajes después la lista para la venta →
 *    el recorte a 20 y los domicilios de compradores de consultar_stock_por_deposito.
 *
 * Lo que protege, y cada cosa tapa una trampa medida en el relevamiento:
 *  - 🔴 TRAMPA 1: `POST api/stock-movement` no valida nada y el signo del origen lo decide el
 *    NOMBRE del concepto. Hay un test que demuestra el modo de fallar (con otro concepto el stock
 *    se suma en los DOS depósitos) y otro que fija que la constante del helper es exactamente el
 *    literal que `CheckFromAddress` compara.
 *  - 🔴 TRAMPA 2: `pivot.amount` de `update_addresses_stock` es el stock FINAL, no un delta. El
 *    test de "sumale 10" verifica que suma y no pisa.
 *  - Que los depósitos que no cambian viajen con su número de hoy y queden intactos.
 *  - Que un `model_id` inexistente no se pueda colar (el endpoint devuelve 201 vacío sin hacer nada).
 *  - El espejo de `article.edit_stock_only_sucursal`.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Stock_por_deposito_por_asistente_Test extends EmpresaTestCase
{
    /** Delta para comparar cantidades del pivot, que es decimal(12,2). */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    /** @var array<int,Address> Sucursales creadas por un test, para borrarlas. */
    protected $sucursales_creadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p52']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->dar_extension('asistente_ia');

        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Engancha una extensión al dueño (creando la fila si la base no la tiene).
     *
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
     * Una sucursal del dueño.
     *
     * @param  string  $nombre
     * @return Address
     */
    protected function sucursal($nombre)
    {
        $sucursal = Address::create([
            'street'  => $nombre,
            'user_id' => $this->dueno->id,
        ]);

        $this->sucursales_creadas[] = $sucursal;

        return $sucursal;
    }

    /**
     * Un artículo del dueño.
     *
     * @param  string  $nombre
     * @param  float|null  $stock
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $stock = null)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 1000,
            'cost'        => 500,
            'stock'       => $stock,
            'iva_id'      => 2,
        ]);
    }

    /**
     * Le pone stock al artículo en un depósito, escribiendo el pivot directo: es el estado DEL QUE
     * SE PARTE, no la carga que se está probando.
     *
     * @param  Article  $articulo
     * @param  Address  $deposito
     * @param  float  $cantidad
     * @return void
     */
    protected function stock_en($articulo, $deposito, $cantidad)
    {
        $articulo->addresses()->syncWithoutDetaching([$deposito->id => ['amount' => $cantidad]]);
    }

    /**
     * El `amount` del pivot, leído de la base en este mismo instante.
     *
     * @param  int  $article_id
     * @param  int  $address_id
     * @return float|null  null si el artículo no tiene ese depósito.
     */
    protected function pivot($article_id, $address_id)
    {
        $fila = DB::table('address_article')
            ->where('article_id', (int) $article_id)
            ->where('address_id', (int) $address_id)
            ->first(['amount']);

        return is_null($fila) ? null : (float) $fila->amount;
    }

    /**
     * Conversación del dueño (o de quien se le pase) con su assistant 'pendiente'.
     *
     * @param  string  $pedido
     * @param  User|null  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Movelo', $persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
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
     * Ejecuta una herramienta de carga por el despacho real y devuelve lo que ve el modelo.
     *
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
     * Confirma la tarjeta por el endpoint real, el mismo que aprieta la persona.
     *
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
     * Un empleado del dueño con los permisos que se le pasen por slug.
     *
     * @param  array<int,string>  $slugs
     * @param  int|null  $address_id
     * @return User
     */
    protected function empleado(array $slugs, $address_id = null)
    {
        $empleado = User::create([
            'name'       => 'Empleado p52',
            'email'      => 'empleado-p52-' . uniqid() . '@test.local',
            'password'   => Hash::make('secreto'),
            'owner_id'   => $this->dueno->id,
            'address_id' => $address_id,
        ]);

        $ids = [];

        foreach ($slugs as $slug) {

            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug, 'model_name' => 'article']);
            }

            $ids[] = $permiso->id;
        }

        $empleado->permissions()->sync($ids);
        $empleado->load('permissions');

        return $empleado;
    }

    // =====================================================================
    // (h) El listado de depósitos que negaba una sucursal que existe
    // =====================================================================

    /**
     * 🔴 LOS DOMICILIOS DE LOS COMPRADORES DE LA TIENDA NO SON SUCURSALES.
     *
     * `addresses` guarda también los domicilios de los compradores del ecommerce (los escribe
     * `tienda-api`, que comparte la base) y esas filas llevan el `user_id` del dueño. Sin el
     * `whereNull('buyer_id')` entraban al listado y se comían el cupo, que es la mitad del "la
     * sucursal Florida no me figura" del 22/9.
     *
     * @test
     */
    public function el_listado_de_depositos_no_cuenta_los_domicilios_de_los_compradores()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina listado');

        $florida = $this->sucursal('zz-p52 Florida');

        $domicilio = Address::create([
            'street'   => 'zz-p52 Casa de un comprador',
            'user_id'  => $this->dueno->id,
            'buyer_id' => 9999,
        ]);

        $respuesta = ConsultasSistemaIaHelper::stock_por_deposito($this->dueno->id, 'zz-p52 Pastina listado');

        $nombres = [];

        foreach ($respuesta['depositos'] as $deposito) {
            $nombres[] = $deposito['deposito'];
        }

        $this->assertContains('zz-p52 Florida', $nombres, 'La sucursal del negocio tiene que figurar.');
        $this->assertNotContains('zz-p52 Casa de un comprador', $nombres, 'Un domicilio de comprador no es una sucursal del negocio.');

        // Y tampoco se cuenta en el total, que es el número que el modelo repite.
        $this->assertSame(count($nombres), (int) $respuesta['depositos_en_esta_lista']);

        $domicilio->delete();
    }

    /**
     * 🔴 CON MÁS DE 20 SUCURSALES, LAS DE ID MÁS ALTO YA NO SE CAEN SIN AVISO.
     *
     * El default de esta consulta dejó de ser MAX_RESULTS (20) y pasó a ser el techo duro (100): el
     * reparto de un artículo es un dato chico que solo sirve entero, y una sucursal que no viaja el
     * modelo la lee como una sucursal que no existe. Eso fue literalmente el #38 del 22/9.
     *
     * @test
     */
    public function con_mas_de_veinte_sucursales_siguen_viajando_todas()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina veinticinco');

        $ultima = null;

        for ($i = 1; $i <= 25; $i++) {
            $ultima = $this->sucursal('zz-p52 Sucursal ' . str_pad($i, 2, '0', STR_PAD_LEFT));
        }

        $respuesta = ConsultasSistemaIaHelper::stock_por_deposito($this->dueno->id, 'zz-p52 Pastina veinticinco');

        $this->assertGreaterThan(
            ConsultasSistemaIaHelper::MAX_RESULTS,
            (int) $respuesta['depositos_en_esta_lista'],
            'Con 25 sucursales cargadas, la lista se quedó en el tope viejo de 20.'
        );

        $this->assertSame(
            (int) $respuesta['depositos_encontrados'],
            (int) $respuesta['depositos_en_esta_lista'],
            'Quedaron sucursales afuera.'
        );

        $nombres = [];

        foreach ($respuesta['depositos'] as $deposito) {
            $nombres[] = $deposito['deposito'];
        }

        $this->assertContains($ultima->street, $nombres, 'La última sucursal creada (id más alto) se cayó del listado.');

        // Y sin recorte no hay aviso: el aviso es para cuando la lista SÍ está cortada.
        $this->assertArrayNotHasKey('aviso', $respuesta);
    }

    /**
     * Y si alguna vez recorta de verdad, lo dice con todas las letras en vez de dejar que el modelo
     * niegue un depósito que existe.
     *
     * @test
     */
    public function cuando_recorta_de_verdad_lo_avisa()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina recorte');

        $this->sucursal('zz-p52 Recorte A');
        $this->sucursal('zz-p52 Recorte B');

        $respuesta = ConsultasSistemaIaHelper::stock_por_deposito($this->dueno->id, 'zz-p52 Pastina recorte', 1);

        $this->assertSame(1, (int) $respuesta['depositos_en_esta_lista']);
        $this->assertGreaterThan(1, (int) $respuesta['depositos_encontrados']);
        $this->assertArrayHasKey('aviso', $respuesta);
        $this->assertStringContainsString('recortada', $respuesta['aviso']);
    }

    // =====================================================================
    // (a) Mover stock entre depósitos — el #24
    // =====================================================================

    /**
     * 🔴 EL CAMINO CRÍTICO: MOVER RESTA EN EL ORIGEN Y SUMA EN EL DESTINO.
     *
     * Es la mitad que tapa la TRAMPA 1: con el concepto bien escrito, `CheckFromAddress` invierte
     * el signo en el origen. El test de abajo demuestra qué pasa cuando el concepto está mal.
     *
     * @test
     */
    public function mover_stock_resta_en_el_origen_y_suma_en_el_destino()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina Perla');

        $central = $this->sucursal('zz-p52 Central');
        $florida = $this->sucursal('zz-p52 Florida mover');

        $this->stock_en($articulo, $central, 10);
        $this->stock_en($articulo, $florida, 3);

        list($conversation, $assistant) = $this->conversacion('Mové 2 pastinas de Central a Florida');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_movimiento_de_stock', [
            'articulo' => 'zz-p52 Pastina Perla',
            'cantidad' => 2,
            'desde'    => 'zz-p52 Central',
            'hacia'    => 'zz-p52 Florida mover',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        // Antes de confirmar no se movió nada: la tarjeta es una tarjeta.
        $this->assertEqualsWithDelta(10, $this->pivot($articulo->id, $central->id), self::DELTA);

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $http->assertStatus(200);

        $this->assertEqualsWithDelta(8, $this->pivot($articulo->id, $central->id), self::DELTA, 'El origen no bajó.');
        $this->assertEqualsWithDelta(5, $this->pivot($articulo->id, $florida->id), self::DELTA, 'El destino no subió.');

        // Y el texto que el modelo tiene para decir sale del resultado, con los números de verdad.
        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado());
        $this->assertStringContainsString('quedó en 8', $accion->resultado->texto);
        $this->assertStringContainsString('en 5', $accion->resultado->texto);
    }

    /**
     * 🔴 POR QUÉ EL CONCEPTO ES UNA CONSTANTE Y NO UN TEXTO QUE ALGUIEN ESCRIBE.
     *
     * Este test NO prueba el asistente: prueba el endpoint crudo, para dejar medido el modo de
     * fallar. Con un concepto que `SetConcepto` no puede resolver, `CheckFromAddress` lo deja en
     * null, `get_amount_for_from_address()` NO invierte el signo y el stock SE SUMA EN LOS DOS
     * depósitos. Si alguien "arregla" la constante del helper escribiéndola distinto, este test
     * explica qué se rompe y el de arriba se pone rojo.
     *
     * ⚠️ DATO MEDIDO ACÁ, Y ES BUENA NOTICIA: una errata de tilde o de mayúscula NO rompe el
     * movimiento. La colación de la base es accent/case-insensitive, así que `Mov manual entre
     * depósitos` resuelve igual al concepto correcto. Lo que rompe es un nombre DISTINTO, que es lo
     * que este test usa — y por eso la constante sigue siendo la defensa.
     *
     * @test
     */
    public function con_el_concepto_mal_escrito_el_endpoint_suma_en_los_dos_depositos()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina concepto');

        $central = $this->sucursal('zz-p52 Central concepto');
        $florida = $this->sucursal('zz-p52 Florida concepto');

        $this->stock_en($articulo, $central, 10);
        $this->stock_en($articulo, $florida, 0);

        $request = Request::create('/api/stock-movement', 'POST', [
            'model_id'                     => $articulo->id,
            'amount'                       => 2,
            'observations'                 => '',
            'from_address_id'              => $central->id,
            'to_address_id'                => $florida->id,
            'article_variant_id'           => 0,
            // Un nombre que no está en `concepto_stock_movements`: SetConcepto lo deja en null.
            'concepto_stock_movement_name' => 'zz-p52 concepto que no existe',
        ]);

        $request->setUserResolver(function () {
            return $this->dueno;
        });

        app(StockMovementController::class)->store($request);

        $this->assertEqualsWithDelta(
            12,
            $this->pivot($articulo->id, $central->id),
            self::DELTA,
            'Si esto ya no suma en los dos lados, la trampa 1 dejó de existir y el comentario del helper hay que actualizarlo.'
        );

        $this->assertEqualsWithDelta(2, $this->pivot($articulo->id, $florida->id), self::DELTA);
    }

    /**
     * Y la constante del helper es EXACTAMENTE el literal que las dos guardas del stock comparan.
     *
     * @test
     */
    public function el_concepto_del_helper_es_el_que_invierte_el_signo()
    {
        $this->assertSame('Mov manual entre depositos', PropuestaStockIaHelper::CONCEPTO_MOVIMIENTO);

        $this->assertContains(
            PropuestaStockIaHelper::CONCEPTO_MOVIMIENTO,
            CheckToAddress::CONCEPTOS_QUE_REPARTEN,
            'El concepto del helper tiene que poder abrir un depósito.'
        );

        // La inversión del signo vive en CheckFromAddress y se lee como texto: el método no es
        // introspectable de otra forma y el literal es lo único que importa.
        $check_from = file_get_contents(app_path('Http/Controllers/Stock/SetArticleStock/CheckFromAddress.php'));

        $this->assertStringContainsString(
            "'" . PropuestaStockIaHelper::CONCEPTO_MOVIMIENTO . "'",
            $check_from,
            'CheckFromAddress ya no compara contra este concepto: el movimiento sumaría en los dos depósitos.'
        );

        // Y el payload que arma el helper lo lleva tal cual, en la clave que lee el controller.
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina payload');
        $desde = $this->sucursal('zz-p52 Payload A');
        $hacia = $this->sucursal('zz-p52 Payload B');

        $payload = PropuestaStockIaHelper::payload_de_movimiento($articulo, 3, $desde, $hacia, '');

        $this->assertSame(PropuestaStockIaHelper::CONCEPTO_MOVIMIENTO, $payload['concepto_stock_movement_name']);
        $this->assertSame(3.0, $payload['amount'], 'El monto viaja SIEMPRE positivo: el signo lo pone el concepto.');
    }

    /**
     * 🔴 UN ARTÍCULO SIN FILA EN EL DEPÓSITO DE ORIGEN NO SE "MUEVE": SE EXPLICA.
     *
     * `CheckFromAddress` no toca el origen si el artículo no tiene ningún depósito, así que ese
     * movimiento sumaría en el destino y no restaría en ningún lado. La respuesta manda a la otra
     * herramienta, que es la única que puede abrir un depósito.
     *
     * @test
     */
    public function mover_desde_un_deposito_donde_el_articulo_no_tiene_stock_no_se_propone()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina sin origen', 7);

        $central = $this->sucursal('zz-p52 Sin origen A');
        $florida = $this->sucursal('zz-p52 Sin origen B');

        list($conversation, $assistant) = $this->conversacion('Mové 2 de A a B');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_movimiento_de_stock', [
            'articulo' => 'zz-p52 Pastina sin origen',
            'cantidad' => 2,
            'desde'    => 'zz-p52 Sin origen A',
            'hacia'    => 'zz-p52 Sin origen B',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('proponer_stock_en_deposito', (string) $respuesta['error']);

        $this->assertNull($this->pivot($articulo->id, $florida->id), 'No se tocó ningún pivot, como corresponde.');
    }

    /**
     * Un artículo que no existe entre los del dueño se corta ANTES de llamar al endpoint: `store()`
     * devuelve 201 con el cuerpo vacío sin haber hecho nada, así que un "éxito" de ahí no prueba nada.
     *
     * @test
     */
    public function un_articulo_inexistente_no_llega_al_endpoint()
    {
        $this->sucursal('zz-p52 Inexistente A');
        $this->sucursal('zz-p52 Inexistente B');

        list($conversation, $assistant) = $this->conversacion('Mové 2 del artículo que no existe');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_movimiento_de_stock', [
            'articulo' => 'zz-p52 esto no existe en ningun catalogo',
            'cantidad' => 2,
            'desde'    => 'zz-p52 Inexistente A',
            'hacia'    => 'zz-p52 Inexistente B',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Mover al mismo depósito no es un movimiento.
     *
     * @test
     */
    public function el_origen_y_el_destino_no_pueden_ser_el_mismo()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina mismo');

        $central = $this->sucursal('zz-p52 Mismo A');
        $this->sucursal('zz-p52 Mismo B');

        $this->stock_en($articulo, $central, 10);

        list($conversation, $assistant) = $this->conversacion('Mové 2 de A a A');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_movimiento_de_stock', [
            'articulo' => 'zz-p52 Pastina mismo',
            'cantidad' => 2,
            'desde'    => 'zz-p52 Mismo A',
            'hacia'    => 'zz-p52 Mismo A',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('el mismo', (string) $respuesta['error']);
    }

    // =====================================================================
    // (b) El stock de UN depósito — el #42
    // =====================================================================

    /**
     * 🔴 TRAMPA 2: "SUMALE 10" SUMA, NO PISA.
     *
     * `pivot.amount` de `PUT api/article-update-addresses` es el stock FINAL del depósito. Mandado
     * crudo, "sumale 10 unidades a Florida" dejaba Florida EN 10, no en 13.
     *
     * @test
     */
    public function sumar_stock_a_un_deposito_suma_y_no_pisa()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina sumar');

        $florida = $this->sucursal('zz-p52 Florida sumar');
        $central = $this->sucursal('zz-p52 Central sumar');

        $this->stock_en($articulo, $florida, 3);
        $this->stock_en($articulo, $central, 40);

        list($conversation, $assistant) = $this->conversacion('Sumale 10 unidades a Florida');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p52 Pastina sumar',
            'deposito' => 'zz-p52 Florida sumar',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $http->assertStatus(200);

        $this->assertEqualsWithDelta(
            13,
            $this->pivot($articulo->id, $florida->id),
            self::DELTA,
            'Quedó en 10: el amount se mandó como delta y pisó el stock del depósito.'
        );

        // 🔴 Y el depósito que no se tocó quedó donde estaba: viajó con su número de hoy.
        $this->assertEqualsWithDelta(40, $this->pivot($articulo->id, $central->id), self::DELTA);
    }

    /**
     * Restar resta, y fijar deja el número exacto.
     *
     * @test
     */
    public function restar_y_fijar_hacen_lo_que_dicen()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina modos');

        $deposito = $this->sucursal('zz-p52 Modos');

        $this->stock_en($articulo, $deposito, 20);

        list($conversation, $assistant) = $this->conversacion('Sacale 5');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p52 Pastina modos',
            'deposito' => 'zz-p52 Modos',
            'cantidad' => 5,
            'modo'     => 'restar',
        ]);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(15, $this->pivot($articulo->id, $deposito->id), self::DELTA);

        list($conversation2, $assistant2) = $this->conversacion('Dejalo en 7');

        $respuesta2 = $this->herramienta($conversation2, $assistant2, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p52 Pastina modos',
            'deposito' => 'zz-p52 Modos',
            'cantidad' => 7,
            'modo'     => 'fijar',
        ]);

        $this->confirmar($conversation2, $assistant2, $respuesta2['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(7, $this->pivot($articulo->id, $deposito->id), self::DELTA);
    }

    /**
     * Restar más de lo que hay no deja el depósito en negativo: se explica.
     *
     * @test
     */
    public function no_se_puede_dejar_un_deposito_en_negativo()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina negativo');

        $deposito = $this->sucursal('zz-p52 Negativo');

        $this->stock_en($articulo, $deposito, 2);

        list($conversation, $assistant) = $this->conversacion('Sacale 5');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p52 Pastina negativo',
            'deposito' => 'zz-p52 Negativo',
            'cantidad' => 5,
            'modo'     => 'restar',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertEqualsWithDelta(2, $this->pivot($articulo->id, $deposito->id), self::DELTA);
    }

    /**
     * 🔴 ES LA ÚNICA VÍA QUE ABRE UN DEPÓSITO NUEVO, y eso es lo que el #42 pedía de verdad:
     * "cargale 10 unidades a Florida" sobre un artículo que en Florida no tenía nada.
     *
     * @test
     */
    public function abrir_un_deposito_nuevo_para_el_articulo()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina abrir');

        $central = $this->sucursal('zz-p52 Abrir central');
        $florida = $this->sucursal('zz-p52 Abrir florida');

        $this->stock_en($articulo, $central, 6);

        $this->assertNull($this->pivot($articulo->id, $florida->id), 'De partida el depósito no existe para el artículo.');

        list($conversation, $assistant) = $this->conversacion('Cargale 10 a Florida');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p52 Pastina abrir',
            'deposito' => 'zz-p52 Abrir florida',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(10, $this->pivot($articulo->id, $florida->id), self::DELTA);
        $this->assertEqualsWithDelta(6, $this->pivot($articulo->id, $central->id), self::DELTA);
    }

    // =====================================================================
    // Permisos y wiring
    // =====================================================================

    /**
     * 🔴 ESPEJO DE `article.edit_stock_only_sucursal`: la pantalla solo le deja tocar a esa persona
     * el depósito que tiene asignado en su ficha, y el asistente tampoco.
     *
     * @test
     */
    public function con_el_permiso_acotado_solo_se_toca_la_propia_sucursal()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina permiso');

        $propia = $this->sucursal('zz-p52 Propia');
        $ajena = $this->sucursal('zz-p52 Ajena');

        $this->stock_en($articulo, $propia, 10);
        $this->stock_en($articulo, $ajena, 10);

        $empleado = $this->empleado(['article.edit_stock_only_sucursal'], $propia->id);

        $this->assertTrue(PropuestaStockIaHelper::puede_en_deposito($empleado, $propia->id));
        $this->assertFalse(PropuestaStockIaHelper::puede_en_deposito($empleado, $ajena->id));

        list($conversation, $assistant) = $this->conversacion('Cargale 5 a la ajena', $empleado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p52 Pastina permiso',
            'deposito' => 'zz-p52 Ajena',
            'cantidad' => 5,
            'modo'     => 'sumar',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertEqualsWithDelta(10, $this->pivot($articulo->id, $ajena->id), self::DELTA);
    }

    /**
     * Sin ninguno de los dos permisos de stock, la herramienta contesta que no puede.
     *
     * @test
     */
    public function sin_permiso_de_stock_no_se_propone_nada()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina sin permiso');

        $a = $this->sucursal('zz-p52 Sin permiso A');
        $b = $this->sucursal('zz-p52 Sin permiso B');

        $this->stock_en($articulo, $a, 10);

        $empleado = $this->empleado(['sale.index']);

        list($conversation, $assistant) = $this->conversacion('Mové 2', $empleado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_movimiento_de_stock', [
            'articulo' => 'zz-p52 Pastina sin permiso',
            'cantidad' => 2,
            'desde'    => 'zz-p52 Sin permiso A',
            'hacia'    => 'zz-p52 Sin permiso B',
        ]);

        $this->assertFalse(!empty($respuesta['ok']));
        $this->assertStringContainsString('permiso', (string) $respuesta['error']);
    }

    /**
     * Las dos herramientas están declaradas, se despachan y pasan por la puerta de auto-confirmación
     * (o sea: en "directo" se hacen solas, y en los otros dos modos dejan tarjeta).
     *
     * @test
     */
    public function las_dos_herramientas_estan_declaradas_y_despachadas()
    {
        $nombres = HerramientasDeCarga::nombres();

        foreach (['proponer_movimiento_de_stock', 'proponer_stock_en_deposito'] as $herramienta) {

            $this->assertContains($herramienta, $nombres, $herramienta . ' no está declarada.');

            $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

            $this->assertStringContainsString("case '" . $herramienta . "':", $contenido, $herramienta . ' no se despacha.');
        }

        foreach ([AiMessageAction::TIPO_MOVIMIENTO_STOCK, AiMessageAction::TIPO_STOCK_DEPOSITO] as $tipo) {

            $this->assertContains($tipo, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO, $tipo);
            $this->assertNotContains($tipo, HerramientasDeCarga::AUTO_CONFIRMABLES, $tipo . ' no va en "resuelto".');
        }
    }

    /**
     * Y el payload de (b) manda TODOS los depósitos que el artículo ya tiene, con el número de hoy:
     * uno que viaja con un número viejo se "corrige" a ese número viejo, y uno que no viaja no se
     * toca. La unidad de esto es el payload, porque es donde está la decisión.
     *
     * @test
     */
    public function el_payload_del_deposito_lleva_todos_los_que_el_articulo_ya_tiene()
    {
        $articulo = $this->articulo_de_prueba('zz-p52 Pastina payload b');

        $uno = $this->sucursal('zz-p52 Payload b uno');
        $dos = $this->sucursal('zz-p52 Payload b dos');
        $tres = $this->sucursal('zz-p52 Payload b tres');

        $this->stock_en($articulo, $uno, 4);
        $this->stock_en($articulo, $dos, 9);

        $depositos = PropuestaStockIaHelper::depositos_del_dueno($this->dueno->id);
        $pivots = PropuestaStockIaHelper::pivots_del_articulo($articulo->id);

        $payload = PropuestaStockIaHelper::payload_de_stock_en_deposito($articulo, $depositos, $pivots, $uno->id, 14);

        $por_id = [];

        foreach ($payload['addresses'] as $address) {
            $por_id[(int) $address['id']] = $address['pivot']['amount'];
        }

        $this->assertArrayHasKey($uno->id, $por_id);
        $this->assertEqualsWithDelta(14, $por_id[$uno->id], self::DELTA, 'El depósito que cambia tiene que llevar el FINAL.');

        $this->assertArrayHasKey($dos->id, $por_id);
        $this->assertEqualsWithDelta(9, $por_id[$dos->id], self::DELTA, 'El que no cambia tiene que llevar lo que tiene HOY.');

        $this->assertArrayNotHasKey(
            $tres->id,
            $por_id,
            'Un depósito que el artículo no tiene no viaja: mandarlo en 0 le abriría el pivot a todos de una.'
        );
    }
}
