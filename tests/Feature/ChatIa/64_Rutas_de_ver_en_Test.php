<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Expense;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión ver-en-del-asistente-refresca-destino (10/10/2026) — el contrato del botón "Ver en …" de las
 * tarjetas del asistente (`resultado.ruta`), que la SPA pasa tal cual a `router.push({ name, params })`
 * en `components/asistente-ia/AccionCard.vue`.
 *
 * Lo que protege:
 *
 * - Que todo `name` de una ruta "Ver en …" exista en el router de empresa-spa. Hasta el 10/10/2026 las
 *   tarjetas de stock mandaban 'listado' y la de compra con factura 'proveedores': ninguno existe, y
 *   vue-router 3 resuelve un name inexistente a `/` sin componente (pantalla en blanco, sin error).
 * - Que las rutas a pantallas con solapas lleven `view`: "Ver en Clientes" y "Ver en Proveedores" iban
 *   sin params (cuerpo en blanco) y "Ver en Sucursales" al ABM pelado (abría Categorías).
 * - Que el gasto lleve en `ruta.fecha` el día en que quedó (`created_at`), y que el listado de ESE día
 *   (`GET expense/from-date/{fecha}`, el mismo que pide la pantalla de Gastos) lo traiga. Un gasto con
 *   fecha anterior no está en el día de hoy: sin la fecha, "Ver en Gastos" abría un día donde no estaba.
 *   Por los dos caminos: la tarjeta que se confirma con el dedo (modo `cauteloso`) y la carga en el acto
 *   (modo `directo`, el default desde el 28/9/2026 y el que tenía la demo donde se vio el defecto).
 *
 * @group chat-ia
 */
