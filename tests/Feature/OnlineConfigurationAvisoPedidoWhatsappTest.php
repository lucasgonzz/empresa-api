<?php

namespace Tests\Feature;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AsistenteWhatsapp\RecordatoriosTestCase;

/**
 * Misión whatsapp-aviso-pedido (25/11/2026) — TESTS DE ACEPTACIÓN, parte 4: la CASILLA (CA5) por el endpoint
 * real `PUT api/online-configuration/{id}`.
 *
 * La casilla «Recibir por WhatsApp el aviso de pedido nuevo de tu asistente personal» es la columna
 * `online_configurations.notificar_pedido_por_whatsapp` (boolean NOT NULL, default 1). Lo que fija este archivo:
 *   1. Apagarla corta los avisos: el modo efectivo de pedidos queda `apagado` aunque el dueño tenga
 *      `users.agente_aviso_pedidos = al_toque` (prendido por chat desde la 4.3.59), y el empuje inmediato
 *      de la tienda devuelve `apagado_por_el_dueno`.
 *   2. Prenderla arranca desde ese momento: el cursor de pedidos queda en el id más alto de `orders`, así que
 *      un pedido que entró mientras estaba apagada no se avisa, y el que entra después sí.
 *   3. Un guardado sin la clave (una pantalla abierta desde antes del deploy) conserva el valor: no apaga ni
 *      prende por accidente.
 *
 * 🔴 Hoy está en ROJO a propósito: la columna y su lectura/escritura en el controlador no existen, así que
 * el PUT devuelve el modelo sin la clave nueva.
 *
 * Reutiliza la escena de RecordatoriosTestCase (dueño con Agentes de IA, reloj fijo del viernes 16/10/2026 a las
 * 10:30, clave del admin). Sin red: Http::fake con un comodín que contesta 599.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class OnlineConfigurationAvisoPedidoWhatsappTest extends RecordatoriosTestCase
{
    /** @var \App\Models\Buyer El comprador de los pedidos. */
    protected $comprador;

    /** @var \App\Models\OnlineConfiguration La configuración de la tienda del dueño. */
    protected $configuracion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comprador = Buyer::create([
            'name'    => 'Gabriel',
            'surname' => 'Domínguez',
            'email'   => 'comprador-cfg-' . uniqid() . '@testing.local',
            'user_id' => $this->comercio->id,
        ]);

        $this->configuracion = OnlineConfiguration::create(['user_id' => $this->comercio->id]);

        // El dueño abre la configuración de su tienda desde la SPA.
        $this->actingAs($this->comercio, 'web');

        // Ningún pedido sale a la red: lo que no es el admin de prueba contesta 599.
        config(['services.admin_api.url' => 'https://admin.test', 'services.admin_api.inbound_key' => 'clave-saliente-de-empresa-al-admin']);
        Http::fake([
            'admin.test/*' => Http::response(['encolado' => true], 202),
            '*'            => Http::response(['error' => 'host sin stub'], 599),
        ]);
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * El PUT del formulario ABM: manda el modelo entero (así lo hace siempre la SPA) con lo que se pise.
     *
     * @param  array  $cambios  Claves a pisar.
     * @param  array  $sacar  Claves a sacar del cuerpo (una pantalla vieja que no las conoce).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function guardar_configuracion(array $cambios = [], array $sacar = [])
    {
        $cuerpo = array_merge($this->configuracion->fresh()->toArray(), $cambios);

        foreach ($sacar as $clave) {
            unset($cuerpo[$clave]);
        }

        return $this->putJson('api/online-configuration/' . $this->configuracion->id, $cuerpo);
    }

    /**
     * El modo de pedidos que efectivamente aplica hoy para el dueño.
     *
     * @return string
     */
    protected function modo_de_pedidos()
    {
        return AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'];
    }

    /**
     * Un pedido de la tienda recién creado (hace 5 minutos), con un renglón.
     *
     * @param  int  $num
     * @return \App\Models\Order
     */
    protected function pedido($num)
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:25:00'));

        $estado = OrderStatus::first();

        $pedido = Order::create([
            'num'             => $num,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $this->comprador->id,
            'order_status_id' => is_null($estado) ? 1 : $estado->id,
            'user_id'         => $this->comercio->id,
            'total'           => 0,
        ]);

        $articulo = Article::create(['name' => 'Malbec Reserva ' . $num, 'user_id' => $this->comercio->id, 'status' => 'active']);
        $pedido->articles()->attach($articulo->id, ['price' => 94000, 'amount' => 2]);

        Carbon::setTestNow(Carbon::parse(self::AHORA));

        return $pedido->fresh();
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

    /* ─────────────────────────────── CA5 ─────────────────────────────── */

    /**
     * 🔴 CA5 — Apagarla corta los avisos. PUT con `false` → 200 y `model.notificar_pedido_por_whatsapp === false`; los
     * pedidos quedan `apagado` aunque `users.agente_aviso_pedidos` sea `al_toque`, y el empuje de la tienda devuelve
     * `apagado_por_el_dueno`.
     *
     * @group configuracion-online
     * @test
     */
    public function test_apagarlo_corta_los_avisos()
    {
        DB::table('users')->where('id', $this->comercio->id)->update(['agente_aviso_pedidos' => 'al_toque']);
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        $respuesta = $this->guardar_configuracion(['notificar_pedido_por_whatsapp' => false]);

        $respuesta->assertStatus(200);
        $this->assertSame(false, $respuesta->json('model.notificar_pedido_por_whatsapp'));

        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, $this->modo_de_pedidos(), 'La casilla apagada gana sobre el chat.');

        $pedido = $this->pedido(150);
        $empuje = $this->postJson('api/tienda/pedidos/' . $pedido->id . '/aviso-al-asistente');

        $empuje->assertStatus(202);
        $this->assertSame('apagado_por_el_dueno', $empuje->json('motivo'));
    }

    /**
     * 🔴 CA5 — Prenderla arranca desde ese momento. Con la casilla apagada entra un pedido; al volver a `true`
     * el cursor queda en el id más alto de `orders`, así que ese pedido no se avisa y el que entra después sí.
     *
     * @group configuracion-online
     * @test
     */
    public function test_prenderlo_arranca_desde_ese_momento()
    {
        $apagada = $this->guardar_configuracion(['notificar_pedido_por_whatsapp' => false]);
        $apagada->assertStatus(200);
        $this->assertSame(false, $apagada->json('model.notificar_pedido_por_whatsapp'));

        $mientras_estaba_apagada = $this->pedido(151);
        $this->assertSame([], $this->items_de_pedidos(), 'Apagada, no se ofrece nada.');

        $prendida = $this->guardar_configuracion(['notificar_pedido_por_whatsapp' => true]);
        $prendida->assertStatus(200);
        $this->assertSame(true, $prendida->json('model.notificar_pedido_por_whatsapp'));

        $this->assertSame(
            AvisosDelDuenoIaHelper::ultimo_id('orders'),
            (int) DB::table('users')->where('id', $this->comercio->id)->value('agente_aviso_pedidos_cursor'),
            'Al prenderla el cursor queda en el último pedido de ese momento.'
        );
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE, $this->modo_de_pedidos());
        $this->assertSame([], $this->items_de_pedidos(), 'El pedido que entró mientras estaba apagada no se avisa.');

        $nuevo = $this->pedido(152);
        $items = $this->items_de_pedidos();

        $this->assertCount(1, $items);
        $this->assertStringStartsWith(AvisosDelDuenoIaHelper::PREFIJO_PEDIDO . $nuevo->id . '-', $items[0]['id']);
        $this->assertNotSame($mientras_estaba_apagada->id, $nuevo->id);
    }

    /**
     * 🔴 CA5 — Un guardado sin la clave conserva el valor (una pantalla abierta desde antes del deploy no apaga ni
     * prende la casilla por accidente), en los dos sentidos.
     *
     * @group configuracion-online
     * @test
     */
    public function test_un_guardado_sin_la_clave_conserva_el_valor()
    {
        $apagada = $this->guardar_configuracion(['notificar_pedido_por_whatsapp' => false]);
        $apagada->assertStatus(200);
        $this->assertSame(false, $apagada->json('model.notificar_pedido_por_whatsapp'));

        $sin_clave = $this->guardar_configuracion([], ['notificar_pedido_por_whatsapp']);
        $sin_clave->assertStatus(200);
        $this->assertSame(false, $sin_clave->json('model.notificar_pedido_por_whatsapp'), 'Apagada, un PUT sin la clave la deja apagada.');

        $prendida = $this->guardar_configuracion(['notificar_pedido_por_whatsapp' => true]);
        $prendida->assertStatus(200);
        $this->assertSame(true, $prendida->json('model.notificar_pedido_por_whatsapp'));

        $sin_clave = $this->guardar_configuracion([], ['notificar_pedido_por_whatsapp']);
        $sin_clave->assertStatus(200);
        $this->assertSame(true, $sin_clave->json('model.notificar_pedido_por_whatsapp'), 'Prendida, un PUT sin la clave la deja prendida.');
    }
}
