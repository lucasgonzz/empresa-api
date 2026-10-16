<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Combo;
use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotSendService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes (16/10/2026) — proponer_promocion_por_whatsapp.
 *
 * El caso real (S13 del registro del 16/10): "armá un combo y mandales una promo a todos con el link a
 * la tienda". Lo que protege este archivo:
 *  - el link NUNCA se inventa: el del artículo exige que esté publicado en la tienda y con slug; el del
 *    combo es la portada (y el combo despublicado se publica al confirmar, la tarjeta lo avisa); sin
 *    tienda no hay promo de un artículo;
 *  - los dos topes de Lucas: 250 por negocio en 24 horas y una por cliente cada 7 días, con el motivo
 *    de cada salteado;
 *  - por cliente: ventana abierta → texto libre "Hola {nombre}! …"; cerrada → `cc_cli_promocion`
 *    aprobada, con las variables limpias de saltos de línea (Meta las rechaza); si no, salteado;
 *  - solo el dueño o un administrador; nunca sola en "directo"; con el sí sale (simulado) y cuenta
 *    para los topes (`source = 'promocion'`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Promocion_por_whatsapp_del_asistente_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function articulo_publicado($nombre, $slug, $online = 1)
    {
        return Article::create([
            'name'        => $nombre,
            'slug'        => $slug,
            'online'      => $online,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 10000,
            'cost'        => 5000,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    /**
     * Las promociones que salieron de este negocio.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function promociones_enviadas()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', PropuestaPromocionWhatsappIaHelper::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * 🔴 S13 con un artículo: el link es el de la tienda, cada cliente por su canal, y el texto exacto
     * de los dos caminos se muestra antes del sí. No sale sola en "directo".
     *
     * @test
     */
    public function la_promo_de_un_articulo_lleva_su_link_y_muestra_los_dos_caminos()
    {
        $articulo = $this->articulo_publicado('ZZE Taladro Percutor 750W', 'zze-taladro-percutor-750w');

        $abierta = $this->cliente('Ana Gomez', '5493444101010');
        $this->chat_abierto($abierta);

        $this->cliente('Bruno Diaz', '5493444202020');
        $this->cliente('Sin Telefono', null);

        list($conversation, $assistant) = $this->vuelta('mandales una promo del taladro a todos');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'      => "Taladro percutor 750W a \$ 89.990\ncon envío gratis",
            'article_id' => $articulo->id,
            'vigencia'   => 'hasta el domingo',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Se ejecutó solo en directo.');
        $this->assertSame(DestinatariosIaHelper::COMO_MOSTRARLO, $respuesta['nota']);

        $link = 'https://tienda-zze.test/articulos/zze-taladro-percutor-750w/' . $this->dueno->id;

        $mostrar = $respuesta['para_mostrar'];

        // Por defecto, todos los que tienen teléfono (el que no tiene, ni aparece).
        $this->assertSame('Ana Gomez y Bruno Diaz', $mostrar['destinatarios']);
        $this->assertSame(2, $mostrar['cantidad']);

        // Ventana abierta: texto libre con el nombre, la vigencia que dijo el dueño y el link.
        $this->assertStringContainsString("Hola Ana Gomez! Taladro percutor 750W a \$ 89.990\ncon envío gratis\nVálida hasta el domingo.\n" . $link, $mostrar['texto_que_sale']);
        // Ventana cerrada: la plantilla de marketing, con el detalle en una línea (sin el salto).
        $this->assertStringContainsString('Hola Bruno Diaz! En ZZE Ferreteria Envios tenemos una promo para vos: Taladro percutor 750W a $ 89.990 con envío gratis. Miralo en ' . $link, $mostrar['texto_que_sale']);
        $this->assertStringContainsString('Válida hasta el domingo.', $mostrar['texto_que_sale']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::TIPO_PROMOCION_WHATSAPP, $tarjeta->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame($link, $tarjeta->datos['link']);

        $this->assertCount(0, $this->promociones_enviadas(), 'Salió sin el sí.');

        // Con el sí, sale a los dos, cada uno por su camino, marcada como promoción.
        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertSame(2, $confirmada['detalle']['encolados']);

        $enviadas = $this->promociones_enviadas();

        $this->assertCount(2, $enviadas);

        $por_body = $enviadas->keyBy(function ($mensaje) {
            return strpos($mensaje->body, 'Ana Gomez') !== false ? 'ana' : 'bruno';
        });

        $this->assertNull($por_body['ana']->template_meta_name);
        $this->assertSame('cc_cli_promocion', $por_body['bruno']->template_meta_name);
        $this->assertStringNotContainsString("\n", $por_body['bruno']->body, 'Una variable con salto de línea la rechaza Meta.');

        foreach ($enviadas as $mensaje) {
            $this->assertTrue(WhatsappBotSendService::es_id_simulado($mensaje->wa_message_id));
        }

        Http::assertNothingSent();
    }

    /**
     * Nunca un link inventado: el artículo sin publicar y el negocio sin tienda cortan con un error
     * claro.
     *
     * @test
     */
    public function sin_articulo_publicado_o_sin_tienda_no_hay_promo_de_un_articulo()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        $oculto = $this->articulo_publicado('ZZE Amoladora oculta', 'zze-amoladora-oculta', 0);

        list($conversation, $assistant) = $this->vuelta('mandales una promo de la amoladora');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Amoladora a mitad de precio', 'article_id' => $oculto->id,
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('no está publicado en la tienda', $respuesta['error']);

        User::where('id', $this->dueno->id)->update(['online' => null]);

        $publicado = $this->articulo_publicado('ZZE Taladro', 'zze-taladro');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Taladro a mitad de precio', 'article_id' => $publicado->id,
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('no tiene tienda online cargada', $respuesta['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * S13 con un combo: el link es la portada de la tienda y, si el combo no estaba publicado, la
     * tarjeta lo avisa y al confirmar queda publicado.
     *
     * @test
     */
    public function la_promo_de_un_combo_despublicado_lo_publica_al_confirmar()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        $combo = Combo::create([
            'num'     => 1,
            'name'    => 'ZZE Combo Asado',
            'price'   => 25000,
            'cost'    => 15000,
            'online'  => 0,
            'user_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales una promo del combo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => 'Combo Asado a $ 25.000',
            'combo' => 'ZZE Combo Asado',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame('https://tienda-zze.test', $tarjeta->datos['link']);
        $this->assertStringContainsString('se publica al mandar la promo', $tarjeta->presentacion['aviso']);
        $this->assertSame(0, (int) Combo::find($combo->id)->online, 'Proponer no publica nada.');

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertSame(1, (int) Combo::find($combo->id)->online);
        $this->assertStringContainsString('quedó publicado en la tienda', $confirmada['resultado']);
    }

    /**
     * 🔴 Los dos topes: una promo por cliente cada 7 días, y 250 por negocio en 24 horas.
     *
     * @test
     */
    public function los_topes_de_siete_dias_y_de_250_por_dia()
    {
        $reciente = $this->cliente('Ya Recibio', '5493444101010');
        $chat = $this->chat_abierto($reciente);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => PropuestaPromocionWhatsappIaHelper::SOURCE,
            'body'             => 'Promo de hace tres días',
            'delivery_status'  => 'entregado',
            'created_at'       => now()->subDays(3),
            'updated_at'       => now()->subDays(3),
        ]);

        $this->cliente('Ana Gomez', '5493444202020');
        $this->cliente('Bruno Diaz', '5493444303030');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('Ana Gomez y Bruno Diaz', $respuesta['para_mostrar']['destinatarios']);
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::MOTIVO_RECIBIO_HACE_POCO, $respuesta['para_mostrar']['no_les_llega'][0]['motivo']);
        $this->assertSame('Ya Recibio', $respuesta['para_mostrar']['no_les_llega'][0]['clientes']);

        // 249 promociones ya salieron hoy (de otro chat del negocio): queda lugar para UNA.
        $otro_chat = WhatsappChat::create([
            'user_id' => $this->dueno->id, 'phone' => '5493444999999', 'ai_enabled' => false, 'unread_count' => 0,
        ]);

        $filas = [];

        for ($i = 0; $i < 249; $i++) {
            $filas[] = [
                'whatsapp_chat_id' => $otro_chat->id,
                'direction'        => 'out',
                'source'           => PropuestaPromocionWhatsappIaHelper::SOURCE,
                'body'             => 'promo ' . $i,
                'delivery_status'  => 'entregado',
                'created_at'       => now()->subHours(2),
                'updated_at'       => now()->subHours(2),
            ];
        }

        DB::table('whatsapp_chat_messages')->insert($filas);

        $this->assertSame(249, PropuestaPromocionWhatsappIaHelper::enviadas_en_24_horas($this->dueno->id));

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local, hoy']);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(1, $respuesta['para_mostrar']['cantidad']);

        $motivos = array_column($respuesta['para_mostrar']['no_les_llega'], 'mensaje', 'motivo');

        $this->assertArrayHasKey(PropuestaPromocionWhatsappIaHelper::MOTIVO_TOPE, $motivos);
        $this->assertStringContainsString('tope de 250 promociones por día', $motivos[PropuestaPromocionWhatsappIaHelper::MOTIVO_TOPE]);
    }

    /**
     * 🔴 CORRECCIÓN 5: los topes cuentan lo RESERVADO. Con los jobs todavía sin correr (la cola no
     * despacha), la promo confirmada ya ocupa sus clientes: la segunda no les vuelve a escribir.
     *
     * @test
     */
    public function el_cliente_de_una_promo_confirmada_no_entra_en_la_segunda()
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->cliente('Ana Gomez', '5493444202020');
        $this->cliente('Bruno Diaz', '5493444303030');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $primera['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertCount(0, $this->promociones_enviadas(), 'Con la cola falsa todavía no salió nada.');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendPromocionWhatsappJob::class, 2);

        $this->cliente('Carla Ruiz', '5493444404040');

        list($otra, $assistant_otra) = $this->vuelta('mandales otra promo');

        $segunda = $this->herramienta($otra, $assistant_otra, 'proponer_promocion_por_whatsapp', ['texto' => '2x1 en tornillos']);

        $this->assertTrue(!empty($segunda['ok']), json_encode($segunda));
        $this->assertSame('Carla Ruiz', $segunda['para_mostrar']['destinatarios']);
        $this->assertSame(PropuestaPromocionWhatsappIaHelper::MOTIVO_RECIBIO_HACE_POCO, $segunda['para_mostrar']['no_les_llega'][0]['motivo']);
        $this->assertSame(2, $segunda['para_mostrar']['no_les_llega'][0]['cantidad']);
    }

    /**
     * 🔴 CORRECCIÓN 5: dos promos confirmadas seguidas no pasan de 250 en 24 horas, aunque la primera no
     * haya mandado nada todavía.
     *
     * @test
     */
    public function dos_promos_confirmadas_seguidas_no_pasan_de_250()
    {
        \Illuminate\Support\Facades\Queue::fake();

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        // Una promo ya confirmada con 249 destinatarios (ids que no son de este negocio: alcanza con la reserva).
        AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_PROMOCION_WHATSAPP,
            'clave'              => 'promocion_whatsapp:reserva-p76',
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'datos'              => ['client_ids' => range(900000001, 900000249)],
            'presentacion'       => ['titulo' => 'Promo anterior', 'renglones' => []],
            'resuelta_at'        => now()->subHour(),
        ]);

        $this->cliente('Ana Gomez', '5493444202020');
        $this->cliente('Bruno Diaz', '5493444303030');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(1, $respuesta['para_mostrar']['cantidad']);

        $motivos = array_column($respuesta['para_mostrar']['no_les_llega'], 'motivo');

        $this->assertContains(PropuestaPromocionWhatsappIaHelper::MOTIVO_TOPE, $motivos);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame(1, $confirmada['detalle']['encolados'], json_encode($confirmada));
    }

    /**
     * 🔴 CORRECCIÓN 6 (S13 sin Combos): un texto que habla de un combo sin decir cuál no se arma. Y M4:
     * la vigencia corta y el cuerpo de la plantilla medido antes del sí.
     *
     * @test
     */
    public function un_combo_que_no_se_nombra_la_vigencia_larga_y_la_plantilla_larga_cortan()
    {
        $this->cliente('Bruno Diaz', '5493444303030');

        list($conversation, $assistant) = $this->vuelta('mandales una promo del combo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => 'Llevá el Combo Asado a $ 25.000']);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('habla de un combo pero no me dijiste cuál', $respuesta['error']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto' => '20% en todo', 'vigencia' => str_repeat('el domingo que viene ', 4),
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('La vigencia es muy larga', $respuesta['error']);

        // Un cliente que no escribió en 24 horas (sale por plantilla) con un nombre largo, un negocio con
        // nombre largo y 684 caracteres de texto: armado, el cuerpo de la plantilla pasa de 1000 y Meta no
        // lo acepta.
        User::where('id', $this->dueno->id)->update(['company_name' => str_repeat('Ferreteria Industrial ', 5)]);

        $largo = $this->cliente('Bruno ' . str_repeat('Diaz Gonzalez ', 7), '5493444909090');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', [
            'texto'      => str_repeat('Oferta increíble. ', 38),
            'client_ids' => [$largo->id],
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('Meta no la acepta', $respuesta['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * La plantilla de promoción sin aprobar: el que no escribió en 24 horas se saltea con el motivo; si
     * no queda nadie, no hay tarjeta.
     *
     * @test
     */
    public function sin_la_plantilla_aprobada_se_saltea_al_que_no_escribio()
    {
        $this->desaprobar_plantilla('cc_cli_promocion');

        $this->cliente('Bruno Diaz', '5493444303030');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('plantilla de promoción todavía no está aprobada', $respuesta['error']);
    }

    /**
     * Solo el dueño o un administrador, y con el módulo de WhatsApp.
     *
     * @test
     */
    public function solo_el_dueno_o_un_administrador_y_con_whatsapp()
    {
        $this->cliente('Ana Gomez', '5493444202020');

        $empleado = User::create([
            'name'     => 'ZZE Empleado',
            'email'    => 'zze-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $conversation->auth_user_id = $empleado->id;
        $conversation->save();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo']);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertSame('Las promociones a los clientes las manda el dueño del negocio o un administrador.', $respuesta['error']);

        $conversation->auth_user_id = $this->dueno->id;
        $conversation->save();

        $this->sacar_extension('whatsapp');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo']);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('módulo de WhatsApp', $respuesta['error']);
    }

    /**
     * Hasta 15 nombres y "y N más".
     *
     * @test
     */
    public function la_lista_muestra_quince_nombres_y_el_resto_contado()
    {
        $nombres = [];

        for ($i = 1; $i <= 18; $i++) {
            $nombres[] = 'Cliente ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }

        $lista = DestinatariosIaHelper::lista_de_nombres($nombres);

        $this->assertStringStartsWith('Cliente 01, Cliente 02', $lista);
        $this->assertStringEndsWith('Cliente 15 y 3 más', $lista);
        $this->assertSame('Ana y Bruno', DestinatariosIaHelper::lista_de_nombres(['Ana', 'Bruno']));
    }
}
