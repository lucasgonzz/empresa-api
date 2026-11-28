<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaAvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\DeliveryZone;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Misión whatsapp-aviso-pedido (25/11/2026) — TESTS DE ACEPTACIÓN, parte 1: el CONTENIDO del aviso de un pedido
 * nuevo de la tienda y la CASILLA «Recibir por WhatsApp el aviso de pedido nuevo de tu asistente personal».
 *
 * Qué fija este archivo (criterios CA1 a CA4 y CA6 del plan):
 *   1. CA1: el ítem `pedido_tienda` de `recordatorios-pendientes` trae el texto completo (número, comprador,
 *      total, entrega, pago y artículos) y el `detalle` de siempre.
 *   2. CA2: entrega y pago según el pedido, y sólo los primeros 3 artículos («… y K más»).
 *   3. CA3: fuera de las 24 h el ítem lleva `plantilla = tarea_del_asistente` y un `resumen` corto (≤ 400); los
 *      ítems de la agenda y de stock cero NO llevan plantilla.
 *   4. CA4: con Agentes de IA y la fila de configuración de la tienda recién creada el aviso queda prendido por
 *      defecto (columna `online_configurations.notificar_pedido_por_whatsapp`, default 1); sin Agentes de IA o
 *      sin fila queda apagado.
 *   5. CA6: la tarjeta del chat `proponer_avisos_por_whatsapp` y la casilla dicen lo mismo.
 *
 * 🔴 Hoy estos tests están en ROJO a propósito: la función no existe (el texto sólo trae número, comprador y
 * total; el ítem no trae plantilla; no hay casilla). Los casos «negativos» (sin extensión, sin fila) arrancan
 * con una aserción positiva que hoy falla: si no, pasarían vacíos porque hoy TODO está apagado.
 *
 * El reloj está fijo (RecordatoriosTestCase): viernes 16/10/2026 a las 10:30. Sin red: nada de esta suite sale a
 * ningún lado.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Aviso_de_pedido_completo_y_por_defecto_Test extends RecordatoriosTestCase
{
    /**
     * El texto exacto del aviso del pedido N° 78 del CA1 (ventana de 24 h abierta), en el formato de la misión
     * asistente-diario-20261128 (CA1/CA3: comprador con contacto, artículos con precio y total en su propia línea).
     * Pasó de constante a método porque ahora lleva el email del comprador del test (que es único por corrida).
     *
     * @param  string  $email  El email del comprador del pedido.
     * @return string
     */
    protected function texto_del_pedido_78($email)
    {
        return "🛒 Entró un pedido nuevo en la tienda: *N° 78*\n"
            . "Comprador: Gabriel Domínguez · Tel. sin cargar · " . $email . "\n"
            . "Entrega: Retiro por local\n"
            . "Artículos:\n"
            . "• Malbec Reserva x2 · \$94.000 c/u = \$188.000\n"
            . "Total: *\$188.000*\n"
            . "Pago: Mercado Pago";
    }

    /** El `resumen` exacto del pedido N° 78 del CA1 (ventana cerrada: va como parámetro de la plantilla de tareas). */
    const RESUMEN_DEL_PEDIDO_78 = 'el aviso del pedido N° 78 de la tienda: Gabriel Domínguez, $188.000, retiro por local, pago con Mercado Pago, 1 artículo';

    /** @var Buyer El comprador de los pedidos. */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comprador = Buyer::create([
            'name'    => 'Gabriel',
            'surname' => 'Domínguez',
            'email'   => 'comprador-wa-' . uniqid() . '@testing.local',
            'user_id' => $this->comercio->id,
        ]);
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * Crea la fila de configuración de la tienda del dueño (la que tiene la casilla). Nada la crea sola en estos
     * tests: el User se arma con User::create. Los valores caen al default de la migración.
     *
     * @return \App\Models\OnlineConfiguration
     */
    protected function configuracion_de_la_tienda()
    {
        return OnlineConfiguration::create(['user_id' => $this->comercio->id]);
    }

    /**
     * El valor de la casilla del dueño, tal como quedó en la base. Falla con un mensaje claro si la columna
     * todavía no existe (la función no está construida).
     *
     * @return int|null
     */
    protected function casilla()
    {
        $this->assertTrue(
            Schema::hasColumn('online_configurations', 'notificar_pedido_por_whatsapp'),
            'Falta la columna online_configurations.notificar_pedido_por_whatsapp (la casilla de la misión no existe).'
        );

        $valor = DB::table('online_configurations')->where('user_id', $this->comercio->id)->value('notificar_pedido_por_whatsapp');

        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * Deja el cursor de pedidos en el último id de la tabla (como lo deja prender los avisos): lo que se cree
     * después es lo nuevo. NO toca `agente_aviso_pedidos`: el modo lo decide la casilla.
     *
     * @return void
     */
    protected function cursor_de_pedidos_al_dia()
    {
        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_pedidos_cursor' => AvisosDelDuenoIaHelper::ultimo_id('orders'),
            'agente_aviso_pedidos_desde'  => '2026-10-16 09:00:00',
        ]);
    }

    /**
     * Un pedido de la tienda, creado a esa hora, con sus renglones.
     *
     * @param  int  $num  Número de pedido.
     * @param  array  $renglones  Lista de [nombre, precio, cantidad].
     * @param  array  $columnas  Columnas de `orders` a pisar (deliver, delivery_zone_id, payment_method_id, ...).
     * @param  string  $creado_a  Momento del `created_at`.
     * @param  \App\Models\Buyer|null  $comprador
     * @return \App\Models\Order
     */
    protected function pedido($num, array $renglones, array $columnas = [], $creado_a = '2026-10-16 10:05:00', $comprador = null)
    {
        Carbon::setTestNow(Carbon::parse($creado_a));

        $estado = OrderStatus::first();
        $comprador = is_null($comprador) ? $this->comprador : $comprador;

        $pedido = Order::create(array_merge([
            'num'             => $num,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => is_null($estado) ? 1 : $estado->id,
            'user_id'         => $this->comercio->id,
            'total'           => 0,
        ], $columnas));

        foreach ($renglones as $renglon) {

            $articulo = Article::create(['name' => $renglon[0], 'user_id' => $this->comercio->id, 'status' => 'active']);
            $pedido->articles()->attach($articulo->id, ['price' => $renglon[1], 'amount' => $renglon[2]]);
        }

        Carbon::setTestNow(Carbon::parse(self::AHORA));

        return $pedido->fresh();
    }

    /**
     * El pedido N° 78 del CA1: «Malbec Reserva» 94.000 × 2, retiro por local, pagado con Mercado Pago.
     *
     * @return \App\Models\Order
     */
    protected function pedido_78()
    {
        $metodo = PaymentMethod::create(['name' => 'Mercado Pago', 'user_id' => $this->comercio->id]);

        return $this->pedido(78, [['Malbec Reserva', 94000, 2]], ['payment_method_id' => $metodo->id]);
    }

    /**
     * Los ítems de pedido (uno por pedido) que ofrece `recordatorios-pendientes`.
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

    /**
     * El ítem de un pedido puntual.
     *
     * @param  \App\Models\Order  $pedido
     * @return array
     */
    protected function item_del_pedido(Order $pedido)
    {
        foreach ($this->items_de_pedidos() as $item) {

            if (strpos($item['id'], AvisosDelDuenoIaHelper::PREFIJO_PEDIDO . $pedido->id . '-') === 0) {
                return $item;
            }
        }

        $this->fail('recordatorios-pendientes no ofrece el pedido N° ' . $pedido->num . '.');
    }

    /* ─────────────────────────────── CA1 ─────────────────────────────── */

    /**
     * 🔴 CA1 — El aviso trae número, comprador, total, entrega, pago y artículos; el `detalle` no cambia.
     * Dueño con Agentes de IA y la casilla por defecto (sin tocar nada más).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_aviso_trae_entrega_pago_y_articulos()
    {
        $this->configuracion_de_la_tienda();
        $this->cursor_de_pedidos_al_dia();
        $pedido = $this->pedido_78();

        $item = $this->item_del_pedido($pedido);

        $this->assertSame($this->texto_del_pedido_78($this->comprador->email), $item['texto']);
        $this->assertSame('Pedido N° 78 de la tienda: Gabriel Domínguez, $188.000', $item['detalle']);
        $this->assertSame('recordatorio:pedido_tienda:' . $pedido->id . '-' . Carbon::parse($pedido->created_at)->timestamp, $item['id']);
    }

    /* ─────────────────────────────── CA2 ─────────────────────────────── */

    /**
     * 🔴 CA2 — Todos los renglones con su precio (hasta 15; el corte se prueba en el test 45) y cada forma de entrega: envío propio con zona, envío
     * a domicilio sin zona, y envío por correo (envio_opcion sin proveedor), que gana sobre `deliver`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function muestra_los_renglones_y_cada_forma_de_entrega()
    {
        $this->configuracion_de_la_tienda();
        $this->cursor_de_pedidos_al_dia();

        $zona = DeliveryZone::create(['name' => 'Zona Centro', 'price' => 0, 'user_id' => $this->comercio->id]);

        $renglones = [['A', 1000, 1], ['B', 2500, 2], ['C', 300, 1], ['D', 400, 3], ['E', 50, 10]];

        // Envío propio con zona, sin método de pago: 1000 + 5000 + 300 + 1200 + 500 = 8000.
        $con_zona = $this->pedido(80, $renglones, ['deliver' => 1, 'delivery_zone_id' => $zona->id], '2026-10-16 10:01:00');
        // Envío a domicilio sin zona.
        $sin_zona = $this->pedido(81, [['A', 1000, 1]], ['deliver' => 1], '2026-10-16 10:02:00');
        // Envío por correo: envio_opcion con algo adentro y sin proveedor (es de Zipnova), aunque deliver sea 1.
        $por_correo = $this->pedido(82, [['A', 1000, 1]], ['deliver' => 1, 'envio_opcion' => ['servicio' => 'Correo Argentino']], '2026-10-16 10:03:00');

        $texto = $this->item_del_pedido($con_zona)['texto'];
        $this->assertStringContainsString("Total: *\$8.000*", $texto);
        $this->assertStringContainsString("Entrega: Envío propio: Zona Centro\n", $texto);
        $this->assertStringContainsString("\nPago: no indicado", $texto);
        $this->assertStringContainsString("• A x1 · \$1.000 c/u = \$1.000", $texto);
        $this->assertStringContainsString("• B x2 · \$2.500 c/u = \$5.000", $texto);
        $this->assertStringContainsString("• E x10 · \$50 c/u = \$500", $texto, 'Con 5 renglones van todos (el tope es 15).');
        $this->assertStringNotContainsString('artículos más', $texto);

        $this->assertStringContainsString('Entrega: Envío a domicilio', $this->item_del_pedido($sin_zona)['texto']);
        $this->assertStringContainsString('• A x1 · $1.000 c/u = $1.000', $this->item_del_pedido($sin_zona)['texto']);

        $this->assertStringContainsString('Entrega: Envío por correo', $this->item_del_pedido($por_correo)['texto']);
    }

    /* ─────────────────────────────── CA3 ─────────────────────────────── */

    /**
     * 🔴 CA3 — Fuera de las 24 h el ítem lleva la plantilla de tareas con un `resumen` exacto y corto (aun con un
     * comprador de 150 caracteres: ≤ 400); los ítems de la agenda y de stock cero no llevan plantilla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_item_lleva_la_plantilla_de_tareas_con_un_resumen_corto()
    {
        $this->configuracion_de_la_tienda();
        $this->cursor_de_pedidos_al_dia();
        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_stock_cero'   => 1,
            'agente_aviso_stock_desde'  => '2026-10-16 09:00:00',
            'agente_aviso_stock_cursor' => AvisosDelDuenoIaHelper::ultimo_id('stock_movements'),
        ]);

        $pedido = $this->pedido_78();

        $largo = Buyer::create([
            'name'    => str_repeat('Nombre', 12) . 'abc',   // 75 caracteres
            'surname' => str_repeat('Apellido', 9) . 'abc',  // 75 caracteres
            'email'   => 'largo-wa-' . uniqid() . '@testing.local',
            'user_id' => $this->comercio->id,
        ]);
        $del_largo = $this->pedido(79, [['Malbec Reserva', 94000, 2]], [], '2026-10-16 10:06:00', $largo);

        // Un ítem de agenda y uno de stock cero conviven en la misma lista.
        $this->tarea();
        $articulo = Article::create(['name' => 'Aceite Coratina 500 ml', 'user_id' => $this->comercio->id, 'status' => 'active']);
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 0]);
        DB::table('stock_movements')->insert([
            'article_id' => $articulo->id, 'user_id' => $this->comercio->id, 'concepto' => 'Venta', 'amount' => -3,
            'stock_resultante' => 0, 'created_at' => '2026-10-16 10:10:00', 'updated_at' => '2026-10-16 10:10:00',
        ]);

        $item = $this->item_del_pedido($pedido);

        $this->assertArrayHasKey('plantilla', $item, 'El ítem del pedido tiene que pedir la plantilla de tareas.');
        $this->assertArrayHasKey('resumen', $item, 'El ítem del pedido tiene que traer el resumen para el parámetro.');
        $this->assertSame('tarea_del_asistente', $item['plantilla']);
        $this->assertSame(self::RESUMEN_DEL_PEDIDO_78, $item['resumen']);

        $item_largo = $this->item_del_pedido($del_largo);
        $this->assertArrayHasKey('resumen', $item_largo);
        $resumen_largo = $item_largo['resumen'];
        $this->assertLessThanOrEqual(400, mb_strlen($resumen_largo), 'El parámetro de la plantilla tiene que entrar.');

        $todos = $this->pendientes()->json('recordatorios');
        $sin_plantilla = 0;

        foreach ($todos as $otro) {

            $es_pedido = strpos($otro['id'], AvisosDelDuenoIaHelper::PREFIJO_PEDIDO) === 0;

            if (!$es_pedido) {
                $sin_plantilla++;
                $this->assertArrayNotHasKey('plantilla', $otro, 'Sólo el pedido lleva plantilla: ' . $otro['id']);
            }
        }

        $this->assertSame(2, $sin_plantilla, 'La tarea de la agenda y el stock cero siguen saliendo, sin plantilla.');
    }

    /* ─────────────────────────────── CA4 ─────────────────────────────── */

    /**
     * 🔴 CA4 — Con Agentes de IA y la fila de la tienda recién creada, los pedidos quedan en «al toque» sin que el
     * dueño haya tocado nada por chat.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function prendido_con_agentes_ia_y_tienda_configurada()
    {
        $this->configuracion_de_la_tienda();

        $preferencias = AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh());

        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE, $preferencias['pedidos']);
        $this->assertSame(1, $this->casilla(), 'La casilla nace prendida (default 1).');
    }

    /**
     * 🔴 CA4 — Sin Agentes de IA la misma fila no prende nada. Primero se prueba que CON la extensión sí prende
     * (si no, hoy pasaría vacío).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_agentes_ia_queda_apagado()
    {
        $this->configuracion_de_la_tienda();

        $this->assertSame(
            AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE,
            AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'],
            'Con Agentes de IA y la casilla por defecto el aviso está prendido.'
        );

        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos']);
    }

    /**
     * 🔴 CA4 — Sin fila de configuración de la tienda sigue apagado (como hoy y como exige el test 29); al crear
     * la fila, con Agentes de IA, pasa a «al toque».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_configuracion_de_tienda_sigue_apagado()
    {
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'], 'Sin fila: apagado.');

        $this->configuracion_de_la_tienda();

        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'], 'Con la fila creada: prendido.');
    }

    /* ─────────────────────────────── CA6 ─────────────────────────────── */

    /**
     * Confirma una tarjeta de `proponer_avisos_por_whatsapp` como lo hace el chat (con el sí del dueño).
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $entrada  Lo que pide el modelo.
     * @return void
     */
    protected function confirmar_tarjeta_de_avisos($conversacion, $mensaje, array $entrada)
    {
        $respuesta = json_decode(HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', $entrada, $conversacion, $mensaje)['content'], true);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        PropuestaAvisosDelDuenoIaHelper::ejecutar(ContextoDeCargaIa::de_la_conversacion($conversacion), AiMessageAction::find($respuesta['tarjeta_id']));
    }

    /**
     * 🔴 CA6 — La tarjeta del chat y la casilla dicen lo mismo: pedidos «apagado» deja la casilla en 0; «al toque» u
     * «horas», en 1. Sin fila de configuración, la tarjeta funciona como hoy (y no inventa la fila).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_tarjeta_del_chat_mueve_la_casilla()
    {
        DB::table('users')->where('id', $this->comercio->id)->update(['agente_confianza' => 'cauteloso']);
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'avisame de los pedidos nuevos']);
        $propone = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        // Sin fila: funciona como hoy, queda el modo en el usuario y no se crea ninguna configuración.
        $this->confirmar_tarjeta_de_avisos($conversacion, $propone, ['pedidos_tienda' => 'al_toque']);
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos']);
        $this->assertSame(0, OnlineConfiguration::where('user_id', $this->comercio->id)->count(), 'La tarjeta no inventa la fila de la tienda.');

        // Con fila: «apagado» baja la casilla.
        $this->configuracion_de_la_tienda();
        $this->assertSame(1, $this->casilla());

        $this->confirmar_tarjeta_de_avisos($conversacion, $propone, ['pedidos_tienda' => 'apagado']);
        $this->assertSame(0, $this->casilla(), 'Apagar los pedidos por chat apaga la casilla.');
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos']);

        // «Al toque» la vuelve a subir.
        $this->confirmar_tarjeta_de_avisos($conversacion, $propone, ['pedidos_tienda' => 'al_toque']);
        $this->assertSame(1, $this->casilla(), 'Prender los pedidos por chat prende la casilla.');
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos']);

        // «Horas» también la deja en 1.
        DB::table('online_configurations')->where('user_id', $this->comercio->id)->update(['notificar_pedido_por_whatsapp' => 0]);
        $this->confirmar_tarjeta_de_avisos($conversacion, $propone, ['pedidos_tienda' => 'horas', 'horas' => ['09:00', '21:00']]);
        $this->assertSame(1, $this->casilla(), 'El resumen por horas también es «prendido».');
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_HORAS, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos']);
    }
}
