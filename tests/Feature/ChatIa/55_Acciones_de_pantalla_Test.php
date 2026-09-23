<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionIaException;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Escritura;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionDePantallaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaAccionDePantallaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Http\Controllers\BudgetController;
use App\Models\AiMessageAction;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Budget;
use App\Models\Buyer;
use App\Models\Caja;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mcp (22/9/2026, constructor B) — las ACCIONES DE PANTALLA: la herramienta
 * genérica con la que el asistente hace "literalmente todo lo que se hace desde la interfaz",
 * llamando a la misma ruta y al mismo controller que llama la pantalla.
 *
 * Corre sobre el fixture de la ferretería (TestingFerreteriaSeeder). Lo que protege:
 *
 * - 🔴 El catálogo deriva del router y la lista negra excluye con motivo: el asistente mismo, las
 *   credenciales, los empleados, los archivos, lo que manda mensajes, las masivas por PUT, y el
 *   alta/edición/baja de lo que ya cubre el ABM genérico. Y trae lo que la misión vino a abrir:
 *   la caja, los presupuestos, la edición de una venta.
 * - que_acciones_de_pantalla_hay busca, pagina y dice cómo seguir; las cuatro definiciones van al
 *   FINAL, en orden, con su `case` en el mismo archivo.
 * - consultar_por_pantalla devuelve el JSON de la pantalla con los datos DEL DUEÑO y de nadie más.
 * - proponer_accion_de_pantalla deja tarjeta en "resuelto" y confirmar corre el controller de la
 *   pantalla (la caja queda abierta, con su apertura a nombre de la persona); en "directo" se
 *   ejecuta en el acto.
 * - 🔴 proponer_borrado_por_pantalla deja tarjeta en los TRES modos: su `case` no pasa por la
 *   puerta de la auto-ejecución (leyendo el archivo, como los tests 36 y 51) y su tipo está en
 *   NUNCA_AUTO_CONFIRMABLES.
 * - Una ruta de la lista negra, una extensión que el dueño no tiene, un método que no coincide y
 *   los datos que faltan cortan con `error` / `faltan`, sin tarjeta. Un empleado raso no puede.
 * - Al confirmar una tarjeta cuya ruta ya no está en el catálogo → 422 MENSAJE_NO_DISPONIBLE; el
 *   rechazo de la pantalla llega como 422 con SU mensaje y la tarjeta sigue propuesta.
 * - Los dos tipos van en dos etapas, y el ejecutor exige la persona autenticada.
 * - 🔴 Lo de AFIP (Catalogo::SIEMPRE_CONFIRMAN) deja tarjeta incluso en "directo": emitir un
 *   comprobante ante ARCA no se deshace. Sin tocar ARCA: alcanza con que la tarjeta quede
 *   propuesta y que `afip_tickets` y `sales` no cambien.
 * - 🔴 Tenencia de los ids de la ruta: un id de otro dueño (o inexistente) corta con `error` al
 *   proponer y al leer, sin tarjeta; y una tarjeta cuyo registro cambia de dueño antes del clic
 *   corta con 422 sin llamar al controller. Un {param} que no es un id (una fecha) no se mira.
 * - 🔴 Las CLASES de la segunda vuelta del verificador (23/9/2026): la tenencia se decide sobre la
 *   ruta que el router RESUELVE (el id metido en la ruta, otro nombre de {param}, una barra
 *   codificada), nada sensible de la respuesta llega al modelo ni a la tarjeta, editar una venta
 *   siempre confirma, los ids del cuerpo se miran todos (`model_id`, listas, anidados, booleanos) y
 *   una consulta no deja nada escrito.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 *
 * @group chat-ia
 */
