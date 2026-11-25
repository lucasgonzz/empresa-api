<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Misión whatsapp-aviso-pedido (25/11/2026) — TESTS DE ACEPTACIÓN, parte 3: el EMPUJE INMEDIATO (CA7 y CA9).
 *
 * Cuando tienda-api crea un pedido le avisa a empresa-api con `POST api/tienda/pedidos/{id}/aviso-al-asistente`
 * (público, sin clave, `throttle:60,1`). Empresa responde 202 `{motivo}` y, si corresponde (`se_avisa_al_admin`),
 * DESPUÉS de responder le pega al admin: `POST {ADMIN_API_URL}/api/inbound/asistente/pedido-nuevo` con
 * `{order_id}` y el header `X-Admin-Api-Key`. El admin adelanta así su consulta de recordatorios para ese
 * cliente; el aviso sigue siendo UNO por pedido (mismo ítem, mismo reclamo, mismo sello).
 *
 * Esta suite fija el lado de empresa de ese contrato:
 *   - el motivo de cada caso (uno por test) y que ninguno llama al admin salvo `se_avisa_al_admin`;
 *   - un solo POST al admin por pedido aunque la tienda insista, con el body y el header del contrato;
 *   - el pedido que dispara el empuje entra en `recordatorios-pendientes` (cursor en `id - 1` si era null);
 *   - 404 para el pedido inexistente, de otro dueño o de hace más de 30 minutos;
 *   - el admin caído (500) o sin responder (timeout) no rompe la respuesta y deja el log `admin_respondio`;
 *   - los logs `Aviso de pedido al asistente` sólo llevan `order_id`, `owner_id`, `motivo` y `http_status`;
 *   - un solo ítem por pedido aunque el dueño también tenga prendido el aviso de la 4.3.59 por chat.
 *
 * 🔴 Hoy está en ROJO a propósito: la ruta no existe (404) y la casilla tampoco. Los casos «de 404» y los
 * «que no llaman al admin» arrancan con una aserción positiva que hoy falla, si no pasarían vacíos.
 *
 * 🔴 Ningún test sale a la red: Http::fake con un comodín que contesta 599 para cualquier host que no sea el del
 * admin de prueba, y los logs se espían con Log::spy (no se escribe nada en el log real).
 *
 * «Después de la respuesta»: el cliente de pruebas de Laravel llama al `terminate()` del kernel al terminar cada
 * request, así que lo que empresa dejó para después de responder (`dispatchAfterResponse` / `terminating`) ya
 * corrió cuando vuelve `postJson`. Por eso NO se llama a mano a `app()->terminate()`: duplicaría los pedidos.
 *
 * El reloj está fijo (RecordatoriosTestCase): viernes 16/10/2026 a las 10:30.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Aviso_de_pedido_inmediato_Test extends RecordatoriosTestCase
{
    /** Base del admin en estos tests (config `services.admin_api.url`). */
    const ADMIN = 'https://admin.test';

    /** La clave con la que empresa le habla al admin (config `services.admin_api.inbound_key`). */
    const CLAVE_SALIENTE = 'clave-saliente-de-empresa-al-admin';

    /** El mensaje del log de esta función: es el único que se mira. */
    const MENSAJE_DEL_LOG = 'Aviso de pedido al asistente';

    /** Las únicas claves que puede llevar el contexto de ese log (sin datos personales). */
    const CLAVES_PERMITIDAS_DEL_LOG = ['order_id', 'owner_id', 'motivo', 'http_status'];

    /** @var Buyer El comprador de los pedidos. */
    protected $comprador;

    /** @var \Closure Lo que contesta el admin AHORA (se cambia dentro de un test). */
    protected $respuesta_del_admin;

    /** @var array Los pedidos HTTP que llegaron al admin de prueba (se anotan adentro del stub: Http::recorded() no registra los que terminan en excepción). */
    protected $intentos_al_admin = [];

    /** @var array Los contextos de los logs `Aviso de pedido al asistente`, en orden. */
    protected $logs_del_aviso = [];

    /** @var array Los callbacks de «después de responder» que el framework ya tenía al terminar de arrancar. */
    protected $callbacks_de_arranque = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->comprador = Buyer::create([
            'name'    => 'Gabriel',
            'surname' => 'Domínguez',
            'email'   => 'comprador-inm-' . uniqid() . '@testing.local',
            'user_id' => $this->comercio->id,
        ]);

        // La configuración de la tienda existe (es lo normal en un comercio con tienda); la casilla nace prendida.
        OnlineConfiguration::create(['user_id' => $this->comercio->id]);

        config([
            'services.admin_api.url'         => self::ADMIN,
            'services.admin_api.inbound_key' => self::CLAVE_SALIENTE,
        ]);

        // El admin contesta 202 hasta que un test diga otra cosa. Http::fake acumula los stubs y gana el primero
        // que coincide: lo que cambia entre casos es el comportamiento al que delega, no el stub.
        $this->respuesta_del_admin = function () {
            return Http::response(['encolado' => true], 202);
        };

        Http::fake([
            'admin.test/*' => function ($request) {
                $this->intentos_al_admin[] = $request;

                return call_user_func($this->respuesta_del_admin, $request);
            },
            '*'            => Http::response(['error' => 'host sin stub'], 599),
        ]);

        // Foto de los callbacks de «después de responder» ya registrados al arrancar (ver empujar()).
        $this->callbacks_de_arranque = $this->callbacks_despues_de_responder();

        // Los logs no se escriben: se espían, y de ellos sólo se guarda el contexto del aviso.
        $this->logs_del_aviso = [];

        $espia = Log::spy();
        $espia->shouldReceive('info')->andReturnUsing(function () {
            $argumentos = func_get_args();

            if (isset($argumentos[0]) && $argumentos[0] === self::MENSAJE_DEL_LOG) {
                $this->logs_del_aviso[] = isset($argumentos[1]) && is_array($argumentos[1]) ? $argumentos[1] : [];
            }
        });
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * Deja el canal conectado: el admin consultó los recordatorios hace un rato (el anuncio que lee `admin_avisa`).
     *
     * @return void
     */
    protected function conectar_canal()
    {
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');
    }

    /**
     * Apaga la casilla de la tienda. Falla con un mensaje claro si la columna todavía no existe.
     *
     * @return void
     */
    protected function apagar_casilla()
    {
        $this->assertTrue(
            Schema::hasColumn('online_configurations', 'notificar_pedido_por_whatsapp'),
            'Falta la columna online_configurations.notificar_pedido_por_whatsapp (la casilla de la misión no existe).'
        );

        DB::table('online_configurations')->where('user_id', $this->comercio->id)->update(['notificar_pedido_por_whatsapp' => 0]);
    }

    /**
     * Un pedido de la tienda con un renglón de 188.000 en total, creado a esa hora (por defecto, hace 5 minutos).
     *
     * @param  int  $num
     * @param  string  $creado_a
     * @param  int|null  $dueno_id  Dueño del pedido (null = el comercio del test).
     * @return \App\Models\Order
     */
    protected function pedido($num, $creado_a = '2026-10-16 10:25:00', $dueno_id = null)
    {
        Carbon::setTestNow(Carbon::parse($creado_a));

        $estado = OrderStatus::first();
        $dueno_id = is_null($dueno_id) ? $this->comercio->id : $dueno_id;

        $pedido = Order::create([
            'num'             => $num,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $this->comprador->id,
            'order_status_id' => is_null($estado) ? 1 : $estado->id,
            'user_id'         => $dueno_id,
            'total'           => 0,
        ]);

        $articulo = Article::create(['name' => 'Malbec Reserva ' . $num, 'user_id' => $dueno_id, 'status' => 'active']);
        $pedido->articles()->attach($articulo->id, ['price' => 94000, 'amount' => 2]);

        Carbon::setTestNow(Carbon::parse(self::AHORA));

        return $pedido->fresh();
    }

    /**
     * Los callbacks de «después de responder» (`terminating`) que tiene registrados la aplicación.
     *
     * @return array
     */
    protected function callbacks_despues_de_responder()
    {
        $propiedad = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'terminatingCallbacks');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($this->app);
    }

    /**
     * Lo que hace tienda-api al crear el pedido: pegarle a empresa.
     *
     * 🔴 En producción cada request vive en su propio proceso; en estos tests todos los requests comparten UNA
     * aplicación y Laravel no vacía los callbacks de «después de responder»: sin esto, el empuje de un pedido
     * volvería a correr (y a pegarle al admin) en el `terminate()` del request siguiente y los conteos de POST
     * dejarían de decir nada. Antes de cada empuje se vuelve a los callbacks que había al arrancar.
     *
     * @param  int  $pedido_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function empujar($pedido_id)
    {
        $propiedad = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'terminatingCallbacks');
        $propiedad->setAccessible(true);
        $propiedad->setValue($this->app, $this->callbacks_de_arranque);

        return $this->postJson('api/tienda/pedidos/' . $pedido_id . '/aviso-al-asistente');
    }

    /**
     * Empuja el pedido y exige 202 con ese motivo.
     *
     * @param  \App\Models\Order  $pedido
     * @param  string  $motivo
     * @return void
     */
    protected function empujar_y_esperar($pedido, $motivo)
    {
        $respuesta = $this->empujar($pedido->id);

        $respuesta->assertStatus(202);
        $this->assertSame($motivo, $respuesta->json('motivo'));
    }

    /**
     * Los POST que empresa le hizo al admin para adelantar el aviso.
     *
     * @return array<int,\Illuminate\Http\Client\Request>
     */
    protected function posts_al_admin()
    {
        $posts = [];

        // Laravel 8 sólo registra en Http::recorded() los pedidos que tuvieron respuesta: un timeout (el stub lanza
        // ConnectionException) no queda. Por eso los intentos se anotan adentro del stub del admin.
        foreach ($this->intentos_al_admin as $pedido_http) {

            if (strpos($pedido_http->url(), '/api/inbound/asistente/pedido-nuevo') !== false) {
                $posts[] = $pedido_http;
            }
        }

        return $posts;
    }

    /**
     * Todos los pedidos HTTP salientes de la prueba: ninguno puede ir a otro host que el admin de prueba.
     *
     * @return void
     */
    protected function assert_sin_red_externa()
    {
        foreach (Http::recorded()->all() as $par) {
            $this->assertStringStartsWith(self::ADMIN . '/', $par[0]->url(), 'Un pedido HTTP salió a un host que no es el admin de prueba.');
        }
    }

    /**
     * Los contextos del log de esta función que tienen ese motivo.
     *
     * @param  string  $motivo
     * @return array<int,array>
     */
    protected function logs_con_motivo($motivo)
    {
        return array_values(array_filter($this->logs_del_aviso, function ($contexto) use ($motivo) {
            return isset($contexto['motivo']) && $contexto['motivo'] === $motivo;
        }));
    }

    /**
     * Exige que el log dejó el motivo para este pedido y este dueño.
     *
     * @param  string  $motivo
     * @param  \App\Models\Order  $pedido
     * @return array  El contexto encontrado.
     */
    protected function assert_log($motivo, $pedido)
    {
        $encontrados = $this->logs_con_motivo($motivo);

        $this->assertNotEmpty($encontrados, 'No quedó el log «' . self::MENSAJE_DEL_LOG . '» con motivo ' . $motivo . '.');
        $this->assertSame((int) $pedido->id, (int) $encontrados[0]['order_id']);
        $this->assertSame((int) $this->comercio->id, (int) $encontrados[0]['owner_id']);

        return $encontrados[0];
    }

    /** Un cursor de pedidos del dueño, tal como quedó en la base. */
    protected function cursor_de_pedidos()
    {
        return DB::table('users')->where('id', $this->comercio->id)->value('agente_aviso_pedidos_cursor');
    }

    /**
     * Los ítems de pedido que ofrece `recordatorios-pendientes`.
     *
     * @return array<int,array>
     */
    protected function items_de_pedidos()
    {
        $respuesta = $this->pendientes();
        $respuesta->assertStatus(200);

        return array_values(array_filter($respuesta->json('recordatorios'), function ($item) {
            return strpos($item['id'], AvisosDelDuenoIaHelper::PREFIJO_PEDIDO) === 0;
        }));
    }

    /* ─────────────────────────────── el caso feliz ─────────────────────────────── */

    /**
     * 🔴 CA7 / CA9 — Pedido recién creado, dueño con Agentes de IA, casilla por defecto, canal conectado y admin
     * configurado: 202 `se_avisa_al_admin` y UN solo POST al admin con `{order_id}` y la clave. Un segundo empuje
     * del mismo pedido da `ya_avisado` y NO vuelve a llamar al admin.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisa_al_admin_una_sola_vez()
    {
        $this->conectar_canal();
        $pedido = $this->pedido(90);

        $this->empujar_y_esperar($pedido, 'se_avisa_al_admin');

        $posts = $this->posts_al_admin();
        $this->assertCount(1, $posts, 'Después de la respuesta sale un POST al admin.');
        $this->assertSame('POST', $posts[0]->method());
        $this->assertSame(self::ADMIN . '/api/inbound/asistente/pedido-nuevo', $posts[0]->url());
        $this->assertSame([self::CLAVE_SALIENTE], $posts[0]->header('X-Admin-Api-Key'));
        $this->assertSame(['order_id' => (int) $pedido->id], json_decode($posts[0]->body(), true));

        $this->assert_log('se_avisa_al_admin', $pedido);
        $this->assertSame(202, (int) $this->assert_log('admin_respondio', $pedido)['http_status']);

        // La tienda insiste (reintento, doble clic): no hay un segundo aviso.
        $this->empujar_y_esperar($pedido, 'ya_avisado');
        $this->assertCount(1, $this->posts_al_admin(), 'El segundo empuje no llama al admin.');

        $this->assert_sin_red_externa();
    }

    /**
     * 🔴 CA7 — Con el cursor de pedidos en null, el empuje lo deja en `id - 1`: el pedido que lo disparó entra en
     * `recordatorios-pendientes` (sin esto el primer pedido de un dueño con la casilla por defecto se perdería).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_pedido_que_dispara_entra_en_pendientes()
    {
        $this->conectar_canal();
        $pedido = $this->pedido(91);

        $this->assertNull($this->cursor_de_pedidos(), 'Escena: el dueño nunca tuvo cursor.');

        $this->empujar_y_esperar($pedido, 'se_avisa_al_admin');

        $this->assertSame((int) $pedido->id - 1, (int) $this->cursor_de_pedidos());

        $items = $this->items_de_pedidos();

        $this->assertCount(1, $items);
        $this->assertStringStartsWith(AvisosDelDuenoIaHelper::PREFIJO_PEDIDO . $pedido->id . '-', $items[0]['id']);
        $this->assertSame('Pedido N° 91 de la tienda: Gabriel Domínguez, $188.000', $items[0]['detalle']);
    }

    /* ─────────────────────────────── un motivo por caso ─────────────────────────────── */

    /**
     * 🔴 CA7 — Sin la extensión Agentes de IA: `sin_agentes_ia`, y no se llama al admin.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_agentes_ia_no_llama_al_admin()
    {
        $this->conectar_canal();
        $pedido = $this->pedido(92);
        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->empujar_y_esperar($pedido, 'sin_agentes_ia');

        $this->assertCount(0, $this->posts_al_admin());
        $this->assert_log('sin_agentes_ia', $pedido);
    }

    /**
     * 🔴 CA7 / CA5 — Casilla apagada: `apagado_por_el_dueno`, aunque el dueño tenga el aviso de la 4.3.59 prendido
     * por chat. No se llama al admin.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function apagado_por_el_dueno_no_llama_al_admin()
    {
        $this->conectar_canal();
        $this->apagar_casilla();
        DB::table('users')->where('id', $this->comercio->id)->update(['agente_aviso_pedidos' => 'al_toque']);
        $pedido = $this->pedido(93);

        $this->empujar_y_esperar($pedido, 'apagado_por_el_dueno');

        $this->assertCount(0, $this->posts_al_admin());
        $this->assert_log('apagado_por_el_dueno', $pedido);
    }

    /**
     * 🔴 CA7 — Si el dueño eligió el resumen por horas, el pedido va en el resumen y no se empuja:
     * `va_en_el_resumen_por_horas`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function va_en_el_resumen_por_horas_no_llama_al_admin()
    {
        $this->conectar_canal();
        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_pedidos'        => 'horas',
            'agente_aviso_pedidos_horas'  => '08:00,16:00',
            'agente_aviso_pedidos_desde'  => '2026-10-16 07:00:00',
            'agente_aviso_pedidos_cursor' => AvisosDelDuenoIaHelper::ultimo_id('orders'),
        ]);
        $pedido = $this->pedido(94);

        $this->empujar_y_esperar($pedido, 'va_en_el_resumen_por_horas');

        $this->assertCount(0, $this->posts_al_admin());
        $this->assert_log('va_en_el_resumen_por_horas', $pedido);
    }

    /**
     * 🔴 CA7 — El admin no consultó los recordatorios en las últimas horas (sin anuncio): `canal_no_conectado`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function canal_no_conectado_no_llama_al_admin()
    {
        $pedido = $this->pedido(95);

        $this->empujar_y_esperar($pedido, 'canal_no_conectado');

        $this->assertCount(0, $this->posts_al_admin());
        $this->assert_log('canal_no_conectado', $pedido);
    }

    /**
     * 🔴 CA7 — Sin `ADMIN_API_URL`: `sin_admin_configurado`, y nada sale.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_admin_configurado_no_llama_al_admin()
    {
        $this->conectar_canal();
        config(['services.admin_api.url' => '']);
        $pedido = $this->pedido(96);

        $this->empujar_y_esperar($pedido, 'sin_admin_configurado');

        $this->assertCount(0, Http::recorded()->all(), 'Sin admin configurado no sale ningún pedido HTTP.');
        $this->assert_log('sin_admin_configurado', $pedido);
    }

    /**
     * 🔴 CA7 — Un pedido ya sellado (el aviso ya salió por la consulta de siempre) no se empuja: `ya_avisado`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_pedido_ya_sellado_no_se_empuja()
    {
        $this->conectar_canal();
        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_pedidos'        => 'al_toque',
            'agente_aviso_pedidos_desde'  => '2026-10-16 09:00:00',
            'agente_aviso_pedidos_cursor' => AvisosDelDuenoIaHelper::ultimo_id('orders'),
        ]);
        $pedido = $this->pedido(97);

        $items = $this->items_de_pedidos();
        $this->assertCount(1, $items);
        $this->avisado($items[0]['id'], 'wamid.PEDIDO97')->assertStatus(200);

        $this->empujar_y_esperar($pedido, 'ya_avisado');

        $this->assertCount(0, $this->posts_al_admin());
    }

    /**
     * 🔴 CA7 — 404 para el pedido inexistente, el de otro dueño y el de hace más de 30 minutos. Primero se prueba que
     * un pedido bueno SÍ da 202 (si no, hoy la ruta inexistente daría 404 y el test pasaría vacío).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function pedido_inexistente_ajeno_o_viejo_da_404()
    {
        $this->conectar_canal();

        $bueno = $this->pedido(98);
        $this->empujar_y_esperar($bueno, 'se_avisa_al_admin');
        $posts_antes = count($this->posts_al_admin());

        $inexistente = (int) DB::table('orders')->max('id') + 999999;
        $this->empujar($inexistente)->assertStatus(404);

        $otro = $this->otro_dueno();
        $ajeno = $this->pedido(99, '2026-10-16 10:25:00', $otro->id);
        $this->empujar($ajeno->id)->assertStatus(404);

        $viejo = $this->pedido(100, '2026-10-16 09:59:00'); // hace 31 minutos
        $this->empujar($viejo->id)->assertStatus(404);

        $this->assertCount($posts_antes, $this->posts_al_admin(), 'Un 404 no llama al admin.');
    }

    /* ─────────────────────────────── el admin falla ─────────────────────────────── */

    /**
     * 🔴 CA7 — Si el admin responde 500, la respuesta a la tienda sigue siendo 202 y queda el log `admin_respondio`
     * con el `http_status`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function admin_caido_no_rompe()
    {
        $this->conectar_canal();
        $this->respuesta_del_admin = function () {
            return Http::response(['message' => 'Server Error'], 500);
        };
        $pedido = $this->pedido(101);

        $this->empujar_y_esperar($pedido, 'se_avisa_al_admin');

        $this->assertCount(1, $this->posts_al_admin());
        $this->assertSame(500, (int) $this->assert_log('admin_respondio', $pedido)['http_status']);
    }

    /**
     * 🔴 CA7 — Si el admin no contesta (timeout), la respuesta sigue siendo 202 y queda el log `admin_respondio`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function admin_con_timeout_no_rompe()
    {
        $this->conectar_canal();
        $this->respuesta_del_admin = function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        };
        $pedido = $this->pedido(102);

        $this->empujar_y_esperar($pedido, 'se_avisa_al_admin');

        $this->assertCount(1, $this->posts_al_admin());
        $this->assert_log('admin_respondio', $pedido);
    }

    /* ─────────────────────────────── los logs ─────────────────────────────── */

    /**
     * 🔴 CA7 — Todo log `Aviso de pedido al asistente` tiene como claves sólo `order_id`, `owner_id`, `motivo` y
     * opcionalmente `http_status`; ningún valor lleva el nombre del comprador, su mail, el importe ni una URL. Se
     * mira con el admin bien, con el admin caído y sin respuesta, y con un caso de rechazo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function logs_sin_datos_personales()
    {
        $this->conectar_canal();

        $this->empujar_y_esperar($this->pedido(103), 'se_avisa_al_admin');

        $this->respuesta_del_admin = function () {
            return Http::response(['message' => 'Server Error'], 500);
        };
        $this->empujar_y_esperar($this->pedido(104), 'se_avisa_al_admin');

        $this->respuesta_del_admin = function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        };
        $this->empujar_y_esperar($this->pedido(105), 'se_avisa_al_admin');

        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');
        $this->empujar_y_esperar($this->pedido(106), 'sin_agentes_ia');

        $this->assertGreaterThanOrEqual(4, count($this->logs_del_aviso), 'Cada empuje deja al menos su log.');

        foreach ($this->logs_del_aviso as $contexto) {

            $this->assertSame([], array_values(array_diff(array_keys($contexto), self::CLAVES_PERMITIDAS_DEL_LOG)), 'Claves de más en el log: ' . json_encode($contexto));
            $this->assertArrayHasKey('order_id', $contexto);
            $this->assertArrayHasKey('owner_id', $contexto);
            $this->assertArrayHasKey('motivo', $contexto);

            $volcado = json_encode($contexto, JSON_UNESCAPED_UNICODE);

            foreach (['Gabriel', 'Domínguez', '188.000', '188000', 'testing.local', 'admin.test', self::CLAVE_SALIENTE] as $dato_personal) {
                $this->assertStringNotContainsString($dato_personal, $volcado, 'El log no puede llevar datos personales ni claves.');
            }
        }
    }

    /* ─────────────────────────────── CA9 ─────────────────────────────── */

    /**
     * 🔴 CA9 — Con el aviso de la 4.3.59 ya prendido por chat (`al_toque`) más el empuje, el ítem del pedido es UNO;
     * después de sellarlo `recordatorios-pendientes` ya no lo ofrece y un segundo empuje da `ya_avisado`: el dueño
     * recibe un solo mensaje por pedido.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_solo_item_con_el_aviso_del_chat()
    {
        $this->conectar_canal();
        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_pedidos'        => 'al_toque',
            'agente_aviso_pedidos_desde'  => '2026-10-16 09:00:00',
            'agente_aviso_pedidos_cursor' => AvisosDelDuenoIaHelper::ultimo_id('orders'),
        ]);
        $pedido = $this->pedido(107);

        $this->empujar_y_esperar($pedido, 'se_avisa_al_admin');
        $this->assertCount(1, $this->posts_al_admin());

        $items = $this->items_de_pedidos();
        $this->assertCount(1, $items, 'Un solo ítem por pedido.');
        $this->assertSame('recordatorio:pedido_tienda:' . $pedido->id . '-' . Carbon::parse($pedido->created_at)->timestamp, $items[0]['id']);

        // La consulta de respaldo del admin lo ve de nuevo antes del sello: es el MISMO ítem.
        $this->assertSame($items, $this->items_de_pedidos());

        $this->avisado($items[0]['id'], 'wamid.PEDIDO107')->assertStatus(200);
        $this->assertSame([], $this->items_de_pedidos(), 'Sellado, no se vuelve a ofrecer.');

        $this->empujar_y_esperar($pedido, 'ya_avisado');
        $this->assertCount(1, $this->posts_al_admin(), 'Ni el segundo empuje ni la consulta llaman de nuevo.');
    }
}