class Rutas_de_ver_en_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * Los `name` de `src/router/index.js` de empresa-spa (develop, 10/10/2026). Copiados del router, no
     * de lo que manda el API: si una propuesta nueva manda un name que no está acá, o el router lo
     * agrega (y entonces se suma a esta lista), o la propuesta está mal.
     */
    const NAMES_DEL_ROUTER_DE_LA_SPA = [
        'root', 'login', 'demoIngreso', 'informeCompartido', 'passwordReset', 'configuration', 'abm',
        'employee', 'alertas', 'vender', 'expense', 'cheque', 'reportes', 'panel', 'article',
        'deposito-para-checkear', 'deposito-checkeadas', 'por-entregar', 'por-estado', 'rutas', 'sale',
        'VentasAll', 'provider', 'client', 'budget', 'produccion', 'produccionV2', 'caja', 'online',
        'whatsapp', 'tienda_nube', 'mercado_libre', 'pending', 'comprobantes', 'consultora_de_precios',
        'papelera', 'devoluciones', 'ia', 'ofertas',
    ];

    /**
     * Pantallas de la SPA que son una barra de solapas y cuyo cuerpo cuelga de `params.view`: sin
     * `view`, Clientes y Proveedores dibujan solo la barra (cuerpo en blanco), el ABM abre Categorías y
     * Tienda Online abre Pedidos. Una ruta "Ver en …" a cualquiera de ellas tiene que llevar `view`.
     */
    const NAMES_QUE_NECESITAN_VIEW = ['client', 'provider', 'abm', 'online'];

    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red (no llama a la API).
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // El camino con tarjeta: en `directo` (el default de la columna desde el 28/9/2026) el gasto se
        // registra en el acto y no queda nada para confirmar. El rollback de la transacción lo vuelve.
        $this->dueno->agente_confianza = ConfianzaDelAgenteIaHelper::CAUTELOSO;
        $this->dueno->save();

        // El gate de las rutas del chat pide la extensión; el rollback de la transacción la saca.
        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * El gasto de hoy: la ruta trae el día de hoy y el listado de ese día lo tiene.
     *
     * @test
     */
    public function el_gasto_de_hoy_lleva_en_la_ruta_el_dia_de_hoy_y_ese_dia_lo_lista()
    {
        $this->fijar_reloj_en(Carbon::parse('2026-09-18 10:00:00'));

        $confirmar = $this->confirmar_gasto(null);

        $confirmar->assertStatus(200);
        $this->assertEquals('expense', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals('2026-09-18', $confirmar->json('model.resultado.ruta.fecha'));

        $gasto = $this->ultimo_gasto();

        $this->assertContains($gasto->id, $this->ids_del_listado_de('2026-09-18'));
    }

    /**
     * 🔴 El gasto con fecha anterior queda en ESE día (`created_at`), no hoy: la ruta trae ese día, el
     * listado de ese día lo tiene y el de hoy no. Es lo que hace que "Ver en Gastos" lo muestre.
     *
     * @test
     */
    public function un_gasto_con_fecha_anterior_lleva_en_la_ruta_ese_dia_y_no_hoy()
    {
        $this->fijar_reloj_en(Carbon::parse('2026-09-18 10:00:00'));

        $confirmar = $this->confirmar_gasto('2026-09-15');

        $confirmar->assertStatus(200);
        $this->assertEquals('2026-09-15', $confirmar->json('model.resultado.ruta.fecha'));

        $gasto = $this->ultimo_gasto();

        $this->assertEquals('2026-09-15', $gasto->created_at->format('Y-m-d'));
        $this->assertContains($gasto->id, $this->ids_del_listado_de('2026-09-15'));
        $this->assertNotContains($gasto->id, $this->ids_del_listado_de('2026-09-18'));
    }

    /**
     * 🔴 En modo `directo` el gasto se registra en el acto (sin tarjeta que confirmar) y su `resultado`
     * trae la misma ruta con el día. Es el camino donde Lucas vio el defecto en la demo (4.3.8): "Gasto
     * N° 87 registrado" + "Ver en Gastos" sin haber tocado Confirmar.
     *
     * @test
     */
    public function en_modo_directo_el_gasto_registrado_en_el_acto_trae_la_ruta_con_su_dia()
    {
        $this->fijar_reloj_en(Carbon::parse('2026-09-18 10:00:00'));

        $this->dueno->agente_confianza = ConfianzaDelAgenteIaHelper::DIRECTO;
        $this->dueno->save();

        list($conversation, $assistant, $respuesta) = $this->proponer_gasto('2026-09-16');

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado, 'En modo directo el gasto se registra sin tarjeta.');
        $this->assertEquals('expense', $accion->resultado->ruta->name);
        $this->assertEquals('2026-09-16', $accion->resultado->ruta->fecha);

        $gasto = $this->ultimo_gasto();
        $this->gastos_creados_por_escenarios[] = $gasto->id;

        $this->assertContains($gasto->id, $this->ids_del_listado_de('2026-09-16'));
    }

    /**
     * Las altas y ediciones genéricas (catálogo de escritura) de una pantalla que lista por día llevan el
     * día del registro en la ruta: editar por el chat un gasto de otro día y tocar "Ver en Gastos" tiene
     * que abrir ese día. Las demás pantallas no lo llevan.
     *
     * @test
     */
    public function la_ruta_generica_de_una_pantalla_por_dia_lleva_el_dia_del_registro()
    {
        $metodo = new \ReflectionMethod(\App\Http\Controllers\Helpers\asistente_ia\EjecutorGenericoIaHelper::class, 'ruta_con_dia');
        $metodo->setAccessible(true);

        $gasto = $metodo->invoke(null, 'expense', (object) ['id' => 1, 'created_at' => '2026-09-15 18:30:00']);
        $this->assertEquals('expense', $gasto['name']);
        $this->assertEquals('2026-09-15', $gasto['fecha']);

        $cliente = $metodo->invoke(null, 'client', (object) ['id' => 1, 'created_at' => '2026-09-15 18:30:00']);
        $this->assertEquals('client', $cliente['name']);
        $this->assertArrayNotHasKey('fecha', $cliente, 'Clientes no lista por día: la ruta no lleva fecha.');

        $sin_fila = $metodo->invoke(null, 'expense', null);
        $this->assertArrayNotHasKey('fecha', $sin_fila);
    }

    /**
     * La clave nueva no cambia la forma de `params`: sigue viajando como objeto vacío (la SPA se lo
     * pasa a router.push). Se mira el contenido CRUDO, como en Acciones_gasto_Test.
     *
     * @test
     */
    public function la_fecha_no_cambia_la_forma_de_los_params()
    {
        $confirmar = $this->confirmar_gasto(null);

        $confirmar->assertStatus(200);
        $this->assertStringContainsString('"params":{}', $confirmar->getContent());
    }

    /**
     * 🔴 Todo `name` de una ruta "Ver en …" de los helpers del asistente existe en el router de la SPA.
     *
     * Se lee el código de los helpers (cada bloque `'ruta' => [` y el `'name'` que lo sigue, literal o
     * `self::CONSTANTE`) en vez de recorrer cada propuesta de punta a punta: hay ~15 helpers que arman la
     * ruta en línea, varios detrás de flujos caros (escaneo de facturas, imágenes), y lo que se quiere
     * cuidar es justamente que nadie escriba un name que la SPA no tiene. También se recorren las rutas
     * del catálogo de escritura genérico, que se arman desde su tabla.
     *
     * @test
     */
    public function toda_ruta_de_ver_en_apunta_a_un_name_que_existe_en_la_spa()
    {
        $carpeta = app_path('Http/Controllers/Helpers/asistente_ia');
        $encontrados = [];

        foreach (glob($carpeta . '/*.php') as $archivo) {
            $codigo = file_get_contents($archivo);
            $clase = 'App\\Http\\Controllers\\Helpers\\asistente_ia\\' . basename($archivo, '.php');

            // Cada `'ruta' => [` del archivo, sin excepción: su primer elemento (sacando comentarios)
            // tiene que ser `'name' => '...'` o `'name' => self::CONSTANTE`. Si alguno no se puede
            // leer, el test lo dice en vez de saltearlo (un comentario largo entre `[` y `'name'`
            // dejaba afuera la ruta del combo con la primera versión de este lector).
            preg_match_all("/'ruta'\\s*=>\\s*\\[/", $codigo, $aperturas, PREG_OFFSET_CAPTURE);

            foreach ($aperturas[0] as $apertura) {
                $desde = $apertura[1] + strlen($apertura[0]);
                $tramo = self::sin_comentarios(substr($codigo, $desde, 1500));
                $legible = preg_match("/^\\s*'name'\\s*=>\\s*(?:'([A-Za-z_\\-]+)'|self::([A-Z_]+))/", $tramo, $m);

                $this->assertSame(1, $legible, 'Una ruta de ' . basename($archivo) . ' no empieza por un `name` legible (literal o self::CONSTANTE): ' . substr($tramo, 0, 120));

                $name = !empty($m[1]) ? $m[1] : constant($clase . '::' . $m[2]);
                $encontrados[] = [basename($archivo), $name, self::tramo_de_params($tramo, [$m[0], 0])];
            }

            // Las funciones que devuelven la ruta armada (ruta_a_disenos, ruta_de_la_agenda, ...).
            preg_match_all("/function\\s+ruta_[a-z_]+\\s*\\([^)]*\\)\\s*\\{\\s*return\\s*\\[\\s*'name'\\s*=>\\s*'([A-Za-z_\\-]+)'/s", $codigo, $funciones, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($funciones as $funcion) {
                $encontrados[] = [basename($archivo), $funcion[1][0], self::tramo_de_params($codigo, $funcion[0])];
            }
        }

        // Que el lector encontró algo de verdad: sin esto, un cambio de formato del código lo dejaría
        // pasando en verde sin mirar nada. Al 10/10/2026 son más de 50 rutas.
        $this->assertGreaterThan(40, count($encontrados), 'El lector de rutas no encontró los bloques: revisar la expresión.');

        $names = array_unique(array_column($encontrados, 1));
        $this->assertContains('expense', $names);
        $this->assertContains('pending', $names);
        $this->assertContains('abm', $names);

        foreach ($encontrados as $par) {
            $this->assertContains($par[1], self::NAMES_DEL_ROUTER_DE_LA_SPA, 'La ruta "' . $par[1] . '" de ' . $par[0] . ' no existe en el router de la SPA: el botón "Ver en …" dejaría la pantalla en blanco.');

            if (in_array($par[1], self::NAMES_QUE_NECESITAN_VIEW, true)) {
                $this->assertMatchesRegularExpression("/'view'\\s*=>/", $par[2], 'La ruta "' . $par[1] . '" de ' . $par[0] . ' no lleva view: la pantalla abriría sin solapa (en blanco o en otra). Tramo leído: ' . $par[2]);
            }
        }
    }

    /**
     * El código sin comentarios, de línea y de bloque.
     *
     * @param string $codigo
     * @return string
     */
    protected static function sin_comentarios($codigo)
    {
        return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $codigo);
    }

    /**
     * El tramo de código de una ruta que va desde su `'name'` hasta su `'texto'`: ahí viajan los
     * `params`. En todos los helpers el orden es name, params, texto.
     *
     * @param string $codigo
     * @param array $coincidencia  [texto, offset] de lo que matcheó el lector (termina en el name)
     * @return string
     */
    protected static function tramo_de_params($codigo, array $coincidencia)
    {
        $desde = $coincidencia[1] + strlen($coincidencia[0]);
        $hasta = strpos($codigo, "'texto'", $desde);

        return substr($codigo, $desde, ($hasta === false ? 300 : $hasta - $desde));
    }

    /**
     * Propone y confirma un gasto de $1.000 en efectivo, por el mismo camino que la tarjeta.
     *
     * @param string|null $fecha  AAAA-MM-DD, o null para hoy.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar_gasto($fecha)
    {
        list($conversation, $assistant, $respuesta) = $this->proponer_gasto($fecha);

        // El mensaje pasa a listo: la SPA recién ahí muestra la tarjeta.
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $gasto = $this->ultimo_gasto();

        if (!is_null($gasto)) {
            $this->gastos_creados_por_escenarios[] = $gasto->id;
        }

        return $confirmar;
    }

    /**
     * Le pide al asistente un gasto de $1.000 en efectivo por la herramienta `proponer_gasto`, por el
     * mismo camino que el loop del servicio. Según el modo del dueño queda una tarjeta en propuesta
     * (`cauteloso`) o el gasto ya registrado (`directo`).
     *
     * @param string|null $fecha  AAAA-MM-DD, o null para hoy.
     * @return array{0: AiConversation, 1: AiMessage, 2: array}
     */
    protected function proponer_gasto($fecha)
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        list($conversation, $assistant) = $this->conversacion();

        $input = [
            'subcategoria_id' => $concepto->id,
            'monto'           => 1000,
            'pagos'           => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
        ];

        if (!is_null($fecha)) {
            $input['fecha'] = $fecha;
        }

        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => 'proponer_gasto',
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        $respuesta = json_decode($resultados[0]['content'], true);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        return [$conversation, $assistant, $respuesta];
    }

    /**
     * Conversación del dueño con su assistant pendiente y las acciones habilitadas (como en
     * Acciones_gasto_Test::conversacion).
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cargame el gasto',
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
     * @return Expense|null
     */
    protected function ultimo_gasto()
    {
        return Expense::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * Ids que trae el listado de Gastos de ese día: el mismo pedido que hace la pantalla
     * (store expense, `from_dates`).
     *
     * @param string $dia  AAAA-MM-DD
     * @return array
     */
    protected function ids_del_listado_de($dia)
    {
        $listado = $this->getJson('api/expense/from-date/' . $dia);

        $listado->assertStatus(200);

        return array_map('intval', array_column($listado->json('models'), 'id'));
    }
}
