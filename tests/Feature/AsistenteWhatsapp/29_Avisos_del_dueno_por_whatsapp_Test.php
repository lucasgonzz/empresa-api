<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaAvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Buyer;
use App\Models\ConceptoStockMovement;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Misión asistente-truvari-pedidos-fallidos (C2, 13/11/2026) — LOS AVISOS QUE PIDE EL DUEÑO POR WHATSAPP.
 *
 * Caso real (Truvari, conversación 7): «avisame cuando entre un pedido nuevo en la tienda», «a las 8, 12, 16 y
 * 22 revisá si hay pedidos y avisame» y «cuando un producto llegue a stock cero avisame por este medio». El
 * asistente armó una tarea de agenda que no revisa nada. Lo que protege este archivo:
 *   1. Apagados por defecto: sin preferencias, recordatorios-pendientes es lo de siempre.
 *   2. Pedidos «al toque»: uno por pedido nuevo desde el cursor, con N°, comprador y total (OrderHelper).
 *   3. Pedidos en horas fijas: un resumen por hora con lo entrado desde la hora anterior; sin pedidos, nada.
 *   4. Stock cero: los artículos sin variantes que un movimiento dejó en cero, agrupados, uno por día.
 *   5. El sello: idempotente, deja el mensaje en la conversación una sola vez, 404 para lo ajeno.
 *   6. Vencen como los recordatorios y conviven con ellos en la misma lista, con la forma que el admin lee.
 *   7. La herramienta: tarjeta que espera el sí, arranca el cursor al confirmar y avisa del canal.
 *
 * El reloj está fijo (RecordatoriosTestCase): viernes 16/10/2026 a las 10:30.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Avisos_del_dueno_por_whatsapp_Test extends RecordatoriosTestCase
{
    /** @var Buyer */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comprador = Buyer::create([
            'name'    => 'Gabriel',
            'surname' => 'Domínguez',
            'email'   => 'comprador-c2-' . uniqid() . '@testing.local',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Prende los avisos del dueño. Sin cursor dado, arranca donde arranca la tarjeta: el id más alto de la tabla
     * en ese momento.
     */
    protected function preferencias(array $columnas)
    {
        if (isset($columnas['agente_aviso_pedidos']) && !array_key_exists('agente_aviso_pedidos_cursor', $columnas)) {
            $columnas['agente_aviso_pedidos_cursor'] = AvisosDelDuenoIaHelper::ultimo_id('orders');
        }

        if (!empty($columnas['agente_aviso_stock_cero']) && !array_key_exists('agente_aviso_stock_cursor', $columnas)) {
            $columnas['agente_aviso_stock_cursor'] = AvisosDelDuenoIaHelper::ultimo_id('stock_movements');
        }

        DB::table('users')->where('id', $this->comercio->id)->update($columnas);
    }

    /** Un cursor del dueño, tal como quedó en la base. */
    protected function cursor($columna)
    {
        return DB::table('users')->where('id', $this->comercio->id)->value($columna);
    }

    /**
     * Un pedido de la tienda, creado a esa hora, con un renglón.
     *
     * @return Order
     */
    protected function pedido($num, $creado_a, $precio = 94000, $cantidad = 2)
    {
        Carbon::setTestNow(Carbon::parse($creado_a));

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

        $articulo = Article::create(['name' => 'Vino del pedido ' . $num, 'user_id' => $this->comercio->id, 'status' => 'active']);
        $pedido->articles()->attach($articulo->id, ['price' => $precio, 'amount' => $cantidad]);

        Carbon::setTestNow(Carbon::parse(self::AHORA));

        return $pedido->fresh();
    }

    /**
     * Un artículo con su stock y un movimiento que lo bajó a esa hora (de 3 unidades: antes tenía stock).
     *
     * @return Article
     */
    protected function sin_stock($nombre, $movido_a, $stock = 0, array $del_movimiento = [])
    {
        $articulo = Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => $stock]);

        $this->movimiento($articulo, $movido_a, array_merge(['amount' => -3, 'stock_resultante' => $stock], $del_movimiento));

        return $articulo;
    }

    /** Un movimiento de stock de un artículo; devuelve su id. */
    protected function movimiento(Article $articulo, $movido_a, array $columnas)
    {
        return (int) DB::table('stock_movements')->insertGetId(array_merge([
            'article_id' => $articulo->id,
            'user_id'    => $this->comercio->id,
            'concepto'   => 'Venta',
            'created_at' => $movido_a,
            'updated_at' => $movido_a,
        ], $columnas));
    }

    /** Solo los ítems de los avisos del dueño (los de la Agenda tienen tarea_id). */
    protected function avisos_del_dueno()
    {
        $respuesta = $this->pendientes();
        $respuesta->assertStatus(200);

        return array_values(array_filter($respuesta->json('recordatorios'), function ($item) {
            return AvisosDelDuenoIaHelper::es_id_propio($item['id']);
        }));
    }

    // ------------------------------------------------------------------------------------ 1

    /**
     * 🔴 Apagados por defecto: con pedidos y stock en cero en el día, sin preferencias no sale nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function apagados_por_defecto_no_sale_nada()
    {
        $this->pedido(78, '2026-10-16 10:00:00');
        $this->sin_stock('Aceite Coratina 500 ml', '2026-10-16 10:10:00');
        $tarea = $this->tarea();

        $preferencias = AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh());
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, $preferencias['pedidos']);
        $this->assertFalse($preferencias['stock_cero']);

        $this->assertSame([], $this->avisos_del_dueno());
        $this->assertSame([(int) $tarea->id], $this->tareas_pendientes(), 'Los recordatorios de siempre, igual.');
    }

    // ------------------------------------------------------------------------------------ 2

    /**
     * 🔴 Al toque: uno por pedido nuevo desde el cursor (el de antes de prenderlo no), con el total del pedido.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function al_toque_avisa_cada_pedido_nuevo_desde_el_cursor()
    {
        $this->pedido(76, '2026-10-16 09:00:00');
        $this->preferencias(['agente_aviso_pedidos' => 'al_toque', 'agente_aviso_pedidos_desde' => '2026-10-16 09:30:00']);
        $nuevo = $this->pedido(78, '2026-10-16 10:05:00');

        $avisos = $this->avisos_del_dueno();

        $this->assertCount(1, $avisos, 'El pedido anterior al cursor no se avisa.');
        $this->assertSame('recordatorio:pedido_tienda:' . $nuevo->id . '-' . Carbon::parse($nuevo->created_at)->timestamp, $avisos[0]['id']);
        $this->assertSame('2026-10-16', $avisos[0]['fecha']);
        $this->assertSame('10:05', $avisos[0]['hora']);
        $this->assertSame('Pedido N° 78 de la tienda: Gabriel Domínguez, $188.000', $avisos[0]['detalle']);
        $this->assertStringContainsString('*N° 78* de Gabriel Domínguez, por $188.000', $avisos[0]['texto']);
        $this->assertNull($avisos[0]['tarea_id']);
        $this->assertSame('/online', $avisos[0]['ruta']);

        // La forma que lee el admin (AsistenteRecordatoriosService::traer_recordatorios): fecha y hora con su patrón.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $avisos[0]['fecha']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $avisos[0]['hora']);
        $this->assertNotSame('', trim($avisos[0]['texto']));
    }

    /**
     * Vencen como los recordatorios: un pedido de hace más de HORAS_DE_GRACIA ya no se ofrece.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_aviso_de_un_pedido_vence_como_un_recordatorio()
    {
        $this->preferencias(['agente_aviso_pedidos' => 'al_toque', 'agente_aviso_pedidos_desde' => '2026-10-16 06:00:00']);
        $vencido = $this->pedido(70, '2026-10-16 07:00:00');

        $this->assertSame([], $this->avisos_del_dueno());
        $this->assertSame((int) $vencido->id, (int) $this->cursor('agente_aviso_pedidos_cursor'), 'El vencido queda atrás del cursor.');
    }

    /**
     * 🔴 D4: con el cursor por id, lo sellado queda atrás y lo que se lee después es siempre lo nuevo; un vencido
     * del principio se saltea sin tapar a los nuevos.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function al_toque_el_cursor_avanza_al_sellar_y_los_nuevos_no_se_atrasan()
    {
        $this->preferencias(['agente_aviso_pedidos' => 'al_toque', 'agente_aviso_pedidos_desde' => '2026-10-16 06:00:00']);
        $this->pedido(70, '2026-10-16 07:00:00'); // vencido
        $primero = $this->pedido(71, '2026-10-16 09:00:00');
        $segundo = $this->pedido(72, '2026-10-16 10:00:00');

        $ids = array_column($this->avisos_del_dueno(), 'id');
        $this->assertCount(2, $ids);

        $this->avisado($ids[0])->assertStatus(200);
        $this->assertSame((int) $primero->id, (int) $this->cursor('agente_aviso_pedidos_cursor'));

        $despues = $this->avisos_del_dueno();
        $this->assertCount(1, $despues);
        $this->assertStringStartsWith('recordatorio:pedido_tienda:' . $segundo->id . '-', $despues[0]['id']);
    }

    // ------------------------------------------------------------------------------------ 3

    /**
     * 🔴 Horas fijas (8 y 10 en este reloj): a las 10 un resumen con lo entrado desde las 8; la de las 8 no tiene
     * pedidos y no manda nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_horas_fijas_manda_un_resumen_con_lo_entrado_desde_la_hora_anterior()
    {
        $this->preferencias([
            'agente_aviso_pedidos'       => 'horas',
            'agente_aviso_pedidos_horas' => '08:00,10:00,16:00,22:00',
            'agente_aviso_pedidos_desde' => '2026-10-16 07:00:00',
        ]);

        $this->pedido(77, '2026-10-16 08:30:00', 48000, 1);
        $this->pedido(78, '2026-10-16 09:45:00', 94000, 2);
        $this->pedido(79, '2026-10-16 10:15:00', 10000, 1); // después de las 10: va en el de las 16.

        $avisos = $this->avisos_del_dueno();

        $this->assertCount(1, $avisos);
        $this->assertSame('recordatorio:pedidos_tienda:' . Carbon::parse('2026-10-16 07:00:00')->timestamp . ':2026-10-16:1000', $avisos[0]['id']);
        $this->assertSame('10:00', $avisos[0]['hora']);
        $this->assertSame('2 pedidos nuevos en la tienda desde las 08:00, $236.000 en total', $avisos[0]['detalle']);
        $this->assertStringContainsString('- N° 77 de Gabriel Domínguez, $48.000', $avisos[0]['texto']);
        $this->assertStringContainsString('- N° 78 de Gabriel Domínguez, $188.000', $avisos[0]['texto']);
        $this->assertStringNotContainsString('N° 79', $avisos[0]['texto']);

        // Sellado, no vuelve a salir.
        $this->avisado($avisos[0]['id'])->assertStatus(200);
        $this->assertSame([], $this->avisos_del_dueno());
    }

    /**
     * Sin horas cargadas el modo de horas fijas no manda nada (queda como apagado).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_horas_fijas_sin_horas_no_manda_nada()
    {
        $this->preferencias(['agente_aviso_pedidos' => 'horas', 'agente_aviso_pedidos_horas' => null, 'agente_aviso_pedidos_desde' => '2026-10-16 07:00:00']);
        $this->pedido(77, '2026-10-16 09:00:00');

        $this->assertSame([], $this->avisos_del_dueno());
        $this->assertSame(['08:00', '12:00', '16:00', '22:00'], AvisosDelDuenoIaHelper::normalizar_horas('8, 12, 16 y 22'));
        $this->assertSame(['08:30', '22:00'], AvisosDelDuenoIaHelper::normalizar_horas(['22:00', '8:30', '25:00', 'cualquiera']));
    }

    // ------------------------------------------------------------------------------------ 4

    /**
     * 🔴 Stock cero: los artículos sin variantes que un movimiento pasó de tener stock a no tenerlo, en UN solo
     * aviso cuyo id es el del último movimiento incluido; el que tiene variantes, el que repuso stock y el movido
     * antes de prender el aviso, no. Ya en cero, otra venta no vuelve a avisar.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function stock_cero_agrupa_los_articulos_que_se_quedaron_sin_stock()
    {
        $this->sin_stock('Antes del cursor', '2026-10-16 08:50:00');
        $this->preferencias(['agente_aviso_stock_cero' => 1, 'agente_aviso_stock_desde' => '2026-10-16 09:00:00']);

        $coratina = $this->sin_stock('Aceite Coratina 500 ml', '2026-10-16 10:10:00');
        $this->sin_stock('Malbec Reserva', '2026-10-16 10:20:00', -1);
        $repuesto = $this->sin_stock('Repuesto después', '2026-10-16 10:00:00');
        DB::table('articles')->where('id', $repuesto->id)->update(['stock' => 5]);
        $con_variantes = $this->sin_stock('Remera con talles', '2026-10-16 10:00:00');
        ArticleVariant::create(['article_id' => $con_variantes->id, 'variant_description' => 'Talle M']);
        $ultimo = (int) DB::table('stock_movements')->max('id');

        $avisos = $this->avisos_del_dueno();

        $this->assertCount(1, $avisos);
        $this->assertSame('recordatorio:stock_cero:' . $ultimo, $avisos[0]['id']);
        $this->assertSame('10:20', $avisos[0]['hora']);
        $this->assertSame('2 artículos sin stock: Aceite Coratina 500 ml y Malbec Reserva', $avisos[0]['detalle']);
        $this->assertSame("📦 Se quedaron sin stock: Aceite Coratina 500 ml y Malbec Reserva.\nSi querés, te ayudo a reponerlos.", $avisos[0]['texto']);

        // Sellado avanza el cursor; otra venta con el artículo ya en cero no es una transición.
        $this->avisado($avisos[0]['id'])->assertStatus(200);
        $this->assertSame($ultimo, (int) $this->cursor('agente_aviso_stock_cursor'));
        $this->movimiento($coratina, '2026-10-16 10:25:00', ['amount' => -1, 'stock_resultante' => -1]);
        $this->assertSame([], $this->avisos_del_dueno());
        $this->assertSame(1, AiAvisoDelAsistente::where('owner_id', $this->comercio->id)->where('tipo', AiAvisoDelAsistente::TIPO_STOCK_CERO)->count());
    }

    /**
     * 🔴 D1: un artículo que queda en cero a las 23:55 y se avisa no vuelve a avisarse a las 00:05.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_stock_cero_de_las_23_55_no_se_repite_despues_de_medianoche()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 23:55:00'));
        $this->preferencias(['agente_aviso_stock_cero' => 1, 'agente_aviso_stock_desde' => '2026-10-16 20:00:00']);
        $this->sin_stock('Aceite', '2026-10-16 23:50:00');

        $avisos = $this->avisos_del_dueno();
        $this->assertCount(1, $avisos);
        $this->avisado($avisos[0]['id'])->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-10-17 00:05:00'));
        $this->assertSame([], $this->avisos_del_dueno());
    }

    /**
     * 🔴 D2: sin transición no hay aviso. Un artículo que ya estaba en negativo, una importación de Excel y la
     * actualización masiva del listado no avisan.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_transicion_ni_por_importacion_ni_por_masiva_no_avisa()
    {
        $this->preferencias(['agente_aviso_stock_cero' => 1, 'agente_aviso_stock_desde' => '2026-10-16 09:00:00']);

        $negativo = Article::create(['name' => 'Sin control de stock', 'user_id' => $this->comercio->id, 'status' => 'active']);
        DB::table('articles')->where('id', $negativo->id)->update(['stock' => -11]);
        $this->movimiento($negativo, '2026-10-16 10:00:00', ['amount' => -1, 'stock_resultante' => -11]);

        $importacion = ConceptoStockMovement::where('name', 'Importacion de excel')->first();
        if (is_null($importacion)) {
            $importacion = new ConceptoStockMovement();
            $importacion->name = 'Importacion de excel';
            $importacion->save();
        }
        $this->sin_stock('Importado en cero', '2026-10-16 10:05:00', 0, ['concepto_stock_movement_id' => $importacion->id, 'concepto' => 'Importacion de excel']);
        $this->sin_stock('Masiva en cero', '2026-10-16 10:06:00', 0, ['observations' => 'Actualizacion masiva del listado (set)', 'concepto' => 'Ingreso manual']);

        $this->assertSame([], $this->avisos_del_dueno());
    }

    /**
     * 🔴 D2: 221 artículos que se quedan sin stock en la misma tanda son UN solo aviso (fuera de las 24 h cada
     * ítem es una plantilla paga).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function muchos_articulos_en_cero_son_un_solo_aviso()
    {
        $this->preferencias(['agente_aviso_stock_cero' => 1, 'agente_aviso_stock_desde' => '2026-10-16 09:00:00']);

        for ($i = 0; $i < 221; $i++) {
            $this->sin_stock(sprintf('Art %03d', $i), '2026-10-16 10:00:00');
        }

        $avisos = $this->avisos_del_dueno();

        $this->assertCount(1, $avisos);
        $this->assertStringStartsWith('221 artículos sin stock: Art 000, Art 001', $avisos[0]['detalle']);
        $this->assertStringEndsWith('Art 009 y 211 más', $avisos[0]['detalle']);
    }

    /**
     * 🔴 D5: el id ofrecido no cambia si entra otra transición antes del sello (el admin ve siempre el mismo
     * id y el mismo texto); lo nuevo sale después, en su propio aviso.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_aviso_de_stock_ofrecido_no_cambia_hasta_que_se_sella()
    {
        $this->preferencias(['agente_aviso_stock_cero' => 1, 'agente_aviso_stock_desde' => '2026-10-16 09:00:00']);
        $this->sin_stock('Aceite', '2026-10-16 10:00:00');

        $primero = $this->avisos_del_dueno();
        $this->assertCount(1, $primero);

        $this->sin_stock('Malbec', '2026-10-16 10:10:00');
        $ultimo = (int) DB::table('stock_movements')->max('id');

        $segundo = $this->avisos_del_dueno();
        $this->assertSame($primero, $segundo, 'Mismo id y mismo texto hasta el sello.');

        $this->avisado($primero[0]['id'])->assertStatus(200);
        $tercero = $this->avisos_del_dueno();

        $this->assertCount(1, $tercero);
        $this->assertSame('recordatorio:stock_cero:' . $ultimo, $tercero[0]['id']);
        $this->assertSame('Sin stock: Malbec', $tercero[0]['detalle']);
    }

    // ------------------------------------------------------------------------------------ 5

    /**
     * 🔴 El sello: idempotente, un solo mensaje en la conversación, y 404 para lo ajeno o mal formado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_sello_es_idempotente_y_deja_un_solo_mensaje()
    {
        $conversacion = $this->conversacion_whatsapp(now()->subMinutes(30));
        $this->preferencias(['agente_aviso_pedidos' => 'al_toque', 'agente_aviso_pedidos_desde' => '2026-10-16 09:00:00']);
        $pedido = $this->pedido(78, '2026-10-16 10:05:00');

        $avisos = $this->avisos_del_dueno();
        $this->assertSame((int) $conversacion->id, $avisos[0]['ai_conversation_id']);

        $this->avisado($avisos[0]['id'], 'wamid.PEDIDO78')->assertStatus(200);
        $this->avisado($avisos[0]['id'], 'wamid.PEDIDO78')->assertStatus(200);

        $mensajes = AiMessage::where('ai_conversation_id', $conversacion->id)->where('whatsapp_message_id', 'wamid.PEDIDO78')->get();
        $this->assertCount(1, $mensajes, 'Un reintento del admin no deja dos mensajes.');
        $this->assertSame($avisos[0]['texto'], $mensajes[0]->contenido, 'Queda lo que salió.');
        $this->assertSame([], $this->avisos_del_dueno());

        // Lo ajeno o mal formado: el 404 del contrato.
        $this->avisado('recordatorio:pedido_tienda:' . $pedido->id . '-1')->assertStatus(404);
        $this->avisado('recordatorio:pedido_tienda:999999999-1')->assertStatus(404);
        $this->avisado('recordatorio:stock_cero:2026-10-16:abc')->assertStatus(404);
        $this->avisado('recordatorio:pedidos_tienda:1:2026-10-17:1000')->assertStatus(404, 'Un día que no llegó.');

        $otro = $this->otro_dueno();
        $ajeno = Article::create(['name' => 'De otro', 'user_id' => $otro->id, 'status' => 'active']);
        $movimiento_ajeno = (int) DB::table('stock_movements')->insertGetId([
            'article_id' => $ajeno->id, 'user_id' => $otro->id, 'amount' => -1, 'stock_resultante' => 0, 'created_at' => self::AHORA, 'updated_at' => self::AHORA,
        ]);
        $this->avisado('recordatorio:stock_cero:' . $movimiento_ajeno)->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------ 6

    /**
     * Conviven con los recordatorios de la Agenda en la misma lista, ordenados por momento.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function conviven_con_los_recordatorios_ordenados_por_momento()
    {
        $tarea = $this->tarea(); // 10:00
        $this->preferencias(['agente_aviso_pedidos' => 'al_toque', 'agente_aviso_pedidos_desde' => '2026-10-16 09:00:00']);
        $this->pedido(75, '2026-10-16 09:40:00');
        $this->pedido(78, '2026-10-16 10:05:00');

        $respuesta = $this->pendientes();
        $respuesta->assertStatus(200);
        $lista = $respuesta->json('recordatorios');

        $this->assertCount(3, $lista);
        $this->assertSame(['09:40', '10:00', '10:05'], array_column($lista, 'hora'));
        $this->assertSame((int) $tarea->id, (int) $lista[1]['tarea_id']);

        // El id de la tarea se sigue sellando como siempre.
        $this->avisado($lista[1]['id'])->assertStatus(200);
        $this->assertNotNull($tarea->fresh()->avisado_at);
    }

    // ------------------------------------------------------------------------------------ 7

    /**
     * 🔴 La herramienta: deja la tarjeta esperando el sí (no toca nada), y con el sí guarda las horas y arranca
     * el cursor en ese momento. Sin el anuncio del admin, lo dice.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_herramienta_deja_la_tarjeta_y_con_el_si_prende_los_avisos()
    {
        DB::table('users')->where('id', $this->comercio->id)->update(['agente_confianza' => 'cauteloso']);

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'revisá a las 8, 12, 16 y 22 si hay pedido nuevo en la tienda y avisame; y avisame cuando algo llegue a stock cero']);
        $propone = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $resultado = HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', [
            'pedidos_tienda' => 'horas',
            'horas'          => ['8:00', '12:00', '16:00', '22:00'],
            'stock_cero'     => true,
        ], $conversacion, $propone);

        $respuesta = json_decode($resultado['content'], true);
        $this->assertTrue($respuesta['ok'], $resultado['content']);
        $this->assertSame(['08:00', '12:00', '16:00', '22:00'], $respuesta['horas']);
        $this->assertSame(PropuestaAvisosDelDuenoIaHelper::AVISO_SIN_ADMIN, $respuesta['aviso'], 'Sin el anuncio del admin no se promete.');
        $this->assertStringContainsString('Todavía NO está hecho', $respuesta['nota']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::TIPO_AVISOS_DEL_DUENO, $tarjeta->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'], 'Sin el sí no cambia nada.');

        // El admin consulta, con plantilla: el sí prende los avisos desde ahora.
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');
        $propone->estado = 'listo';
        $propone->contenido = 'Te dejo los avisos. ¿Los prendo?';
        $propone->save();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'sí']);
        $confirma = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $confirmado = json_decode(HerramientasDeCarga::ejecutar('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta->id], $conversacion, $confirma)['content'], true);
        $this->assertTrue($confirmado['ok'], json_encode($confirmado, JSON_UNESCAPED_UNICODE));

        $preferencias = AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh());
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_HORAS, $preferencias['pedidos']);
        $this->assertSame(['08:00', '12:00', '16:00', '22:00'], $preferencias['horas']);
        $this->assertTrue($preferencias['stock_cero']);
        $this->assertSame(self::AHORA, $preferencias['pedidos_desde']->format('Y-m-d H:i:s'));
        $this->assertSame(self::AHORA, $preferencias['stock_desde']->format('Y-m-d H:i:s'));
        $this->assertSame(AvisosDelDuenoIaHelper::ultimo_id('orders'), $preferencias['pedidos_cursor'], 'El cursor arranca en el último pedido de ahora.');
        $this->assertSame(AvisosDelDuenoIaHelper::ultimo_id('stock_movements'), $preferencias['stock_cursor']);

        // Cambiar solo las horas no reinicia el cursor ni el desde.
        $this->pedido(90, '2026-10-16 10:29:00');
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:45:00'));
        $horas = json_decode(HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', ['horas' => ['09:00', '21:00']], $conversacion, $confirma)['content'], true);
        PropuestaAvisosDelDuenoIaHelper::ejecutar(\App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa::de_la_conversacion($conversacion), AiMessageAction::find($horas['tarjeta_id']));
        $despues = AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh());
        $this->assertSame(['09:00', '21:00'], $despues['horas']);
        $this->assertSame($preferencias['pedidos_cursor'], $despues['pedidos_cursor']);
        $this->assertSame(self::AHORA, $despues['pedidos_desde']->format('Y-m-d H:i:s'));
        Carbon::setTestNow(Carbon::parse(self::AHORA));

        // Apagar los pedidos deja el stock como estaba.
        $tarjeta2 = json_decode(HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', ['pedidos_tienda' => 'apagado'], $conversacion, $confirma)['content'], true);
        $accion = AiMessageAction::find($tarjeta2['tarjeta_id']);
        $ejecutado = PropuestaAvisosDelDuenoIaHelper::ejecutar(\App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa::de_la_conversacion($conversacion), $accion);
        $this->assertStringContainsString('Pedidos nuevos de la tienda: apagado', $ejecutado['texto']);
        $this->assertStringContainsString('Artículos sin stock: prendido', $ejecutado['texto']);

        $preferencias = AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh());
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, $preferencias['pedidos']);
        $this->assertNull($preferencias['pedidos_desde']);
        $this->assertNull($preferencias['pedidos_cursor']);
        $this->assertTrue($preferencias['stock_cero']);
    }

    /**
     * Sin plantilla, la herramienta avisa que fuera de las 24 h no salen; sin nada que cambiar, pregunta. En
     * "directo" (el default de un negocio nuevo) se hace en el acto, como una tarea, y el resultado lo dice.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_plantilla_avisa_las_24_horas_y_sin_datos_pregunta()
    {
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '0');
        DB::table('users')->where('id', $this->comercio->id)->update(['agente_confianza' => 'resuelto']);

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'avisame cuando entre un pedido nuevo en la tienda']);
        $propone = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = json_decode(HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', ['pedidos_tienda' => 'al_toque'], $conversacion, $propone)['content'], true);
        $this->assertSame(PropuestaAvisosDelDuenoIaHelper::AVISO_SIN_PLANTILLA, $respuesta['aviso']);
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_APAGADO, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'], 'En "resuelto" espera el sí.');

        DB::table('users')->where('id', $this->comercio->id)->update(['agente_confianza' => 'directo']);
        $directo = HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', ['pedidos_tienda' => 'al_toque'], $conversacion, $propone)['content'];
        $this->assertStringContainsString(PropuestaAvisosDelDuenoIaHelper::AVISO_SIN_PLANTILLA, json_decode($directo, true) === null ? $directo : json_encode(json_decode($directo, true), JSON_UNESCAPED_UNICODE));
        $this->assertSame(AvisosDelDuenoIaHelper::PEDIDOS_AL_TOQUE, AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['pedidos'], 'En "directo" se hace en el acto.');

        $vacia = json_decode(HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', [], $conversacion, $propone)['content'], true);
        $this->assertFalse($vacia['ok']);
        $this->assertNotEmpty($vacia['faltan']);

        $sin_horas = json_decode(HerramientasDeCarga::ejecutar('proponer_avisos_por_whatsapp', ['pedidos_tienda' => 'horas'], $conversacion, $propone)['content'], true);
        $this->assertFalse($sin_horas['ok']);
        $this->assertStringContainsString('a qué horas', $sin_horas['faltan'][0]);
    }
}
