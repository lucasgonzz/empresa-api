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
use App\Models\Article;
use App\Models\Budget;
use App\Models\Caja;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
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

        // Por método y por página: la segunda página de los DELETE.
        $borrados = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['metodo' => 'DELETE', 'pagina' => 2]);

        $this->assertGreaterThan(40, $borrados['encontradas']);
        $this->assertSame(2, $borrados['pagina']);
        $this->assertGreaterThanOrEqual(2, $borrados['paginas']);
        $this->assertNotEmpty($borrados['acciones']);

        foreach ($borrados['acciones'] as $accion) {
            $this->assertSame('DELETE', $accion['metodo']);
        }

        $primera = $this->herramienta($conversation, $assistant, 'que_acciones_de_pantalla_hay', ['metodo' => 'DELETE', 'pagina' => 1]);

        $this->assertNotSame($primera['acciones'][0]['ruta'], $borrados['acciones'][0]['ruta'], 'La página 2 no repite la 1');

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
     * No se toca ARCA: el `sale_id` no existe, así que aunque la puerta fallara, makeAfipTicket()
     * devolvería `response(null, 200)` sin instanciar MakeAfipTicket. Lo que se mide es que la
     * tarjeta quede propuesta y que `afip_tickets` y `sales` no cambien.
     *
     * @test
     */
    public function una_accion_que_emite_comprobantes_ante_arca_siempre_deja_tarjeta_incluso_en_directo()
    {
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
            'cuerpo'      => ['sale_id' => 999999999],
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
        $this->assertSame(999999999, $tarjeta->datos['cuerpo']['sale_id']);

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
}