class Acciones_de_pantalla_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    /**
     * El `agente_confianza` que el dueño tenía ANTES de que este archivo lo tocara: es un
     * interruptor global de la cuenta y se devuelve a mano (mismo criterio que el test 51).
     *
     * @var string|null
     */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();

        Escritura::olvidar();
        Catalogo::olvidar();
    }

    protected function tearDown(): void
    {
        if (!is_null($this->dueno)) {
            DB::table('users')->where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Conversación de la persona (el dueño si no se dice otra) con su assistant 'pendiente' y las
     * acciones habilitadas.
     *
     * @param  User|null  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Hacelo por la pantalla',
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
     * Llama a una herramienta por el mismo camino que el loop del servicio.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Como herramienta(), pero devuelve también el JSON CRUDO del tool_result: es lo que el modelo
     * lee, y es donde tiene que faltar lo que no puede ver.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
     * @return array{0: array, 1: string}
     */
    protected function herramienta_cruda($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return [json_decode($resultados[0]['content'], true), (string) $resultados[0]['content']];
    }

    /**
     * Confirma la tarjeta por el endpoint de la pantalla (el mensaje pasa a listo antes: la SPA
     * recién ahí la muestra).
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
     * Deja al dueño en un modo de confianza, guardado en la base.
     *
     * @param  string  $modo
     * @return void
     */
    protected function dueno_en($modo)
    {
        $this->dueno->agente_confianza = $modo;
        $this->dueno->save();
    }

    /**
     * Una caja del dueño, cerrada, creada por este test: es sobre lo que abren, cierran y borran
     * las acciones de pantalla de acá.
     *
     * @param  string  $nombre
     * @return Caja
     */
    protected function caja_de_prueba($nombre)
    {
        return Caja::create([
            'num'     => (int) Caja::where('user_id', $this->dueno->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ]);
    }

    /**
     * El bloque del `case` de una herramienta en HerramientasDeCarga, leído como texto plano (igual
     * que los tests 36 y 51: el switch no es introspectable de otra forma).
     *
     * @param  string  $herramienta
     * @return string
     */
    protected function bloque_del_case($herramienta)
    {
        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        $desde = strpos($contenido, "case '" . $herramienta . "':");

        $this->assertNotFalse($desde, $herramienta . ' no se despacha');

        $hasta = strpos($contenido, 'case ', $desde + 10);

        return substr($contenido, $desde, $hasta - $desde);
    }

    /**
     * Otro comercio, para los registros ajenos.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'     => 'Otro comercio P55',
            'email'    => 'otro-p55-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Un empleado raso del dueño (sin admin_access).
     *
     * @return User
     */
    protected function empleado_raso()
    {
        return User::create([
            'name'     => 'Empleado raso P55',
            'email'    => 'raso-p55-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // El catálogo
    // ---------------------------------------------------------------------

    /**
     * 🔴 La lista negra excluye con motivo, y lo que la misión vino a abrir está.
     *
     * @test
     */
    public function el_catalogo_deriva_del_router_excluye_con_motivo_y_trae_lo_que_la_mision_abrio()
    {
        // El asistente mismo, las credenciales, los empleados y el ABM genérico no están.
        foreach ([['GET', 'api/ai-conversations'], ['POST', 'api/mcp'], ['GET', 'api/payment-method'], ['GET', 'api/employee'], ['POST', 'api/provider']] as $par) {
            $this->assertNull(Catalogo::declaracion($par[0], $par[1]), $par[0] . ' ' . $par[1] . ' tenía que estar excluida');
            $this->assertNotNull(Catalogo::motivo_de_exclusion($par[0], $par[1]), $par[0] . ' ' . $par[1] . ' sin motivo');
        }

        $this->assertStringContainsString('proponer_alta', Catalogo::motivo_de_exclusion('POST', 'api/provider'));
        $this->assertStringContainsString('el asistente mismo', Catalogo::motivo_de_exclusion('GET', 'api/ai-conversations'));
        $this->assertStringContainsString('credenciales', Catalogo::motivo_de_exclusion('GET', 'api/employee'));

        // Ni una URI con pinta de archivo, ni las conversaciones del asistente, y todo bajo api/.
        $todas = Catalogo::todas();

        $this->assertGreaterThan(500, count($todas), 'El catálogo tiene que cubrir la mayor parte de la interfaz');

        foreach ($todas as $fila) {
            $this->assertStringStartsWith('api/', $fila['ruta']);
            $this->assertContains($fila['metodo'], Catalogo::METODOS);
            $this->assertDoesNotMatchRegularExpression('#pdf|excel|export|download|print|imagen|image|foto|file|csv|zip|qr#', $fila['ruta'], $fila['ruta']);
            $this->assertStringNotContainsString('ai-conversations', $fila['ruta']);
            foreach (['metodo', 'ruta', 'accion', 'modulo', 'parametros', 'claves', 'extension', 'siempre_confirma', 'motivo_confirmacion'] as $clave) {
                $this->assertArrayHasKey($clave, $fila);
            }
            $this->assertSame(is_null($fila['motivo_confirmacion']) ? false : true, $fila['siempre_confirma'], $fila['ruta']);
        }

        // Lo que sí entra: la caja y los presupuestos (menos su store, que es proponer_presupuesto).
        $acciones = array_column($todas, 'accion');

        $de_caja = array_filter($acciones, function ($accion) {
            return strpos($accion, 'CajaController@') === 0;
        });
        $de_budget = array_filter($acciones, function ($accion) {
            return strpos($accion, 'BudgetController@') === 0 && $accion !== 'BudgetController@store';
        });

        $this->assertNotEmpty($de_caja);
        $this->assertNotEmpty($de_budget);
        $this->assertContains('BudgetController@anular', $acciones);
        $this->assertContains('BudgetController@confirmar', $acciones);
        $this->assertNotContains('BudgetController@store', $acciones, 'El presupuesto nuevo va por proponer_presupuesto');
        $this->assertStringContainsString('proponer_presupuesto', Catalogo::motivo_de_exclusion('POST', 'api/budget'));

        // Editar una venta por la pantalla SÍ (era lo que quedaba afuera); anularla y crearla, no.
        $this->assertSame('SaleController@update', Catalogo::declaracion('PUT', 'api/sale/{sale}')['accion']);
        $this->assertNull(Catalogo::declaracion('DELETE', 'api/sale/{sale}'));
        $this->assertStringContainsString('proponer_baja', Catalogo::motivo_de_exclusion('DELETE', 'api/sale/{sale}'));
        $this->assertStringContainsString('proponer_venta', Catalogo::motivo_de_exclusion('POST', 'api/sale'));

        // Mandar mensajes a terceros sigue afuera, por URI, por controller y por método.
        $this->assertNull(Catalogo::declaracion('POST', 'api/whatsapp-chats/{id}/messages'));
        $this->assertStringContainsString('WhatsApp', Catalogo::motivo_de_exclusion('POST', 'api/whatsapp-chats/{id}/messages'));
        $this->assertNull(Catalogo::declaracion('POST', 'api/sale/{sale_id}/send-client-mail'));
        $this->assertNull(Catalogo::declaracion('POST', 'api/recordatorio-cobro/enviar'));

        // Las masivas por PUT no entran: con el dueño en "directo" correrían sin tarjeta.
        $this->assertNull(Catalogo::declaracion('PUT', 'api/delete/{model_name}'));
        $this->assertStringContainsString('proponer_borrado_por_pantalla', Catalogo::motivo_de_exclusion('PUT', 'api/delete/{model_name}'));
        $this->assertNull(Catalogo::declaracion('PUT', 'api/article/reset-stock/to-0'));

        // La ruta se acepta como venga: con o sin api/, con valores, con otro nombre de param, PATCH.
        foreach (['api/abrir-caja/{caja_id}', '/api/abrir-caja/{caja_id}', 'abrir-caja/{caja_id}', 'api/abrir-caja/12', 'api/abrir-caja/{id}', 'https://api-x.comerciocity.com/public/api/abrir-caja/12'] as $forma) {
            $declaracion = Catalogo::declaracion('PUT', $forma);
            $this->assertNotNull($declaracion, $forma);
            $this->assertSame('CajaController@abrir_caja', $declaracion['accion'], $forma);
            $this->assertSame('api/abrir-caja/{caja_id}', $declaracion['ruta'], $forma);
            $this->assertSame(['caja_id'], $declaracion['parametros'], $forma);
        }

        $this->assertSame('CajaController@abrir_caja', Catalogo::declaracion('PATCH', 'api/abrir-caja/{caja_id}')['accion']);
        $this->assertSame('CajaController@abrir_caja', Catalogo::resolver_ruta('PUT', 'api/abrir-caja/12')['accion']);
        $this->assertSame('Caja', Catalogo::declaracion('PUT', 'api/abrir-caja/{caja_id}')['modulo']);
        $this->assertNull(Catalogo::declaracion('POST', 'api/abrir-caja/{caja_id}'), 'Con otro método no existe');

        // Los {param}: los que faltan, los opcionales y la URI concreta (el `?` de {x?} no es query string).
        $this->assertSame(['from_date'], Catalogo::parametros_que_faltan('api/budget/from-date/{from_date}/{until_date?}', []));
        $this->assertSame([], Catalogo::parametros_que_faltan('api/budget/from-date/{from_date}/{until_date?}', ['from_date' => '2026-09-01']));
        $this->assertSame('api/budget/from-date/2026-09-01', Catalogo::uri_concreta('api/budget/from-date/{from_date}/{until_date?}', ['from_date' => '2026-09-01']));
        $this->assertSame('api/budget/from-date/2026-09-01/2026-09-30', Catalogo::uri_concreta('api/budget/from-date/{from_date}/{until_date?}', ['from_date' => '2026-09-01', 'until_date' => '2026-09-30']));
        $this->assertSame('api/caja/7', Catalogo::uri_concreta('caja/{caja}', ['caja' => 7]));
        $this->assertSame('api/x/0', Catalogo::uri_concreta('api/x/{id}', ['id' => 0]), 'El 0 es un valor');

        // El conteo cierra: lo que entra más lo excluido es lo considerado, y cada motivo cuenta.
        $conteo = Catalogo::conteo();

        $this->assertSame(count($todas), $conteo['total']);
        $this->assertSame($conteo['total'] + $conteo['excluidas'], $conteo['consideradas']);
        $this->assertSame(count($todas), array_sum($conteo['por_metodo']));
        $this->assertSame($conteo['excluidas'], array_sum($conteo['excluidas_por_motivo']));
        $this->assertGreaterThan(0, $conteo['excluidas_por_motivo']['ya lo hace el ABM genérico: usá proponer_alta']);
    }

    /**
     * @test
     */
    public function que_acciones_de_pantalla_hay_busca_pagina_y_dice_como_seguir()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['buscar' => 'caja']);

        $this->assertGreaterThan(0, $respuesta['encontradas']);
        $this->assertSame(1, $respuesta['pagina']);
        $this->assertLessThanOrEqual(40, count($respuesta['acciones']));

        $rutas = [];

        foreach ($respuesta['acciones'] as $accion) {
            foreach (['metodo', 'ruta', 'modulo', 'accion', 'parametros', 'claves'] as $clave) {
                $this->assertArrayHasKey($clave, $accion);
            }
            $rutas[] = $accion['metodo'] . ' ' . $accion['ruta'];
        }

        $this->assertContains('PUT api/abrir-caja/{caja_id}', $rutas);
        $this->assertContains('GET api/caja', $rutas);
        $this->assertStringContainsString('consultar_por_pantalla', $respuesta['como_sigo']);
        $this->assertStringContainsString('proponer_accion_de_pantalla', $respuesta['como_sigo']);

        // Dos palabras: las dos tienen que estar, y el guion de la ruta vale como espacio.
        $dos_palabras = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['buscar' => 'cerrar caja', 'metodo' => 'PUT']);

        $this->assertSame(1, $dos_palabras['encontradas']);
        $this->assertSame('PUT', $dos_palabras['acciones'][0]['metodo']);
        $this->assertSame('api/cerrar-caja/{caja_id}', $dos_palabras['acciones'][0]['ruta']);
        $this->assertSame('CajaController@cerrar_caja', $dos_palabras['acciones'][0]['accion']);

        // Por método y por página: la segunda página de los GET (los DELETE son menos de 40 desde
        // que las rutas de Route::resource sin método quedaron afuera).
        $lecturas = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['metodo' => 'GET', 'pagina' => 2]);

        $this->assertGreaterThan(40, $lecturas['encontradas']);
        $this->assertSame(2, $lecturas['pagina']);
        $this->assertGreaterThanOrEqual(2, $lecturas['paginas']);
        $this->assertNotEmpty($lecturas['acciones']);

        foreach ($lecturas['acciones'] as $accion) {
            $this->assertSame('GET', $accion['metodo']);
        }

        $primera = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['metodo' => 'GET', 'pagina' => 1]);

        $this->assertNotSame($primera['acciones'][0]['ruta'], $lecturas['acciones'][0]['ruta'], 'La página 2 no repite la 1');

        // Sin resultados: lo dice y explica dónde buscar.
        $nada = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['buscar' => 'zzzz-no-existe-p55']);

        $this->assertSame(0, $nada['encontradas']);
        $this->assertSame([], $nada['acciones']);
        $this->assertStringContainsString('herramienta', $nada['como_sigo']);

        // Sin tarjetas: es de lectura.
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Las dos puntas de cada herramienta nueva, y el orden al final del array (prefijo del caché).
     *
     * @test
     */
    public function las_cuatro_definiciones_van_al_final_en_orden_con_su_case_y_su_esquema()
    {
        $nombres = HerramientasDeCarga::nombres();

        $this->assertSame(
            ['que_acciones_de_pantalla_hay', 'consultar_por_pantalla', 'proponer_accion_de_pantalla', 'proponer_borrado_por_pantalla'],
            array_slice($nombres, -4)
        );

        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        $definiciones = [];

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $definiciones[$definicion['name']] = $definicion;
        }

        foreach (array_slice($nombres, -4) as $nombre) {
            $this->assertStringContainsString("case '" . $nombre . "':", $contenido, $nombre);
            $this->assertTrue(HerramientasDeCarga::maneja($nombre), $nombre);
            $this->assertContains($nombre, HerramientasDeCarga::nombres(true), $nombre . ' tiene que estar también en el canal de WhatsApp');
            $this->assertSame('object', $definiciones[$nombre]['input_schema']['type']);
        }

        // Las de lectura no son cargas; las dos proponer_ sí.
        $this->assertFalse(HerramientasDeCarga::es_de_carga('que_acciones_de_pantalla_hay'));
        $this->assertFalse(HerramientasDeCarga::es_de_carga('consultar_por_pantalla'));
        $this->assertTrue(HerramientasDeCarga::es_de_carga('proponer_accion_de_pantalla'));
        $this->assertTrue(HerramientasDeCarga::es_de_carga('proponer_borrado_por_pantalla'));

        // Los esquemas del contrato.
        $this->assertSame([], $definiciones['que_acciones_de_pantalla_hay']['input_schema']['required']);
        $this->assertSame(['GET', 'POST', 'PUT', 'DELETE'], $definiciones['que_acciones_de_pantalla_hay']['input_schema']['properties']['metodo']['enum']);
        $this->assertSame(['ruta'], $definiciones['consultar_por_pantalla']['input_schema']['required']);
        $this->assertSame(['metodo', 'ruta', 'descripcion'], $definiciones['proponer_accion_de_pantalla']['input_schema']['required']);
        $this->assertSame(['POST', 'PUT'], $definiciones['proponer_accion_de_pantalla']['input_schema']['properties']['metodo']['enum']);
        $this->assertSame(['ruta', 'descripcion'], $definiciones['proponer_borrado_por_pantalla']['input_schema']['required']);

        foreach (['parametros', 'cuerpo'] as $objeto) {
            $this->assertTrue($definiciones['proponer_accion_de_pantalla']['input_schema']['properties'][$objeto]['additionalProperties'], $objeto);
        }

        $this->assertTrue($definiciones['consultar_por_pantalla']['input_schema']['properties']['consulta']['additionalProperties']);
        $this->assertArrayHasKey('reemplaza_a', $definiciones['proponer_accion_de_pantalla']['input_schema']['properties']);
        $this->assertArrayHasKey('reemplaza_a', $definiciones['proponer_borrado_por_pantalla']['input_schema']['properties']);

        // Y dicen lo que tienen que decir.
        $this->assertStringContainsString('SIEMPRE deja tarjeta', $definiciones['proponer_borrado_por_pantalla']['description']);
        $this->assertStringContainsString('herramienta propia', $definiciones['proponer_accion_de_pantalla']['description']);
        $this->assertStringContainsString('que_acciones_de_pantalla_hay', $definiciones['consultar_por_pantalla']['description']);

        // Sin mensaje donde colgar la tarjeta, una propuesta va con is_error (regla común).
        list($conversation) = $this->conversacion();

        $sin_mensaje = HerramientasDeCarga::ejecutar('proponer_accion_de_pantalla', ['metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => 1], 'descripcion' => 'x'], $conversation, null);

        $this->assertTrue($sin_mensaje['is_error']);
    }

    // ---------------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function consultar_por_pantalla_devuelve_lo_que_la_pantalla_ve_solo_del_dueno()
    {
        $mio = Article::create([
            'name'        => 'Zeta P55 del dueño',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 100,
            'cost'        => 50,
            'stock'       => 1,
            'iva_id'      => 2,
        ]);

        $otro = User::create([
            'name'     => 'Otro comercio P55',
            'email'    => 'otro-p55-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        Article::create([
            'name'        => 'Zeta P55 ajeno',
            'user_id'     => $otro->id,
            'status'      => 'active',
            'final_price' => 100,
            'cost'        => 50,
            'stock'       => 1,
            'iva_id'      => 2,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        // El índice de artículos de la pantalla (`api/article` es un resource sin index).
        $respuesta = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
            'ruta'     => 'api/article/index/from-status',
            'consulta' => ['per_page' => 5],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('GET', $respuesta['metodo']);
        $this->assertSame('api/article/index/from-status', $respuesta['ruta']);
        $this->assertSame(200, $respuesta['status']);
        $this->assertFalse($respuesta['recortado']);

        // ArticleController::index() pagina y ordena por fecha de alta: el recién creado va primero.
        $nombres = array_column($respuesta['respuesta']['models']['data'], 'name');

        $this->assertContains('Zeta P55 del dueño', $nombres);
        $this->assertNotContains('Zeta P55 ajeno', $nombres, 'La pantalla filtra por dueño, y por acá también');
        $this->assertSame(5, $respuesta['respuesta']['models']['per_page'], 'La query string llegó al controller');

        // Con {param} y la query pegada a la ruta: la caja del dueño por su ruta con valor.
        $caja = $this->caja_de_prueba('Caja P55 lectura');

        $liquidaciones = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
            'ruta'       => 'api/caja/{id}/liquidaciones-pendientes?nada=1',
            'parametros' => ['id' => $caja->id],
        ]);

        $this->assertTrue($liquidaciones['ok'], json_encode($liquidaciones));
        $this->assertSame('api/caja/' . $caja->id . '/liquidaciones-pendientes', $liquidaciones['ruta']);
        $this->assertSame([], $liquidaciones['respuesta']['models']);

        // Es de lectura: nada de esto deja tarjeta ni escribe.
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNotNull(Article::find($mio->id));

        // Y sale del catálogo igual que las demás: una excluida y una que no existe cortan con error.
        $excluida = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', ['ruta' => 'api/payment-method']);

        $this->assertFalse($excluida['ok']);
        $this->assertStringContainsString(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $excluida['error']);
        $this->assertStringContainsString('credenciales', $excluida['error']);

        $sin_param = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', ['ruta' => 'api/caja/{id}/liquidaciones-pendientes']);

        $this->assertFalse($sin_param['ok']);
        $this->assertNull($sin_param['error']);
        $this->assertCount(1, $sin_param['faltan']);
        $this->assertStringContainsString('{id}', $sin_param['faltan'][0]);

        $sin_ruta = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', []);

        $this->assertFalse($sin_ruta['ok']);
        $this->assertCount(1, $sin_ruta['faltan']);
    }

    // ---------------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------------

    /**
     * 🔴 La tarjeta en "resuelto", y confirmar corre el MISMO controller que la pantalla: la caja
     * queda abierta, con su apertura a nombre de la persona.
     *
     * @test
     */
    public function proponer_accion_de_pantalla_deja_tarjeta_en_resuelto_y_confirmar_corre_el_controller()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::RESUELTO);

        $caja = $this->caja_de_prueba('Caja P55 resuelto');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/abrir-caja/{caja_id}',
            'parametros'  => ['caja_id' => $caja->id],
            'cuerpo'      => [],
            'descripcion' => 'Abrir la caja P55',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_ACCION_PANTALLA, $respuesta['tipo']);
        $this->assertArrayNotHasKey('estado', $respuesta, 'En "resuelto" una acción de pantalla NO se ejecuta sola');
        $this->assertStringContainsString('Abrir la caja P55', $respuesta['resumen']);
        $this->assertStringContainsString('PUT api/abrir-caja/' . $caja->id, $respuesta['resumen']);
        $this->assertSame('La tarjeta queda para que la persona la confirme. No digas que ya está cargado.', $respuesta['nota']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame('Abrir la caja P55', $tarjeta->presentacion['titulo']);
        $this->assertSame(['etiqueta' => 'Acción', 'valor' => 'PUT api/abrir-caja/' . $caja->id], $tarjeta->presentacion['renglones'][0]);
        $this->assertNull($tarjeta->presentacion['aviso']);
        $this->assertStringStartsWith('pantalla:', $tarjeta->clave);

        // `datos` es exactamente lo que el ejecutor va a llamar: la ruta con su {param}, no la concreta.
        $this->assertSame(
            ['metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => $caja->id], 'cuerpo' => []],
            $tarjeta->datos
        );

        $this->assertSame(0, (int) $caja->fresh()->abierta, 'Nada se ejecutó todavía');

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);
        $this->assertSame('confirmada', $confirmacion->json('model.estado'));

        $resultado = $confirmacion->json('model.resultado');

        $this->assertSame('Hecho: PUT api/abrir-caja/' . $caja->id, $resultado['texto']);
        $this->assertNull($resultado['ruta']);
        $this->assertSame('PUT', $resultado['metodo']);
        $this->assertSame('api/abrir-caja/' . $caja->id, $resultado['uri']);
        $this->assertSame(200, $resultado['status']);
        $this->assertFalse($resultado['recortado']);
        $this->assertSame($caja->id, (int) $resultado['respuesta']['model']['id'], 'La respuesta de la tarjeta es la del controller');

        // Y el efecto en la base es el de CajaController::abrir_caja(): CajaAperturaHelper.
        $caja->refresh();

        $this->assertSame(1, (int) $caja->abierta);
        $this->assertNotNull($caja->current_apertura_caja_id);

        $apertura = DB::table('apertura_cajas')->where('caja_id', $caja->id)->first();

        $this->assertNotNull($apertura);
        $this->assertSame((int) $caja->current_apertura_caja_id, (int) $apertura->id);
        $this->assertSame($this->dueno->id, (int) $apertura->apertura_employee_id, 'La apertura queda a nombre de la persona autenticada');
    }

    /**
     * 🔴 En "directo" una acción de pantalla que no borra se ejecuta en el acto, por la puerta única.
     *
     * @test
     */
    public function en_directo_una_accion_de_pantalla_se_ejecuta_en_el_acto()
    {
        // Las tres guardas, en positivo: está en la lista del modo, el filtro la deja pasar y su
        // `case` pasa por la puerta. Comentar la puerta en el `case` pone rojo este test.
        $this->assertContains(AiMessageAction::TIPO_ACCION_PANTALLA, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertContains(AiMessageAction::TIPO_ACCION_PANTALLA, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::DIRECTO));
        $this->assertNotContains(AiMessageAction::TIPO_ACCION_PANTALLA, HerramientasDeCarga::AUTO_CONFIRMABLES, 'En "resuelto" no se ejecuta sola');
        $this->assertNotContains(AiMessageAction::TIPO_ACCION_PANTALLA, HerramientasDeCarga::auto_confirmables_de(ConfianzaDelAgenteIaHelper::RESUELTO));
        $this->assertStringContainsString('quizas_auto_confirmar', $this->bloque_del_case('proponer_accion_de_pantalla'));

        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $caja = $this->caja_de_prueba('Caja P55 directo');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/abrir-caja/{caja_id}',
            'parametros'  => ['caja_id' => $caja->id],
            'descripcion' => 'Abrir la caja P55 directo',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        /* La respuesta es la de confirmar_del_agente: trae `estado` y `resultado`, no una tarjeta a confirmar. */
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $respuesta['estado']);
        $this->assertSame('Hecho: PUT api/abrir-caja/' . $caja->id, $respuesta['resultado']);
        $this->assertStringContainsString('Ahora sí quedó registrado', $respuesta['nota']);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertSame(1, (int) $caja->fresh()->abierta, 'La caja tenía que quedar abierta en el acto');
        $this->assertSame(1, DB::table('apertura_cajas')->where('caja_id', $caja->id)->count());
    }

    /**
     * 🔴 EL BORRADO POR PANTALLA DEJA TARJETA EN LOS TRES MODOS, con las tres guardas medidas.
     *
     * @test
     */
    public function proponer_borrado_por_pantalla_deja_tarjeta_en_los_tres_modos_y_confirmar_borra()
    {
        // Guarda 1 y 2: la constante y el filtro, en los tres modos.
        $this->assertContains(AiMessageAction::TIPO_BORRADO_PANTALLA, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
        $this->assertNotContains(AiMessageAction::TIPO_BORRADO_PANTALLA, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);

        foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {
            $this->assertNotContains(AiMessageAction::TIPO_BORRADO_PANTALLA, HerramientasDeCarga::auto_confirmables_de($modo), $modo);
        }

        // Guarda 3: su `case` ni siquiera pasa por la puerta (leyendo el archivo, como el test 36).
        $this->assertStringNotContainsString('quizas_auto_confirmar', $this->bloque_del_case('proponer_borrado_por_pantalla'));

        $ultima = null;

        foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {

            $this->dueno_en($modo);

            $caja = $this->caja_de_prueba('Caja P55 borrar ' . $modo);

            list($conversation, $assistant) = $this->conversacion();

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
                'ruta'        => 'api/caja/{caja}',
                'parametros'  => ['caja' => $caja->id],
                'descripcion' => 'Borrar la caja P55 ' . $modo,
            ]);

            $this->assertTrue(!empty($respuesta['ok']), $modo . ': ' . json_encode($respuesta));
            $this->assertSame(AiMessageAction::TIPO_BORRADO_PANTALLA, $respuesta['tipo'], $modo);
            $this->assertArrayNotHasKey('estado', $respuesta, $modo . ': una respuesta con "estado" es la de confirmar_del_agente, y el borrado no puede haber pasado por ahí.');
            $this->assertSame(PropuestaAccionDePantallaIaHelper::AVISO_BORRADO, $respuesta['aviso'], $modo);

            $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado(), $modo);
            $this->assertSame('Borrar la caja P55 ' . $modo, $tarjeta->presentacion['titulo']);
            $this->assertSame(PropuestaAccionDePantallaIaHelper::AVISO_BORRADO, $tarjeta->presentacion['aviso']);
            $this->assertSame('DELETE api/caja/' . $caja->id, $tarjeta->presentacion['renglones'][0]['valor']);
            $this->assertSame('DELETE', $tarjeta->datos['metodo']);
            $this->assertSame('api/caja/{caja}', $tarjeta->datos['ruta']);

            $this->assertNotNull(Caja::find($caja->id), $modo . ': se borró la caja sin confirmar');

            $ultima = [$conversation, $assistant, $respuesta['tarjeta_id'], $caja->id];
        }

        // Confirmar la última (la del modo directo) sí borra, por CajaController::destroy().
        $confirmacion = $this->confirmar($ultima[0], $ultima[1], $ultima[2]);

        $confirmacion->assertStatus(200);
        $this->assertSame('confirmada', $confirmacion->json('model.estado'));
        $this->assertSame('Hecho: DELETE api/caja/' . $ultima[3], $confirmacion->json('model.resultado.texto'));
        $this->assertNull(Caja::find($ultima[3]), 'Caja no usa SoftDeletes: destroy() la borra de verdad');
    }

    /**
     * @test
     */
    public function lista_negra_extension_ausente_metodo_que_no_coincide_y_datos_que_faltan_cortan_con_error()
    {
        list($conversation, $assistant) = $this->conversacion();

        // Una ruta de la lista negra, con su motivo.
        $negra = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/ai-conversations', 'descripcion' => 'Crear una conversación',
        ]);

        $this->assertFalse($negra['ok']);
        $this->assertStringContainsString(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $negra['error']);
        $this->assertStringContainsString('el asistente mismo', $negra['error']);

        // Una masiva por PUT, con el motivo que manda al borrado con tarjeta.
        $masiva = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/delete/{model_name}', 'parametros' => ['model_name' => 'article'], 'descripcion' => 'Borrar artículos',
        ]);

        $this->assertFalse($masiva['ok']);
        $this->assertStringContainsString('proponer_borrado_por_pantalla', $masiva['error']);

        // Una ruta con extensión que el dueño no tiene.
        $this->assertFalse(PermisosIaHelper::tiene_extencion($this->dueno, 'sugerencias_compras'), 'El fixture no trae la extensión sugerencias_compras');
        $this->assertSame('sugerencias_compras', Catalogo::declaracion('POST', 'api/purchase-suggestion/{id}/create-provider-order')['extension']);

        $sin_extension = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/purchase-suggestion/{id}/create-provider-order', 'parametros' => ['id' => 1], 'descripcion' => 'Crear el pedido',
        ]);

        $this->assertFalse($sin_extension['ok']);
        $this->assertStringContainsString('no tiene activado el módulo', $sin_extension['error']);

        // Un método que no coincide con la ruta (abrir-caja es PUT).
        $otro_metodo = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => 1], 'descripcion' => 'Abrir la caja',
        ]);

        $this->assertFalse($otro_metodo['ok']);
        $this->assertStringContainsString(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $otro_metodo['error']);
        $this->assertStringContainsString('otro método', $otro_metodo['error']);

        // Un DELETE por la herramienta de acción, y una ruta PUT por la de borrado.
        $delete_por_accion = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'DELETE', 'ruta' => 'api/caja/{caja}', 'parametros' => ['caja' => 1], 'descripcion' => 'Borrar la caja',
        ]);

        $this->assertSame(PropuestaAccionDePantallaIaHelper::MENSAJE_METODO_DE_ACCION, $delete_por_accion['error']);

        $put_por_borrado = $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
            'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => 1], 'descripcion' => 'Abrir la caja',
        ]);

        $this->assertFalse($put_por_borrado['ok']);
        $this->assertStringContainsString(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $put_por_borrado['error']);

        // Falta el {param}: `faltan`, no `error`.
        $sin_param = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'descripcion' => 'Abrir la caja',
        ]);

        $this->assertFalse($sin_param['ok']);
        $this->assertNull($sin_param['error']);
        $this->assertCount(1, $sin_param['faltan']);
        $this->assertStringContainsString('{caja_id}', $sin_param['faltan'][0]);

        // Falta la descripción: es el título de la tarjeta, no se inventa.
        $sin_descripcion = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => 1],
        ]);

        $this->assertFalse($sin_descripcion['ok']);
        $this->assertCount(1, $sin_descripcion['faltan']);
        $this->assertStringContainsString('descripción', $sin_descripcion['faltan'][0]);

        // Nada de esto dejó tarjeta.
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Un empleado raso no puede, ni proponer ni leer.
        $raso = $this->empleado_raso();

        list($conversation_raso, $assistant_raso) = $this->conversacion($raso);

        $propuesta_raso = $this->herramienta($conversation_raso, $assistant_raso, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => 1], 'descripcion' => 'Abrir la caja',
        ]);

        $this->assertSame(PermisosIaHelper::mensaje_sin_permiso('acciones de pantalla'), $propuesta_raso['error']);

        $lectura_raso = $this->herramienta($conversation_raso, $assistant_raso, 'consultar_por_pantalla', ['ruta' => 'api/caja']);

        $this->assertSame(PermisosIaHelper::mensaje_sin_permiso('acciones de pantalla'), $lectura_raso['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation_raso->id)->count());
    }

    /**
     * 🔴 El catálogo se vuelve a consultar al confirmar: una tarjeta cuya ruta ya no está (o nunca
     * estuvo, si se forjó) corta con 422 y no llama a nada. Y una tarjeta cuyo tipo no coincide con
     * su método, tampoco.
     *
     * @test
     */
    public function al_confirmar_una_tarjeta_cuya_ruta_ya_no_esta_en_el_catalogo_corta_con_422()
    {
        list($conversation, $assistant) = $this->conversacion();

        $forjar = function ($tipo, array $datos) use ($conversation, $assistant) {
            return AiMessageAction::create([
                'ai_conversation_id' => $conversation->id,
                'ai_message_id'      => $assistant->id,
                'user_id'            => $this->dueno->id,
                'auth_user_id'       => $this->dueno->id,
                'tipo'               => $tipo,
                'clave'              => 'pantalla:forjada-' . uniqid(),
                'estado'             => AiMessageAction::ESTADO_PROPUESTA,
                'datos'              => $datos,
                'presentacion'       => ['titulo' => 'Forjada P55', 'renglones' => [], 'aviso' => null],
            ]);
        };

        // Una ruta que no existe en el router.
        $inexistente = $forjar(AiMessageAction::TIPO_ACCION_PANTALLA, [
            'metodo' => 'PUT', 'ruta' => 'api/ruta-que-no-existe-p55/{id}', 'parametros' => ['id' => 1], 'cuerpo' => [],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $inexistente->id);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $confirmacion->json('model.error_mensaje'));
        $this->assertSame('propuesta', $confirmacion->json('model.estado'));

        // Una ruta que existe pero está excluida: no se crea ninguna conversación.
        $conversaciones_antes = AiConversation::where('user_id', $this->dueno->id)->count();

        $excluida = $forjar(AiMessageAction::TIPO_ACCION_PANTALLA, [
            'metodo' => 'POST', 'ruta' => 'api/ai-conversations', 'parametros' => [], 'cuerpo' => [],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $excluida->id);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $confirmacion->json('model.error_mensaje'));
        $this->assertSame($conversaciones_antes, AiConversation::where('user_id', $this->dueno->id)->count(), 'No se llamó al controller');

        // Una tarjeta de acción (que en "directo" se ejecuta sola) con un DELETE adentro: no corre.
        $caja = $this->caja_de_prueba('Caja P55 forjada');

        $disfrazada = $forjar(AiMessageAction::TIPO_ACCION_PANTALLA, [
            'metodo' => 'DELETE', 'ruta' => 'api/caja/{caja}', 'parametros' => ['caja' => $caja->id], 'cuerpo' => [],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $disfrazada->id);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_DISPONIBLE, $confirmacion->json('model.error_mensaje'));
        $this->assertNotNull(Caja::find($caja->id), 'La caja sigue: el tipo y el método no coincidían');
    }

    /**
     * El rechazo de la pantalla llega como 422 con SU mensaje, y la tarjeta vuelve a 'propuesta'
     * con el motivo: es el camino de dos etapas. Se usa un presupuesto REAL del dueño, sin
     * confirmar, y se le pide anularlo: `BudgetController::anular()` lo rechaza con su 422 (un id
     * inexistente ya no llega hasta el controller: lo frena antes la tenencia).
     *
     * @test
     */
    public function el_rechazo_de_la_pantalla_llega_como_422_con_su_mensaje_y_la_tarjeta_sigue_propuesta()
    {
        $cliente = Client::where('user_id', $this->dueno->id)->first();

        $this->assertNotNull($cliente, 'El fixture trae clientes');

        $presupuesto = Budget::create([
            'client_id'        => $cliente->id,
            'user_id'          => $this->dueno->id,
            'budget_status_id' => BudgetController::ESTADO_SIN_CONFIRMAR,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        // Proponer no mira el estado del presupuesto: eso lo dice la pantalla al confirmar.
        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'POST',
            'ruta'        => 'api/budget/{id}/anular',
            'parametros'  => ['id' => $presupuesto->id],
            'descripcion' => 'Anular el presupuesto',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(422);
        $this->assertSame('El presupuesto no esta confirmado.', $confirmacion->json('model.error_mensaje'), 'El mensaje es el de BudgetController::anular(), tal cual');
        $this->assertSame('propuesta', $confirmacion->json('model.estado'));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado(), 'La reserva de las dos etapas se soltó');
        $this->assertSame(BudgetController::ESTADO_SIN_CONFIRMAR, (int) $presupuesto->fresh()->budget_status_id);
    }

    /**
     * Los dos tipos van en dos etapas (llaman a cualquier controller de la pantalla), y el ejecutor
     * exige la persona autenticada y los {param} antes de llamar a nada.
     *
     * @test
     */
    public function los_dos_tipos_van_en_dos_etapas_y_el_ejecutor_exige_la_persona_autenticada()
    {
        foreach ([AiMessageAction::TIPO_ACCION_PANTALLA, AiMessageAction::TIPO_BORRADO_PANTALLA] as $tipo) {
            $this->assertContains($tipo, EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS, $tipo);
        }

        list($conversation) = $this->conversacion();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        // Un {param} que falta corta antes de mirar la sesión.
        try {
            EjecutorAccionDePantallaIaHelper::llamar($contexto, 'PUT', 'api/abrir-caja/{caja_id}', [], []);
            $this->fail('Tenía que cortar por el parámetro que falta');
        } catch (AccionIaException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('Falta el parámetro caja_id de la ruta.', $e->getMessage());
        }

        // Sin persona autenticada no se llama a ningún controller.
        $caja = $this->caja_de_prueba('Caja P55 sin sesión');

        Auth::logout();

        try {
            EjecutorAccionDePantallaIaHelper::llamar($contexto, 'PUT', 'api/abrir-caja/{caja_id}', ['caja_id' => $caja->id], []);
            $this->fail('Tenía que cortar sin persona autenticada');
        } catch (AccionIaException $e) {
            $this->assertSame(500, $e->status);
            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_SIN_AUTENTICAR, $e->getMessage());
        }

        $this->assertSame(0, (int) $caja->fresh()->abierta);
        $this->assertSame(0, DB::table('apertura_cajas')->where('caja_id', $caja->id)->count());
    }

    // ---------------------------------------------------------------------
    // Lo que siempre se confirma, y la tenencia de los ids de la ruta
    // ---------------------------------------------------------------------

    /**
     * 🔴 FACTURAR SIEMPRE CONFIRMA, EN LOS TRES MODOS (decisión de la misión, 23/9/2026): emitir un
     * comprobante ante ARCA no se deshace. La puerta es `requiere_confirmacion` en la respuesta de
     * la propuesta, que quizas_auto_confirmar() respeta antes de mirar el modo.
     *
     * No se toca ARCA: la tarjeta queda propuesta y nada se ejecuta. El `sale_id` es una venta
     * real del dueño porque la tenencia del cuerpo exige que exista y sea suya; lo que se mide es
     * que la tarjeta quede propuesta y que `afip_tickets` y `sales` no cambien.
     *
     * @test
     */
    public function una_accion_que_emite_comprobantes_ante_arca_siempre_deja_tarjeta_incluso_en_directo()
    {
        $venta = Sale::where('user_id', $this->dueno->id)->whereNull('deleted_at')->orderBy('id')->first();

        $this->assertNotNull($venta, 'El fixture trae ventas');

        // El catálogo la marca, y a una acción común no.
        $declaracion = Catalogo::declaracion('POST', 'api/afip-ticket');

        $this->assertNotNull($declaracion, 'Facturar tiene que estar en el catálogo');
        $this->assertSame('SaleController@makeAfipTicket', $declaracion['accion']);
        $this->assertTrue($declaracion['siempre_confirma']);
        $this->assertStringContainsString('ARCA', $declaracion['motivo_confirmacion']);
        $this->assertFalse(Catalogo::declaracion('PUT', 'api/abrir-caja/{caja_id}')['siempre_confirma']);
        $this->assertNull(Catalogo::declaracion('PUT', 'api/abrir-caja/{caja_id}')['motivo_confirmacion']);

        list($conversation, $assistant) = $this->conversacion();

        // que_acciones_de_pantalla_hay lo muestra y dice cómo leerlo.
        $lista = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['buscar' => 'afip-ticket', 'metodo' => 'POST']);

        $facturar = null;

        foreach ($lista['acciones'] as $accion) {
            if ($accion['ruta'] === 'api/afip-ticket') {
                $facturar = $accion;
            }
        }

        $this->assertNotNull($facturar);
        $this->assertTrue($facturar['siempre_confirma']);
        $this->assertStringContainsString('siempre_confirma', $lista['como_sigo']);

        // Con el dueño en "directo", la tarjeta queda propuesta y nada se ejecuta.
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $tickets_antes = DB::table('afip_tickets')->count();
        $ventas_antes = DB::table('sales')->count();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'POST',
            'ruta'        => 'api/afip-ticket',
            'cuerpo'      => ['sale_id' => $venta->id],
            'descripcion' => 'Facturar la venta',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Una respuesta con "estado" es la de confirmar_del_agente: facturar pasó por la auto-ejecución.');
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertStringContainsString('ARCA', $respuesta['motivo_confirmacion']);
        $this->assertStringContainsString('ARCA', $respuesta['aviso']);
        $this->assertSame('La tarjeta queda para que la persona la confirme. No digas que ya está cargado.', $respuesta['nota']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::TIPO_ACCION_PANTALLA, $tarjeta->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertStringContainsString('ARCA', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('"directo"', $tarjeta->presentacion['aviso']);
        $this->assertSame((int) $venta->id, (int) $tarjeta->datos['cuerpo']['sale_id']);

        $this->assertSame($tickets_antes, DB::table('afip_tickets')->count(), 'No se emitió ningún comprobante');
        $this->assertSame($ventas_antes, DB::table('sales')->count());

        // Y una acción común en "directo" sigue ejecutándose sola: la puerta es por acción, no global.
        $caja = $this->caja_de_prueba('Caja P55 afip directo');

        $comun = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/abrir-caja/{caja_id}',
            'parametros'  => ['caja_id' => $caja->id],
            'descripcion' => 'Abrir la caja',
        ]);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $comun['estado']);
        $this->assertSame(1, (int) $caja->fresh()->abierta);
    }

    /**
     * 🔴 TENENCIA DE LOS IDS DE LA RUTA, al proponer y al leer: un id ajeno o inexistente corta
     * con `error` sin tarjeta y sin tocar nada; un {param} que no es un id no se mira.
     *
     * @test
     */
    public function un_id_de_otro_dueno_o_inexistente_corta_con_error_al_proponer_y_al_leer_sin_dejar_tarjeta()
    {
        $otro = $this->otro_dueno();

        $ajena = Caja::create(['num' => 1, 'name' => 'Caja ajena P55', 'user_id' => $otro->id]);

        list($conversation, $assistant) = $this->conversacion();

        foreach ([$ajena->id, 999999999] as $id) {

            $accion = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
                'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => $id], 'descripcion' => 'Abrir la caja',
            ]);

            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $accion['error'], 'id ' . $id);

            $borrado = $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
                'ruta' => 'api/caja/{caja}', 'parametros' => ['caja' => $id], 'descripcion' => 'Borrar la caja',
            ]);

            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $borrado['error'], 'id ' . $id);

            $lectura = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
                'ruta' => 'api/caja/{id}/liquidaciones-pendientes', 'parametros' => ['id' => $id],
            ]);

            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $lectura['error'], 'id ' . $id);
        }

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Nada de esto dejó tarjeta');
        $this->assertNotNull(Caja::find($ajena->id));
        $this->assertSame(0, (int) $ajena->fresh()->abierta);

        // Un {param} que no es un id (una fecha) no se mira: la lectura pasa. El rango es de 2020 a
        // propósito: sin presupuestos adentro la respuesta es chica y no se recorta.
        $presupuestos = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
            'ruta'       => 'api/budget/from-date/{from_date}/{until_date?}',
            'parametros' => ['from_date' => '2020-01-01', 'until_date' => '2020-01-31'],
        ]);

        $this->assertTrue(!empty($presupuestos['ok']), json_encode($presupuestos));
        $this->assertSame('api/budget/from-date/2020-01-01/2020-01-31', $presupuestos['ruta']);
        $this->assertFalse($presupuestos['recortado']);
        $this->assertSame([], $presupuestos['respuesta']['models']);

        // Y el id propio pasa.
        $mia = $this->caja_de_prueba('Caja P55 propia');

        $propia = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
            'ruta' => 'api/caja/{id}/liquidaciones-pendientes', 'parametros' => ['id' => $mia->id],
        ]);

        $this->assertTrue(!empty($propia['ok']), json_encode($propia));
    }

    // ---------------------------------------------------------------------
    // Lo que encontró el verificador del catálogo (23/9/2026): bloqueantes
    // ---------------------------------------------------------------------

    /**
     * 🔴 B1/B2: las rutas genéricas de la SPA reciben el modelo en `{model_name}` y la lista negra
     * no las veía: `POST api/search/user` devolvía todos los usuarios de la base con la contraseña
     * visible. Ninguna ruta con `{model_name}` entra; `PUT api/update/{model_name}` además dice que
     * ES la actualización masiva.
     *
     * @test
     */
    public function las_rutas_genericas_por_model_name_no_estan_en_el_catalogo()
    {
        $this->assertNull(Catalogo::declaracion('POST', 'api/search/{model_name}/{_filters?}/{paginate?}'));
        $this->assertNull(Catalogo::declaracion('POST', 'api/search/user'));
        $this->assertNull(Catalogo::declaracion('POST', 'api/global-search/{model_name}'));
        $this->assertNull(Catalogo::declaracion('DELETE', 'api/current-acount/{model_name}/{id}'));
        $this->assertStringContainsString('endpoint genérico', Catalogo::motivo_de_exclusion('POST', 'api/search/{model_name}/{_filters?}/{paginate?}'));

        foreach (Catalogo::todas() as $fila) {
            $this->assertStringNotContainsString('{model_name}', $fila['ruta'], $fila['metodo'] . ' ' . $fila['ruta']);
        }

        $this->assertNull(Catalogo::declaracion('PUT', 'api/update/{model_name}'));
        $this->assertStringContainsString('proponer_actualizacion_masiva', Catalogo::motivo_de_exclusion('PUT', 'api/update/{model_name}'));
        // Y el motivo de la masiva por PUT que ya estaba sigue siendo el suyo, no el genérico.
        $this->assertStringContainsString('proponer_borrado_por_pantalla', Catalogo::motivo_de_exclusion('PUT', 'api/delete/{model_name}'));
    }

    /**
     * 🔴 B3: dos rutas emiten comprobantes ante ARCA sin decir "afip" en la URI.
     *
     * @test
     */
    public function consolidar_facturacion_y_devoluciones_siempre_confirman()
    {
        foreach (['POST api/sales/consolidar-facturacion', 'POST api/devoluciones'] as $accion) {
            list($metodo, $ruta) = explode(' ', $accion);
            $declaracion = Catalogo::declaracion($metodo, $ruta);
            $this->assertNotNull($declaracion, $accion);
            $this->assertTrue($declaracion['siempre_confirma'], $accion);
            $this->assertStringContainsString('ARCA', $declaracion['motivo_confirmacion'], $accion);
        }

        // La lectura de devoluciones no es la emisión: no se marca.
        $indice = Catalogo::declaracion('GET', 'api/devoluciones');

        if (!is_null($indice)) {
            $this->assertFalse($indice['siempre_confirma']);
        }
    }

    /**
     * 🔴 B4: credenciales que habían quedado adentro. Buyer no tiene $hidden (devuelve la
     * contraseña hasta en un GET), WhatsappBotConfig lleva la clave de Kapso y el secreto del
     * webhook, y la configuración de la tienda lleva la contraseña del mail y el secreto de Google.
     *
     * @test
     */
    public function las_credenciales_del_bot_la_configuracion_online_y_los_compradores_no_estan()
    {
        foreach ([['GET', 'api/buyer'], ['GET', 'api/buyer/{buyer}'], ['GET', 'api/whatsapp-bot/config'], ['PUT', 'api/whatsapp-bot/config'], ['GET', 'api/online-configuration'], ['PUT', 'api/online-configuration/{id}']] as $par) {
            $this->assertNull(Catalogo::declaracion($par[0], $par[1]), $par[0] . ' ' . $par[1]);
            $this->assertStringContainsString('credenciales', (string) Catalogo::motivo_de_exclusion($par[0], $par[1]), $par[0] . ' ' . $par[1]);
        }
    }

    /**
     * 🔴 B5: la tenencia no se saltea con un id que no es solo dígitos. `'<ajeno>x'` matchea el
     * router y MySQL lo castea al id ajeno en el find(): con tabla derivable, lo que no es un
     * entero se rechaza, al proponer y al confirmar.
     *
     * @test
     */
    public function un_id_que_no_es_solo_digitos_no_saltea_la_tenencia()
    {
        $otro = $this->otro_dueno();

        $ajena = Caja::create(['num' => 1, 'name' => 'Caja ajena B5', 'user_id' => $otro->id]);

        list($conversation, $assistant) = $this->conversacion();

        foreach ([$ajena->id . 'x', '0', '-' . $ajena->id, $ajena->id . '.0', ' ' . $ajena->id] as $valor) {

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
                'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => $valor], 'descripcion' => 'Abrir la caja',
            ]);

            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $respuesta['error'], 'valor ' . json_encode($valor));
        }

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Y una tarjeta forjada con ese valor corta al confirmar, sin abrir la caja ajena.
        $forjada = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_ACCION_PANTALLA,
            'clave'              => 'pantalla:forjada-b5',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => $ajena->id . 'x'], 'cuerpo' => []],
            'presentacion'       => ['titulo' => 'Forjada B5', 'renglones' => [], 'aviso' => null],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $forjada->id);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $confirmacion->json('model.error_mensaje'));
        $this->assertSame(0, (int) $ajena->fresh()->abierta, 'La caja ajena tenía que seguir cerrada');
        $this->assertSame(0, DB::table('apertura_cajas')->where('caja_id', $ajena->id)->count());
    }

    // ---------------------------------------------------------------------
    // Lo que encontró el verificador del catálogo (23/9/2026): importantes
    // ---------------------------------------------------------------------

    /**
     * I1: cinco GET que escriben (publicar en la tienda, destacar, recalcular saldos, marcar
     * leído, quemar cuota de Google) no pueden correr "en el acto" como una consulta.
     *
     * @test
     */
    public function los_get_con_efectos_no_estan()
    {
        foreach (['api/article/set-online/{id}', 'api/article/set-featured/{id}', 'api/check-saldos/{credit_account_id}', 'api/message/set-read/{buyer_id}'] as $ruta) {
            $this->assertNull(Catalogo::declaracion('GET', $ruta), $ruta);
            $this->assertStringContainsString('GET con efectos', (string) Catalogo::motivo_de_exclusion('GET', $ruta), $ruta);
        }

        foreach (Catalogo::todas() as $fila) {
            $this->assertDoesNotMatchRegularExpression('#article/set-online/|article/set-featured/|check-saldos/|message/set-read/|google/custom-search/aumentar-contador#', $fila['ruta'], $fila['metodo'] . ' ' . $fila['ruta']);
        }
    }

    /**
     * 🔴 I2: los ids del cuerpo también son del dueño. `PUT api/cheque/rechazar` con el cheque_id
     * de otro lo dejaba rechazado; acá se mide con change-provider, que hace `Article::find($request->id)`.
     *
     * @test
     */
    public function un_id_ajeno_en_el_cuerpo_corta_al_proponer_y_al_confirmar()
    {
        $otro = $this->otro_dueno();

        $ajeno = Article::create(['name' => 'Artículo ajeno I2', 'user_id' => $otro->id, 'status' => 'active', 'final_price' => 100, 'cost' => 50, 'stock' => 1, 'iva_id' => 2]);
        $propio = Article::create(['name' => 'Artículo propio I2', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 100, 'cost' => 50, 'stock' => 1, 'iva_id' => 2]);

        $proveedor = Provider::where('user_id', $this->dueno->id)->first();
        $proveedor_ajeno = Provider::create(['name' => 'Proveedor ajeno I2', 'user_id' => $otro->id, 'num' => 1]);

        $this->assertNotNull($proveedor, 'El fixture trae proveedores');

        list($conversation, $assistant) = $this->conversacion();

        $proponer = function (array $cuerpo) use ($conversation, $assistant) {
            return $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
                'metodo' => 'PUT', 'ruta' => 'api/article/change-provider', 'cuerpo' => $cuerpo, 'descripcion' => 'Cambiar el proveedor',
            ]);
        };

        // El `id` del cuerpo va al recurso de la ruta (articles); `provider_id` a providers.
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $proponer(['id' => $ajeno->id, 'provider_id' => $proveedor->id])['error'], 'artículo ajeno');
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $proponer(['id' => $propio->id, 'provider_id' => $proveedor_ajeno->id])['error'], 'proveedor ajeno');
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $proponer(['id' => 'abc', 'provider_id' => $proveedor->id])['error'], 'id que no es un entero');
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $proponer(['id' => 999999999, 'provider_id' => $proveedor->id])['error'], 'id inexistente');

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Lo propio pasa (y 0 / null / '' en una relación son "sin valor", no un id que rechazar).
        $ok = $proponer(['id' => $propio->id, 'provider_id' => $proveedor->id, 'category_id' => 0, 'brand_id' => null, 'sub_category_id' => '']);

        $this->assertTrue(!empty($ok['ok']), json_encode($ok));
        // change-provider está en SIEMPRE_CONFIRMAN: queda como tarjeta, no se ejecuta.
        $this->assertTrue($ok['requiere_confirmacion']);

        // Una tarjeta forjada con el id ajeno en el cuerpo corta al confirmar, sin tocar el artículo.
        $forjada = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_ACCION_PANTALLA,
            'clave'              => 'pantalla:forjada-i2',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['metodo' => 'PUT', 'ruta' => 'api/article/change-provider', 'parametros' => [], 'cuerpo' => ['id' => $ajeno->id, 'provider_id' => $proveedor->id]],
            'presentacion'       => ['titulo' => 'Forjada I2', 'renglones' => [], 'aviso' => null],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $forjada->id);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $confirmacion->json('model.error_mensaje'));
        $this->assertNull($ajeno->fresh()->provider_id, 'El artículo ajeno sigue sin proveedor');
    }

    /**
     * 🔴 I3: una fila cuya tabla no tiene `user_id` se verifica por sus padres (dos saltos), el
     * slug que no es tabla cae al modelo del controller, y un catálogo global sin padre no se
     * escribe desde el asistente.
     *
     * @test
     */
    public function una_fila_sin_user_id_se_verifica_por_sus_padres_y_un_catalogo_global_no_se_escribe()
    {
        $otro = $this->otro_dueno();

        $ajeno = Article::create(['name' => 'Artículo ajeno I3', 'user_id' => $otro->id, 'status' => 'active', 'final_price' => 100, 'cost' => 50, 'stock' => 1, 'iva_id' => 2]);
        $propio = Article::create(['name' => 'Artículo propio I3', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 100, 'cost' => 50, 'stock' => 1, 'iva_id' => 2]);

        $variante_ajena = ArticleVariant::create(['article_id' => $ajeno->id, 'price' => 77]);
        $variante_propia = ArticleVariant::create(['article_id' => $propio->id, 'price' => 77]);

        list($conversation, $assistant) = $this->conversacion();

        // article_variants no tiene user_id: se sube a articles.
        $ajena = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/article-variant/{id}', 'parametros' => ['id' => $variante_ajena->id], 'cuerpo' => ['price' => 1], 'descripcion' => 'Cambiar el precio de la variante',
        ]);

        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $ajena['error']);

        $propia = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/article-variant/{id}', 'parametros' => ['id' => $variante_propia->id], 'cuerpo' => ['price' => 1], 'descripcion' => 'Cambiar el precio de la variante',
        ]);

        $this->assertTrue(!empty($propia['ok']), json_encode($propia));

        // Forjada con la variante ajena: 422 y el precio no cambió.
        $forjada = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_ACCION_PANTALLA,
            'clave'              => 'pantalla:forjada-i3',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['metodo' => 'PUT', 'ruta' => 'api/article-variant/{id}', 'parametros' => ['id' => $variante_ajena->id], 'cuerpo' => ['price' => 1]],
            'presentacion'       => ['titulo' => 'Forjada I3', 'renglones' => [], 'aviso' => null],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $forjada->id);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $confirmacion->json('model.error_mensaje'));
        $this->assertEquals(77, (float) $variante_ajena->fresh()->price);

        // Dos saltos: apertura_cajas → caja_id → cajas. La apertura de una caja ajena no se reabre...
        $caja_ajena = Caja::create(['num' => 1, 'name' => 'Caja ajena I3', 'user_id' => $otro->id]);
        $apertura_ajena = AperturaCaja::create(['caja_id' => $caja_ajena->id, 'saldo_apertura' => 0]);

        $reabrir = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/apertura-caja/reabrir/{id}', 'parametros' => ['id' => $apertura_ajena->id], 'descripcion' => 'Reabrir la caja',
        ]);

        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $reabrir['error']);
        $this->assertSame(0, (int) $caja_ajena->fresh()->abierta);

        // ...y la propia se lee por el mismo camino.
        $caja = $this->caja_de_prueba('Caja I3 propia');
        $apertura = AperturaCaja::create(['caja_id' => $caja->id, 'saldo_apertura' => 0]);

        $lectura = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', [
            'ruta' => 'api/apertura-caja/show/{id}', 'parametros' => ['id' => $apertura->id],
        ]);

        $this->assertTrue(!empty($lectura['ok']), json_encode($lectura));

        // Un catálogo global (current_acount_payment_methods: sin user_id, sin padre) no se borra
        // desde acá: nadie puede decir de qué negocio es.
        $efectivo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->first();

        $this->assertNotNull($efectivo);

        $global = $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
            'ruta' => 'api/current-acount-payment-method/{current_acount_payment_method}', 'parametros' => ['current_acount_payment_method' => $efectivo->id], 'descripcion' => 'Borrar el método de pago',
        ]);

        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_NO_VERIFICABLE, $global['error']);
        $this->assertNotNull(CurrentAcountPaymentMethod::find($efectivo->id));

        // El slug que no es tabla cae al modelo del controller: cc-payment-method-discount →
        // current_acount_payment_method_discounts, que SÍ tiene user_id.
        $descuento_ajeno = CurrentAcountPaymentMethodDiscount::create(['current_acount_payment_method_id' => $efectivo->id, 'discount_percentage' => 10, 'user_id' => $otro->id]);

        $descuento = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/cc-payment-method-discount/{cc_payment_method_discount}', 'parametros' => ['cc_payment_method_discount' => $descuento_ajeno->id], 'cuerpo' => ['discount_percentage' => 5], 'descripcion' => 'Cambiar el descuento',
        ]);

        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $descuento['error']);

        // Una sola tarjeta en toda la prueba: la de la variante propia (más la forjada a mano).
        $this->assertSame(2, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * I4 a I7: Zipnova cuesta plata, las masivas por proveedor siempre confirman, los mensajes que
     * el nombre no delata, la doble puerta y el candado de sesión.
     *
     * @test
     */
    public function zipnova_las_masivas_por_proveedor_los_mensajes_ocultos_la_doble_puerta_y_el_candado_de_sesion()
    {
        // I4: Zipnova, afuera por controller.
        foreach (['POST api/envio/generar/{order_id}', 'POST api/envio/{id}/cancelar', 'POST api/envio/{id}/sincronizar'] as $accion) {
            list($metodo, $ruta) = explode(' ', $accion);
            $this->assertNull(Catalogo::declaracion($metodo, $ruta), $accion);
            $this->assertStringContainsString('Zipnova', (string) Catalogo::motivo_de_exclusion($metodo, $ruta), $accion);
        }

        // I4: las que en "directo" correrían sin tarjeta y son masivas, irreversibles o pesadas.
        foreach (['PUT api/provider/{id}/propagar-descuentos', 'PUT api/provider/{id}/sincronizar-descuentos', 'PUT api/article/change-provider', 'POST api/article-description-ai/batch-generate', 'POST api/inventory-performance/generate'] as $accion) {
            list($metodo, $ruta) = explode(' ', $accion);
            $declaracion = Catalogo::declaracion($metodo, $ruta);
            $this->assertNotNull($declaracion, $accion);
            $this->assertTrue($declaracion['siempre_confirma'], $accion);
        }

        $preview = Catalogo::declaracion('GET', 'api/provider/{id}/propagar-descuentos/preview');

        if (!is_null($preview)) {
            $this->assertFalse($preview['siempre_confirma'], 'El preview es una lectura');
        }

        // I5: mensajes que el nombre no delata.
        foreach (['POST api/error', 'POST api/message'] as $accion) {
            list($metodo, $ruta) = explode(' ', $accion);
            $this->assertNull(Catalogo::declaracion($metodo, $ruta), $accion);
            $this->assertStringContainsString('manda', (string) Catalogo::motivo_de_exclusion($metodo, $ruta), $accion);
        }

        list($conversation) = $this->conversacion();

        $prompt = $this->service->build_system_prompt($conversation, $this->dueno, true);

        // Un tramo que vive en una sola línea del heredoc: el prompt tiene saltos de línea adentro de la frase.
        $this->assertStringContainsString('cliente como lo haría la pantalla', $prompt);
        $this->assertStringContainsString('editar una venta le manda el comprobante', $prompt);

        // I6: doble puerta.
        $this->assertNull(Catalogo::declaracion('POST', 'api/current-acount/pago'));
        $this->assertStringContainsString('proponer_pago', (string) Catalogo::motivo_de_exclusion('POST', 'api/current-acount/pago'));
        $this->assertNull(Catalogo::declaracion('POST', 'api/article/new-article'));
        $this->assertStringContainsString('proponer_alta', (string) Catalogo::motivo_de_exclusion('POST', 'api/article/new-article'));

        // I7: el candado de sesión.
        $this->assertNull(Catalogo::declaracion('POST', 'api/user/last-activity'));
        $this->assertStringContainsString('sesión', (string) Catalogo::motivo_de_exclusion('POST', 'api/user/last-activity'));
    }

    // ---------------------------------------------------------------------
    // Lo que encontró el verificador del catálogo (23/9/2026): menores
    // ---------------------------------------------------------------------

    /**
     * m1, m2 y m6: toda acción del catálogo apunta a un método que existe (las rutas de
     * Route::resource sin método daban 500), la etiqueta del envío y el logo del ticket son
     * archivos, y la sincronización offline de la SPA no es una consulta.
     *
     * @test
     */
    public function toda_accion_tiene_metodo_y_los_archivos_y_la_sincronizacion_offline_quedan_afuera()
    {
        foreach (Catalogo::todas() as $fila) {
            list($clase, $metodo) = explode('@', $fila['accion'], 2);
            $this->assertTrue(method_exists('App\Http\Controllers\\' . $clase, $metodo), $fila['metodo'] . ' ' . $fila['ruta'] . ' apunta a ' . $fila['accion'] . ', que no existe');
        }

        // Route::resource('caja') declara create y edit, y CajaController no los tiene.
        $this->assertNull(Catalogo::declaracion('GET', 'api/caja/create'));
        $this->assertStringContainsString('no tiene método', (string) Catalogo::motivo_de_exclusion('GET', 'api/caja/create'));
        $this->assertNull(Catalogo::declaracion('GET', 'api/caja/{caja}/edit'));

        // La etiqueta del envío es un PDF y el logo del ticket un raster.
        $this->assertNull(Catalogo::declaracion('GET', 'api/envio/{id}/etiqueta'));
        $this->assertStringContainsString('archivo', (string) Catalogo::motivo_de_exclusion('GET', 'api/envio/{id}/etiqueta'));

        // La sincronización offline trae el catálogo entero.
        foreach (['api/articles-por-defecto', 'api/article/deleted-models/{last_updated}', 'api/articles-ultimos-actualizados'] as $ruta) {
            $this->assertNull(Catalogo::declaracion('GET', $ruta), $ruta);
            $this->assertStringContainsString('sincronización offline', (string) Catalogo::motivo_de_exclusion('GET', $ruta), $ruta);
        }

        $conteo = Catalogo::conteo();

        $this->assertGreaterThan(200, $conteo['excluidas_por_motivo']['la ruta no tiene método en el controller: ejecutarla daría 500']);
    }

    /**
     * 🔴 La tenencia se vuelve a mirar al confirmar: si el registro cambió de dueño entre la tarjeta
     * y el clic (o la tarjeta se forjó con un id ajeno), 422 sin llamar al controller.
     *
     * @test
     */
    public function una_tarjeta_cuyo_registro_cambia_de_dueno_antes_de_confirmar_corta_con_422()
    {
        $otro = $this->otro_dueno();

        $caja = $this->caja_de_prueba('Caja P55 que cambia de dueño');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/abrir-caja/{caja_id}',
            'parametros'  => ['caja_id' => $caja->id],
            'descripcion' => 'Abrir la caja',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());

        // La caja pasa a otro negocio entre la tarjeta y el clic.
        DB::table('cajas')->where('id', $caja->id)->update(['user_id' => $otro->id]);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(422);
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $confirmacion->json('model.error_mensaje'));
        $this->assertSame('propuesta', $confirmacion->json('model.estado'));

        $this->assertSame(0, (int) DB::table('cajas')->where('id', $caja->id)->value('abierta'), 'No se llamó a CajaController::abrir_caja()');
        $this->assertSame(0, DB::table('apertura_cajas')->where('caja_id', $caja->id)->count());
    }

    // ---------------------------------------------------------------------
    // La segunda vuelta del verificador (23/9/2026): las CLASES, no los ejemplos
    // ---------------------------------------------------------------------

    /**
     * 🔴 B-1: la tenencia se decide sobre la ruta que el router RESUELVE y los valores que LIGA, no
     * sobre los nombres que escribió el modelo. Las cuatro formas con las que el verificador leyó la
     * venta de otro dueño cortan con `error` de ajeno y sin un solo dato de la venta; y del lado de
     * la escritura, una propuesta con el id metido en la ruta queda ejecutable (la tarjeta guarda la
     * ruta resuelta y los valores ligados) y se ejecuta.
     *
     * @test
     */
    public function la_tenencia_se_decide_sobre_la_ruta_resuelta_y_no_sobre_lo_que_escribio_el_modelo()
    {
        $otro = $this->otro_dueno();

        $venta_ajena = Sale::create(['user_id' => $otro->id, 'observations' => 'P55-venta-ajena-secreta']);
        $venta_propia = Sale::create(['user_id' => $this->dueno->id, 'observations' => 'P55 venta propia']);

        $this->assertNotNull(Catalogo::declaracion('GET', 'api/sale/{sale}'), 'Leer una venta por la pantalla está en el catálogo');

        list($conversation, $assistant) = $this->conversacion();

        $variantes = [
            'el id ya metido en la ruta'                        => ['ruta' => 'api/sale/' . $venta_ajena->id],
            'otro nombre de parámetro'                          => ['ruta' => 'api/sale/{id}', 'parametros' => ['id' => $venta_ajena->id]],
            'la ruta adentro de un {param}'                     => ['ruta' => 'api/{x}', 'parametros' => ['x' => 'sale/' . $venta_ajena->id]],
            'el id ajeno en la ruta y uno propio en parametros' => ['ruta' => 'api/sale/' . $venta_ajena->id, 'parametros' => ['sale' => $venta_propia->id]],
        ];

        foreach ($variantes as $nombre => $input) {

            list($respuesta, $crudo) = $this->herramienta_cruda($conversation, $assistant, 'consultar_por_pantalla', $input);

            $this->assertFalse($respuesta['ok'], $nombre . ': ' . $crudo);
            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $respuesta['error'], $nombre . ': ' . $crudo);
            $this->assertStringNotContainsString('P55-venta-ajena-secreta', $crudo, $nombre . ': la venta ajena viajó en la respuesta');
        }

        // La guarda no cierra de más: la venta propia, por esas mismas formas, se lee.
        foreach ([['ruta' => 'api/sale/' . $venta_propia->id], ['ruta' => 'api/sale/{id}', 'parametros' => ['id' => $venta_propia->id]]] as $input) {

            list($propia, $crudo_propia) = $this->herramienta_cruda($conversation, $assistant, 'consultar_por_pantalla', $input);

            $this->assertTrue(!empty($propia['ok']), $crudo_propia);
            $this->assertSame('api/sale/' . $venta_propia->id, $propia['ruta'], 'Lo que se llamó es la ruta resuelta con su valor');
            $this->assertStringContainsString('P55 venta propia', $crudo_propia);
        }

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Del lado de la escritura: una caja ajena con el id metido en la ruta no deja tarjeta...
        $caja_ajena = Caja::create(['num' => 1, 'name' => 'Caja ajena P55 ruta', 'user_id' => $otro->id]);

        $ajena = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/' . $caja_ajena->id, 'descripcion' => 'Abrir la caja',
        ]);

        $this->assertFalse(!empty($ajena['ok']), 'La caja ajena con el id en la ruta dejó tarjeta: ' . json_encode($ajena));
        $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $ajena['error']);
        $this->assertSame(0, (int) $caja_ajena->fresh()->abierta);

        // ...con otro nombre de {param}, la tarjeta queda con el nombre de la ruta resuelta...
        $this->dueno_en(ConfianzaDelAgenteIaHelper::RESUELTO);

        $caja_otro_nombre = $this->caja_de_prueba('Caja P55 otro nombre');

        $otro_nombre = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{id}', 'parametros' => ['id' => $caja_otro_nombre->id], 'descripcion' => 'Abrir la caja con otro nombre de parámetro',
        ]);

        $this->assertTrue(!empty($otro_nombre['ok']), json_encode($otro_nombre));
        $this->assertSame(['caja_id' => $caja_otro_nombre->id], AiMessageAction::find($otro_nombre['tarjeta_id'])->datos['parametros']);

        // ...y la propia con el id metido en la ruta queda EJECUTABLE: antes la tarjeta guardaba
        // `{caja_id}` sin valor, su renglón lo mostraba literal y al confirmar faltaba el parámetro.
        $caja = $this->caja_de_prueba('Caja P55 id en la ruta');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'PUT', 'ruta' => 'api/abrir-caja/' . $caja->id, 'descripcion' => 'Abrir la caja con el id en la ruta',
        ]);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));
        $this->assertStringContainsString('PUT api/abrir-caja/' . $caja->id, $propuesta['resumen']);

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertSame(['metodo' => 'PUT', 'ruta' => 'api/abrir-caja/{caja_id}', 'parametros' => ['caja_id' => $caja->id], 'cuerpo' => []], $tarjeta->datos);
        $this->assertSame('PUT api/abrir-caja/' . $caja->id, $tarjeta->presentacion['renglones'][0]['valor'], 'El renglón muestra la URI concreta, no {caja_id}');

        $confirmacion = $this->confirmar($conversation, $assistant, $propuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);
        $this->assertSame('Hecho: PUT api/abrir-caja/' . $caja->id, $confirmacion->json('model.resultado.texto'));
        $this->assertSame(1, (int) $caja->fresh()->abierta);
        $this->assertSame(0, (int) $caja_otro_nombre->fresh()->abierta, 'La otra tarjeta sigue sin confirmar');
    }

    /**
     * 🔴 B-2: nada sensible de la respuesta llega al modelo ni queda en la tarjeta, venga por la
     * relación que venga. `GET api/client/{client}` trae el comprador (Buyer no tiene $hidden) y
     * `GET api/sale/{sale}` además el empleado (User::$hidden no incluye visible_password): la
     * pantalla los recibe, el asistente no. Los modelos no se tocan.
     *
     * @test
     */
    public function nada_sensible_de_la_respuesta_llega_al_modelo_ni_queda_en_la_tarjeta()
    {
        $empleado = User::create([
            'name'             => 'Empleado P55 sensible',
            'email'            => 'empleado-p55-' . uniqid() . '@test.local',
            'password'         => Hash::make('x'),
            'visible_password' => 'p55-empleado-secreto',
            'owner_id'         => $this->dueno->id,
        ]);

        $cliente = Client::create(['name' => 'Cliente P55 con comprador', 'user_id' => $this->dueno->id]);

        $comprador = Buyer::create([
            'name'                    => 'Comprador P55 sensible',
            'email'                   => 'comprador-p55-' . uniqid() . '@test.local',
            'user_id'                 => $this->dueno->id,
            'comercio_city_client_id' => $cliente->id,
            'password'                => Hash::make('y'),
            'visible_password'        => 'p55-comprador-secreto',
            'verification_code'       => 'p55-codigo-de-verificacion',
        ]);

        $venta = Sale::create(['user_id' => $this->dueno->id, 'employee_id' => $empleado->id, 'buyer_id' => $comprador->id, 'client_id' => $cliente->id]);

        $secretos = ['p55-empleado-secreto', 'p55-comprador-secreto', 'p55-codigo-de-verificacion'];

        // Sin esto el test no probaría nada: los modelos SÍ entregan esos datos (y no se tocan).
        $crudo_del_modelo = json_encode(Sale::withAll()->find($venta->id));

        foreach ($secretos as $secreto) {
            $this->assertStringContainsString($secreto, $crudo_del_modelo, 'El modelo ya no entrega "' . $secreto . '": revisar este test');
        }

        list($conversation, $assistant) = $this->conversacion();

        $lecturas = [
            'el cliente' => ['ruta' => 'api/client/{client}', 'parametros' => ['client' => $cliente->id], 'se_ve' => 'Comprador P55 sensible'],
            'la venta'   => ['ruta' => 'api/sale/{sale}', 'parametros' => ['sale' => $venta->id], 'se_ve' => 'Empleado P55 sensible'],
        ];

        foreach ($lecturas as $nombre => $lectura) {

            list($respuesta, $crudo) = $this->herramienta_cruda($conversation, $assistant, 'consultar_por_pantalla', ['ruta' => $lectura['ruta'], 'parametros' => $lectura['parametros']]);

            $this->assertTrue(!empty($respuesta['ok']), $nombre . ': ' . $crudo);
            $this->assertFalse($respuesta['recortado'], $nombre . ': la respuesta vino recortada y el test no vería todo');

            // La relación viaja (la limpieza no se lleva todo)...
            $this->assertStringContainsString($lectura['se_ve'], $crudo, $nombre);

            // ...sin las claves sensibles ni sus valores.
            foreach ($secretos as $secreto) {
                $this->assertStringNotContainsString($secreto, $crudo, $nombre . ': "' . $secreto . '" llegó al modelo');
            }

            foreach (['"visible_password"', '"verification_code"', '"password"'] as $clave) {
                $this->assertStringNotContainsString($clave, $crudo, $nombre . ': la clave ' . $clave . ' llegó al modelo');
            }
        }

        // Y lo mismo en el `resultado` de una tarjeta ejecutada: delivery-info responde la venta entera.
        $this->dueno_en(ConfianzaDelAgenteIaHelper::RESUELTO);

        $this->assertNotNull(Catalogo::declaracion('PUT', 'api/sale/{sale_id}/delivery-info'));

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/sale/{sale_id}/delivery-info',
            'parametros'  => ['sale_id' => $venta->id],
            'cuerpo'      => ['first_name' => 'Destinatario P55', 'locality' => 'Rosario'],
            'descripcion' => 'Cargar los datos de envío de la venta',
        ]);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $propuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);
        $this->assertSame('confirmada', $confirmacion->json('model.estado'));
        $this->assertSame('Empleado P55 sensible', $confirmacion->json('model.resultado.respuesta.model.employee.name'), 'La relación viaja en el resultado, sin lo sensible');

        $guardado = (string) DB::table('ai_message_actions')->where('id', $propuesta['tarjeta_id'])->value('resultado');

        foreach ($secretos as $secreto) {
            $this->assertStringNotContainsString($secreto, $confirmacion->getContent(), '"' . $secreto . '" en la respuesta del clic');
            $this->assertStringNotContainsString($secreto, $guardado, '"' . $secreto . '" quedó guardado en la tarjeta');
        }

        foreach (['visible_password', 'verification_code'] as $clave) {
            $this->assertStringNotContainsString($clave, $guardado, 'la clave ' . $clave . ' quedó guardada en la tarjeta');
        }
    }

    /**
     * 🔴 B-3: editar una venta ya cargada SIEMPRE confirma, también en "directo". `PUT api/sale/{sale}`
     * con `save_nota_credito` emite una nota de crédito ante ARCA y con `send_mail` le manda el
     * comprobante al cliente: la tarjeta queda propuesta, con su aviso, y la venta no cambia.
     *
     * @test
     */
    public function editar_una_venta_siempre_confirma_incluso_en_directo()
    {
        $declaracion = Catalogo::declaracion('PUT', 'api/sale/{sale}');

        $this->assertSame('SaleController@update', $declaracion['accion']);
        $this->assertTrue($declaracion['siempre_confirma']);
        $this->assertStringContainsString('nota de crédito ante ARCA', $declaracion['motivo_confirmacion']);
        $this->assertStringContainsString('avisarle al cliente', $declaracion['motivo_confirmacion']);

        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $venta = Sale::create(['user_id' => $this->dueno->id, 'observations' => 'P55 venta a editar']);

        $antes = (array) DB::table('sales')->where('id', $venta->id)->first();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo'      => 'PUT',
            'ruta'        => 'api/sale/{sale}',
            'parametros'  => ['sale' => $venta->id],
            'cuerpo'      => ['observations' => 'P55 editada', 'save_nota_credito' => true, 'send_mail' => true],
            'descripcion' => 'Editar la venta',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Una respuesta con "estado" es la de confirmar_del_agente: la edición se ejecutó sola en "directo".');
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertStringContainsString('nota de crédito', $respuesta['motivo_confirmacion']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertStringContainsString('nota de crédito', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('"directo"', $tarjeta->presentacion['aviso']);

        $this->assertEquals($antes, (array) DB::table('sales')->where('id', $venta->id)->first(), 'La venta no cambió');

        // Y el prompt de "directo" la nombra entre lo que siempre deja tarjeta.
        $prompt = $this->service->build_system_prompt($conversation, $this->dueno->fresh(), true);

        $this->assertStringContainsString('editar una venta ya cargada', $prompt);
    }

    /**
     * 🔴 I-4: los ids del cuerpo, todos. Las cuatro que el verificador ejecutó en "directo" sobre
     * registros de otro comercio —un `model_id` en dos rutas de "hijo de", una lista `article_ids`
     * y un `cheque_id: true` (Cheque::find(true) es el cheque 1)— cortan con `error`, no dejan
     * tarjeta y no tocan nada. Lo propio, por el mismo camino, pasa.
     *
     * @test
     */
    public function los_ids_del_cuerpo_se_miran_todos_model_id_listas_y_booleanos()
    {
        $otro = $this->otro_dueno();

        $articulo_ajeno = Article::create(['name' => 'Artículo ajeno P55 I4', 'user_id' => $otro->id, 'status' => 'active', 'final_price' => 150, 'cost' => 100, 'stock' => 1, 'iva_id' => 2]);
        $proveedor_ajeno = Provider::create(['name' => 'Proveedor ajeno P55 I4', 'user_id' => $otro->id, 'num' => 1]);

        // El cheque 1, de otro comercio: es el que resuelve `Cheque::find(true)`.
        if (is_null(Cheque::find(1))) {
            Cheque::forceCreate(['id' => 1, 'user_id' => $otro->id, 'amount' => 100, 'numero' => 'P55-UNO']);
        } else {
            DB::table('cheques')->where('id', 1)->update(['user_id' => $otro->id, 'estado_manual' => null]);
        }

        $precio_antes = DB::table('articles')->where('id', $articulo_ajeno->id)->value('final_price');

        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion();

        $casos = [
            'model_id de un artículo ajeno'  => ['POST', 'api/article-discount', ['model_id' => $articulo_ajeno->id, 'percentage' => 10, 'show_in_online' => 0]],
            'model_id de un proveedor ajeno' => ['POST', 'api/provider-price-list', ['model_id' => $proveedor_ajeno->id, 'name' => 'Lista P55', 'percentage' => 10]],
            'article_ids con uno ajeno'      => ['POST', 'api/sale-tax', ['name' => 'Impuesto P55', 'percentage' => 3, 'apply_to_all' => 0, 'activo' => 1, 'article_ids' => [$articulo_ajeno->id]]],
            'cheque_id booleano'             => ['PUT', 'api/cheque/rechazar', ['cheque_id' => true, 'rechazado_observaciones' => 1]],
        ];

        foreach ($casos as $nombre => $caso) {

            $this->assertNotNull(Catalogo::declaracion($caso[0], $caso[1]), $caso[1] . ' está en el catálogo');

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
                'metodo' => $caso[0], 'ruta' => $caso[1], 'cuerpo' => $caso[2], 'descripcion' => $nombre,
            ]);

            $this->assertFalse($respuesta['ok'], $nombre . ': ' . json_encode($respuesta));
            $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $respuesta['error'], $nombre);
            $this->assertArrayNotHasKey('estado', $respuesta, $nombre . ': se ejecutó');
        }

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Nada dejó tarjeta ni se ejecutó');

        $this->assertEquals($precio_antes, DB::table('articles')->where('id', $articulo_ajeno->id)->value('final_price'), 'El precio del artículo ajeno no se recalculó');
        $this->assertSame(0, DB::table('article_discounts')->where('article_id', $articulo_ajeno->id)->count());
        $this->assertSame(0, DB::table('provider_price_lists')->where('provider_id', $proveedor_ajeno->id)->count());
        $this->assertSame(0, DB::table('article_sale_tax')->where('article_id', $articulo_ajeno->id)->count());
        $this->assertNull(DB::table('cheques')->where('id', 1)->value('estado_manual'), 'El cheque 1, ajeno, no se rechazó');

        // Lo propio, por el mismo camino, pasa (en "resuelto", para que no se ejecute).
        $this->dueno_en(ConfianzaDelAgenteIaHelper::RESUELTO);

        $articulo_propio = Article::create(['name' => 'Artículo propio P55 I4', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 150, 'cost' => 100, 'stock' => 1, 'iva_id' => 2]);

        $propio = $this->herramienta($conversation, $assistant, 'proponer_accion_de_pantalla', [
            'metodo' => 'POST', 'ruta' => 'api/article-discount', 'cuerpo' => ['model_id' => $articulo_propio->id, 'percentage' => 5, 'show_in_online' => 0], 'descripcion' => 'Descuento propio',
        ]);

        $this->assertTrue(!empty($propio['ok']), json_encode($propio));
    }

    /**
     * 🔴 I-4, la clase entera contra el recorrido del cuerpo: los ids anidados, las listas, los
     * empleados, los decimales, los booleanos y la basura cortan; el `model_id` va a la tabla que le
     * da el CÓDIGO del controller y no al `model_name` que mande el modelo; uno que no se puede
     * atribuir es no verificable al escribir y pasa al leer; y lo propio —con sus "sin valor", sus
     * catálogos globales y las claves que no dicen su tabla— pasa.
     *
     * @test
     */
    public function el_recorrido_del_cuerpo_cubre_la_clase_y_deja_pasar_lo_propio()
    {
        $otro = $this->otro_dueno();

        $articulo_ajeno = Article::create(['name' => 'Artículo ajeno P55 recorrido', 'user_id' => $otro->id, 'status' => 'active', 'final_price' => 1, 'cost' => 1, 'stock' => 1, 'iva_id' => 2]);
        $articulo_propio = Article::create(['name' => 'Artículo propio P55 recorrido', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 1, 'cost' => 1, 'stock' => 1, 'iva_id' => 2]);
        $cliente_ajeno = Client::create(['name' => 'Cliente ajeno P55 recorrido', 'user_id' => $otro->id]);
        $cliente_propio = Client::create(['name' => 'Cliente propio P55 recorrido', 'user_id' => $this->dueno->id]);
        $empleado_ajeno = User::create(['name' => 'Empleado ajeno P55', 'email' => 'empleado-ajeno-p55-' . uniqid() . '@test.local', 'password' => Hash::make('x'), 'owner_id' => $otro->id]);
        $empleado_propio = $this->empleado_raso();

        list($conversation) = $this->conversacion();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $editar_venta = Catalogo::declaracion('PUT', 'api/sale/{sale}');

        $rechazos = [
            'el id de un renglón (lista bajo articles)' => ['articles' => [['id' => $articulo_ajeno->id, 'amount' => 1]]],
            'un x_id adentro de un objeto'              => ['sale' => ['client_id' => $cliente_ajeno->id]],
            'un x_id con una lista adentro'             => ['client_id' => [$cliente_propio->id, $cliente_ajeno->id]],
            'un x_ids'                                  => ['article_ids' => [$articulo_propio->id, $articulo_ajeno->id]],
            'el empleado de otro comercio'              => ['employee_id' => $empleado_ajeno->id],
            'un decimal'                                => ['client_id' => (float) $cliente_propio->id],
            'un booleano'                               => ['client_id' => true],
            'un texto con basura'                       => ['client_id' => $cliente_propio->id . 'x'],
        ];

        foreach ($rechazos as $nombre => $cuerpo) {
            try {
                EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, $editar_venta, [], $cuerpo);
                $this->fail($nombre . ': tenía que cortar');
            } catch (AccionIaException $e) {
                $this->assertSame(EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO, $e->getMessage(), $nombre);
            }
        }

        /*
         * El model_id va a la tabla que le da el CÓDIGO: en article-discount el controller lo
         * escribe como `article_id`, así que un `model_name: client` no lo salva aunque el mismo
         * número sea un cliente PROPIO. Se arma a propósito un id que es las dos cosas.
         */
        $mismo_id = max((int) DB::table('articles')->max('id'), (int) DB::table('clients')->max('id')) + 1000;

        Article::forceCreate(['id' => $mismo_id, 'name' => 'Artículo ajeno P55 mismo id', 'user_id' => $otro->id, 'status' => 'active', 'final_price' => 1, 'cost' => 1, 'stock' => 1, 'iva_id' => 2]);
        Client::forceCreate(['id' => $mismo_id, 'name' => 'Cliente propio P55 mismo id', 'user_id' => $this->dueno->id]);

        $casos_model_id = [
            'un model_name que no es lo que el controller escribe' => ['POST', 'api/article-discount', ['model_name' => 'client', 'model_id' => $mismo_id], EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO],
            'el model_name de un cliente ajeno'                    => ['POST', 'api/credit-account/limite-credito', ['model_name' => 'client', 'model_id' => $cliente_ajeno->id, 'moneda_id' => 1], EjecutorAccionDePantallaIaHelper::MENSAJE_AJENO],
            'un model_id sin tabla en una escritura'               => ['POST', 'api/current-acount/nota-credito', ['model_id' => $cliente_propio->id], EjecutorAccionDePantallaIaHelper::MENSAJE_NO_VERIFICABLE],
        ];

        foreach ($casos_model_id as $nombre => $caso) {

            $declaracion = Catalogo::declaracion($caso[0], $caso[1]);

            $this->assertNotNull($declaracion, $caso[1] . ' está en el catálogo');

            try {
                EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, $declaracion, [], $caso[2]);
                $this->fail($nombre . ': tenía que cortar');
            } catch (AccionIaException $e) {
                $this->assertSame($caso[3], $e->getMessage(), $nombre);
            }
        }

        // Lo no verificable solo corta al ESCRIBIR: el mismo model_id en una lectura pasa.
        EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, array_merge(Catalogo::declaracion('POST', 'api/current-acount/nota-credito'), ['metodo' => 'GET']), [], ['model_id' => $cliente_propio->id]);

        // Lo propio pasa: renglones, empleados (y el dueño mismo), "sin valor", catálogos globales
        // y claves que no dicen su tabla (`returned_items`).
        $global_id = DB::table('current_acount_payment_methods')->orderBy('id')->value('id');

        EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, $editar_venta, [], [
            'articles'                       => [['id' => $articulo_propio->id, 'amount' => 2]],
            'client_id'                      => $cliente_propio->id,
            'employee_id'                    => $empleado_propio->id,
            'moneda_id'                      => 1,
            'category_id'                    => 0,
            'brand_id'                       => null,
            'provider_id'                    => '',
            'sub_category_id'                => '0',
            'current_acount_payment_methods' => [['id' => $global_id, 'amount' => 10]],
            'returned_items'                 => [['id' => 999999999, 'returned_amount' => 1]],
            'sale'                           => ['client_id' => $cliente_propio->id],
        ]);

        EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, $editar_venta, [], ['employee_id' => $this->dueno->id]);
        EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, Catalogo::declaracion('POST', 'api/article-discount'), [], ['model_id' => $articulo_propio->id, 'percentage' => 5]);
        EjecutorAccionDePantallaIaHelper::verificar_tenencia($contexto, Catalogo::declaracion('POST', 'api/credit-account/limite-credito'), [], ['model_name' => 'client', 'model_id' => $cliente_propio->id, 'moneda_id' => 1]);

        $this->assertNotNull($global_id, 'Hay métodos de pago de cuenta corriente (el catálogo global del caso propio)');
    }

    /**
     * 🔴 I-5: una consulta no puede escribir, y eso se cierra por CLASE y no por lista: el
     * controller de un GET corre adentro de una transacción que se deshace siempre.
     * `company-performance` sin fechas borra el informe del día y lo regenera
     * (CompanyPerformanceController::check_tiempo_ultima_creada(); no hace TRUNCATE ni DDL, que se
     * saltearían el rollback): se envejece el que hay para que lo haga seguro, y la base queda
     * igual. Los dos GET del "?" del precio, que recalculan y guardan a propósito, salen del
     * catálogo con su motivo.
     *
     * @test
     */
    public function una_consulta_no_deja_nada_escrito_aunque_el_get_escriba()
    {
        foreach (['api/article/final-price-description/{id}', 'api/article/price-type-description/{id}/{price_type_id}'] as $ruta) {
            $this->assertNull(Catalogo::declaracion('GET', $ruta), $ruta);
            $this->assertSame('recalcula y guarda el precio: no es una consulta', Catalogo::motivo_de_exclusion('GET', $ruta), $ruta);
        }

        $this->assertNotNull(Catalogo::declaracion('GET', 'api/company-performance/{mes_inicio?}/{mes_fin?}'), 'El informe del día sigue siendo una lectura del catálogo');

        $this->dueno_en(ConfianzaDelAgenteIaHelper::CAUTELOSO);

        DB::table('company_performances')->where('user_id', $this->dueno->id)->where('from_today', 1)->update(['created_at' => now()->subDays(2)]);

        $antes = DB::table('company_performances')->where('user_id', $this->dueno->id)->orderBy('id')->pluck('id')->all();
        $max_antes = (int) DB::table('company_performances')->max('id');
        $nivel_antes = DB::transactionLevel();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'consultar_por_pantalla', ['ruta' => 'api/company-performance/{mes_inicio?}/{mes_fin?}']);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(201, $respuesta['status'], 'Corrió el camino "sin fechas", el que regenera el informe del día');

        $this->assertSame($antes, DB::table('company_performances')->where('user_id', $this->dueno->id)->orderBy('id')->pluck('id')->all(), 'Los informes del dueño quedaron como estaban');
        $this->assertSame($max_antes, (int) DB::table('company_performances')->max('id'), 'No quedó ningún informe nuevo');
        $this->assertSame($nivel_antes, DB::transactionLevel(), 'La conexión volvió al nivel de transacción de antes');
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Menores de la segunda vuelta: Zipnova queda afuera por credenciales (y no de casualidad por el
     * `zip` del patrón de archivos), y la etiqueta es un archivo solo cuando es el PDF del envío:
     * las medidas de etiqueta y el remitente de una venta entran.
     *
     * @test
     */
    public function zipnova_queda_afuera_por_credenciales_y_la_etiqueta_solo_es_archivo_si_es_el_pdf()
    {
        foreach ([['POST', 'api/integraciones/zipnova/conectar'], ['POST', 'api/integraciones/zipnova/disconnect'], ['PUT', 'api/integraciones/zipnova/config'], ['POST', 'api/integraciones/zipnova/origenes'], ['POST', 'api/integraciones/zipnova/cotizar-prueba'], ['GET', 'api/integraciones/zippin/connect']] as $par) {
            $this->assertNull(Catalogo::declaracion($par[0], $par[1]), $par[0] . ' ' . $par[1]);
            $this->assertSame('credenciales: Zippin / Zipnova', Catalogo::motivo_de_exclusion($par[0], $par[1]), $par[0] . ' ' . $par[1]);
        }

        $this->assertNull(Catalogo::declaracion('GET', 'api/envio/{id}/etiqueta'));
        $this->assertStringContainsString('archivo', (string) Catalogo::motivo_de_exclusion('GET', 'api/envio/{id}/etiqueta'));

        foreach ([['GET', 'api/etiqueta-medidas'], ['POST', 'api/etiqueta-medidas'], ['DELETE', 'api/etiqueta-medidas/{id}'], ['PUT', 'api/sale/{sale_id}/etiqueta-sender']] as $par) {
            $this->assertNotNull(Catalogo::declaracion($par[0], $par[1]), $par[0] . ' ' . $par[1] . ' no es un archivo y filtra por dueño');
        }
    }
}
