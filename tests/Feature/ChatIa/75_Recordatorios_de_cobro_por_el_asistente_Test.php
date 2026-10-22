<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Models\AiMessageAction;
use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\RecordatorioCobroSenderService;
use App\Services\WhatsappBotSendService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes (16/10/2026) — proponer_recordatorios_de_cobro.
 *
 * El caso real (S12 del registro del 16/10): "mandales un recordatorio a los que me deben más de 200
 * mil hace más de 20 días" no tenía herramienta. Lo que protege este archivo:
 *  - el filtro es EL MISMO de consultar_ventas_sin_cobrar: deuda TOTAL de X o más y alguna venta
 *    impaga de N días o más (un cliente con deuda alta pero toda reciente NO entra; uno con deuda
 *    vieja pero chica tampoco);
 *  - la propuesta dice a quiénes, cuántos, a quiénes no y por qué, y el texto REAL del primero;
 *  - no sale sola en "directo"; con el sí del mensaje siguiente se encola por el mismo camino que la
 *    pantalla (un SendRecordatorioCobroJob por cliente) y sale (en simulado);
 *  - el freno de 24 h, el permiso y la extensión siguen mandando.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Recordatorios_de_cobro_por_el_asistente_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Los mensajes de recordatorio que salieron de este negocio.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function recordatorios_enviados()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', RecordatorioCobroSenderService::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->get();
    }

    /**
     * 🔴 S12: "los que me deben más de 200 mil hace más de 20 días". Entra el que debe 300 mil con
     * una venta de 40 días; no entran el de deuda vieja pero chica ni el de deuda grande pero toda
     * reciente; el que cumple sin teléfono queda entre los que no les llega, con el motivo.
     *
     * @test
     */
    public function el_filtro_es_el_de_la_consulta_y_la_propuesta_muestra_todo_antes_del_si()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 150000);
        $this->venta_impaga($perez, 2, 150000);

        $chica = $this->cliente('Deuda Chica', '5493444202020');
        $this->venta_impaga($chica, 40, 50000);

        $reciente = $this->cliente('Deuda Reciente', '5493444303030');
        $this->venta_impaga($reciente, 5, 400000);

        $sin_telefono = $this->cliente('Sin Telefono', null);
        $this->venta_impaga($sin_telefono, 60, 250000);

        list($conversation, $assistant) = $this->vuelta('mandales un recordatorio a los que me deben mas de 200 mil hace mas de 20 dias');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', [
            'monto_minimo' => 200000,
            'dias'         => 20,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Se ejecutó solo en directo.');
        $this->assertSame(DestinatariosIaHelper::COMO_MOSTRARLO, $respuesta['nota']);

        $mostrar = $this->para_mostrar($respuesta);

        $this->assertSame('Juan Perez', $mostrar['destinatarios']);
        $this->assertSame(1, $mostrar['cantidad']);
        $this->assertCount(1, $mostrar['no_les_llega']);
        $this->assertSame('Sin Telefono', $mostrar['no_les_llega'][0]['clientes']);
        $this->assertSame(RecordatorioCobroSenderService::CODE_SIN_TELEFONO, $mostrar['no_les_llega'][0]['motivo']);

        // El texto es el REAL del primero (previsualizar()), y la aclaración dice que cada uno recibe lo suyo.
        $this->assertStringContainsString('Juan Perez', $mostrar['texto_que_sale']);
        $this->assertStringContainsString('SUS ventas impagas de 20 días o más', $mostrar['aclaracion']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::TIPO_RECORDATORIOS_COBRO, $tarjeta->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame([(int) $perez->id], $tarjeta->datos['client_ids']);
        $this->assertSame(20, $tarjeta->datos['dias']);

        $etiquetas = array_column($tarjeta->presentacion['renglones'], 'valor', 'etiqueta');

        $this->assertSame('Deuda total de $ 200.000 o más, alguna venta impaga de 20 días o más', $etiquetas['Filtro']);
        $this->assertSame('Juan Perez', $etiquetas['Le llega a 1']);
        $this->assertStringContainsString('Sin Telefono', $etiquetas['No le llega a 1']);

        $this->assertCount(0, $this->recordatorios_enviados(), 'Salió sin el sí.');
        Http::assertNothingSent();
    }

    /**
     * Con el sí en el mensaje siguiente: un job por cliente (sync en la suite) y el recordatorio sale
     * (simulado). El resultado dice cuántos se encolaron.
     *
     * @test
     */
    public function con_el_si_se_encolan_los_que_vio_el_dueno_y_salen()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 300000);

        $gomez = $this->cliente('Ana Gomez', '5493444404040');
        $this->venta_impaga($gomez, 35, 250000);
        $this->chat_abierto($gomez);

        list($conversation, $assistant) = $this->vuelta('mandales el recordatorio');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['monto_minimo' => 200000, 'dias' => 30]);

        $this->assertSame(2, $this->para_mostrar($propuesta)['cantidad'], json_encode($propuesta));

        // Un cliente NUEVO que empieza a cumplir después de la tarjeta no recibe nada: no lo vio.
        $nuevo = $this->cliente('Cliente Nuevo', '5493444505050');
        $this->venta_impaga($nuevo, 90, 900000);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertSame(2, $confirmada['detalle']['encolados']);
        $this->assertStringContainsString('Recordatorio de cobro en camino a 2 clientes', $confirmada['resultado']);

        $enviados = $this->recordatorios_enviados();

        $this->assertCount(2, $enviados);

        foreach ($enviados as $mensaje) {
            $this->assertTrue(WhatsappBotSendService::es_id_simulado($mensaje->wa_message_id));
        }

        $chats = WhatsappChat::whereIn('id', $enviados->pluck('whatsapp_chat_id'))->pluck('client_id')->all();

        $this->assertNotContains((int) $nuevo->id, array_map('intval', $chats));

        Http::assertNothingSent();
    }

    /**
     * El freno de 24 h: el que ya recibió el recordatorio hoy queda entre los que no les llega; si no
     * queda nadie, no hay tarjeta.
     *
     * @test
     */
    public function el_freno_de_24_horas_y_sin_nadie_no_hay_tarjeta()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 300000);

        $chat = $this->chat_abierto($perez);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => RecordatorioCobroSenderService::SOURCE,
            'body'             => 'Recordatorio de hace un rato',
            'delivery_status'  => 'entregado',
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales el recordatorio');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['monto_minimo' => 200000]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('No hay a quién mandarle el recordatorio', $respuesta['error']);
        $this->assertStringContainsString('Ya recibió el recordatorio en las últimas 24 horas', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Los clientes nombrados: el que no debe con el filtro se dice; el de otro negocio no existe.
     *
     * @test
     */
    public function los_clientes_nombrados_se_filtran_y_los_de_otro_negocio_no_existen()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 300000);

        $al_dia = $this->cliente('Al Dia', '5493444606060');

        $otro = User::create(['name' => 'ZZE Otro', 'email' => 'zze-otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $ajeno = $this->cliente('Cliente Ajeno', '5493444707070', null, $otro->id);
        $this->venta_impaga($ajeno, 40, 300000);

        list($conversation, $assistant) = $this->vuelta('mandale el recordatorio a Perez y a Al Dia');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', [
            'client_ids' => [$perez->id, $al_dia->id, $ajeno->id],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('Juan Perez', $this->para_mostrar($respuesta)['destinatarios']);
        $this->assertSame('Al Dia', $this->para_mostrar($respuesta)['no_les_llega'][0]['clientes']);
        $this->assertStringNotContainsString('Ajeno', json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame([(int) $perez->id], $tarjeta->datos['client_ids']);
    }

    /**
     * M1 del chequeo: una tarjeta sin ids no le manda a nadie (con la lista vacía, el filtro entendería
     * "todos los que deben", y serían clientes que el dueño no vio).
     *
     * @test
     */
    public function una_tarjeta_sin_clientes_no_le_manda_a_nadie()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 300000);

        list($conversation, $assistant) = $this->vuelta('mandales el recordatorio');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', []);

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);
        $datos = $tarjeta->datos;
        $datos['client_ids'] = [];
        $tarjeta->datos = $datos;
        $tarjeta->save();

        $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertTrue(empty($intento['ok']), json_encode($intento));
        $this->assertStringContainsString('no tiene a quién mandarle', $intento['error']);
        $this->assertCount(0, $this->recordatorios_enviados());
    }

    /**
     * Los gates: el permiso del recordatorio (un empleado sin él no puede) y la extensión de WhatsApp.
     *
     * @test
     */
    public function sin_permiso_o_sin_el_modulo_de_whatsapp_no_se_propone()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 300000);

        $empleado = User::create([
            'name'     => 'ZZE Empleado',
            'email'    => 'zze-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales el recordatorio');

        $conversation->auth_user_id = $empleado->id;
        $conversation->save();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', []);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertSame('No tenés permiso para mandar recordatorios de cobro desde tu usuario.', $respuesta['error']);

        $conversation->auth_user_id = $this->dueno->id;
        $conversation->save();

        $this->sacar_extension('agentes_ia');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', []);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('módulo de WhatsApp', $respuesta['error']);
    }
}
