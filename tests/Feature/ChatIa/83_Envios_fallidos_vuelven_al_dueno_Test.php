<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EnviosFallidosDelAsistenteHelper;
use App\Jobs\SendPromocionWhatsappJob;
use App\Jobs\SendRecordatorioCobroJob;
use App\Listeners\AnotarMailRechazadoPorElServidor;
use App\Mail\ComercioCityMail;
use App\Models\AiEnvioFallido;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Budget;
use App\Models\Client;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Carbon\Carbon;
use Database\Seeders\PdfColumnOptionSeeder;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\Transport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Swift_Mime_SimpleMessage;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-arreglos-segunda-prueba (19/10/2026) — N3: un envío que falla después del sí vuelve
 * al dueño.
 *
 * EL CASO REAL (segunda prueba por WhatsApp contra demo3): el dueño confirmó mandarle un presupuesto a
 * Marcos por mail; el resultado decía «mandado … por mail a X (sale en unos minutos)», el modelo le
 * contestó «le mandé el presupuesto», y el mail murió en el worker. Ningún registro ataba el fallo a la
 * tarjeta ni a la conversación. Recordatorios y promos: un log por cliente y nada más.
 *
 * Lo que protege este archivo:
 *  - el resultado no promete lo que sale en segundo plano ("queda saliendo") y el modelo recibe la nota
 *    de envío (no "Ahora sí quedó registrado"), con el "te aviso" solo si el aviso va a salir;
 *  - el mail que muere en el worker (failed()), el 550 que no lanza (el listener), el WhatsApp que Meta
 *    rechaza después del sent (con el rango corregido) y los jobs de recordatorio y de promo dejan su
 *    fila en ai_envios_fallidos, una por lote;
 *  - el aviso sale por el canal de avisos de siempre (envio_fallido:{uuid}, sin link) y avisos/avisado lo
 *    sella y lo deja en la conversación;
 *  - con la cola sync el fallo se dice en el turno y no se avisa dos veces; sin admin que avise, la fila
 *    queda sin aviso.
 *
 * Nada sale a la red: WhatsApp en envío simulado, Http::fake, Mail::fake o un transporte de mentira.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envios_fallidos_vuelven_al_dueno_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** La base del SPA que anuncia el admin en estos tests. */
    const SPA_URL = 'https://ferreteria-envios.comerciocity.com';

    /** La clave que manda el admin en X-Admin-Api-Key. */
    const CLAVE = 'clave-del-admin-envios-fallidos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);

        $this->seed(PdfColumnOptionSeeder::class);
    }

    /**
     * Un presupuesto del negocio del test.
     *
     * @param  Client  $client
     * @param  int  $num
     * @return Budget
     */
    protected function presupuesto(Client $client, $num = 900005)
    {
        return Budget::create([
            'user_id'   => $this->dueno->id,
            'client_id' => $client->id,
            'num'       => $num,
            'total'     => 45000,
            'moneda_id' => 1,
        ]);
    }

    /**
     * El admin de este dueño anunció que consulta los avisos (lo que hace que el aviso se pida).
     *
     * @return void
     */
    protected function el_admin_avisa()
    {
        AvisosDelAsistenteHelper::anunciar($this->dueno->id, '1', self::SPA_URL);
    }

    /**
     * Propone el envío del presupuesto y lo confirma con el sí del mensaje siguiente.
     *
     * @param  array  $input
     * @return array{0: \App\Models\AiConversation, 1: array, 2: array}  conversación, propuesta, confirmada
     */
    protected function mandar_el_presupuesto(array $input = [])
    {
        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900005 a Marcos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', array_merge([
            'tipo'   => 'presupuesto',
            'numero' => 900005,
        ], $input));

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        return [$conversation, $propuesta, $confirmada];
    }

    /**
     * El canal de avisos por HTTP, como lo consulta el admin.
     *
     * @return void
     */
    protected function preparar_el_canal_del_admin()
    {
        $this->dar_extension('asistente_ia');

        config(['services.admin_api.api_key' => self::CLAVE]);
        config(['services.admin_api.require_api_key' => false]);
        config(['app.USER_ID' => $this->dueno->id]);
    }

    // ---------------------------------------------------------------------
    // El texto del resultado
    // ---------------------------------------------------------------------

    /**
     * 🔴 Con solo el mail, el resultado ya no dice "mandado": dice que queda saliendo, el modelo recibe la
     * nota de envío y un aviso que no promete el "te aviso" (sin anuncio del admin no va a salir).
     *
     * @test
     */
    public function el_mail_queda_saliendo_y_el_modelo_recibe_la_nota_de_envio()
    {
        Mail::fake();

        $marcos = $this->cliente('Marcos Perez', null, 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertSame(ConfirmacionPorTextoIaHelper::NOTA_ENVIO_EJECUTADO, $confirmada['nota']);
        $this->assertStringContainsString('El presupuesto N° 900005 para Marcos Perez: por mail queda saliendo a marcos@test.local (se manda en segundo plano).', $confirmada['resultado']);
        $this->assertStringNotContainsString('mandado', $confirmada['resultado']);
        $this->assertSame('No digas que el mail llegó: queda saliendo.', $confirmada['detalle']['aviso']);

        // El mail lleva su tarjeta: es con lo que un fallo en el worker vuelve al dueño.
        Mail::assertQueued(ComercioCityMail::class, function ($mail) use ($propuesta) {
            return $mail->hasTo('marcos@test.local') && (int) $mail->ai_message_action_id === (int) $propuesta['tarjeta_id'];
        });

        $this->assertStringNotContainsString('Ahora sí quedó registrado', ConfirmacionPorTextoIaHelper::NOTA_ENVIO_EJECUTADO);
        $this->assertStringContainsString('No digas que le llegó', ConfirmacionPorTextoIaHelper::NOTA_ENVIO_EJECUTADO);
    }

    /**
     * Con el anuncio del admin y la tarjeta por WhatsApp, el aviso sí promete el "te aviso por acá".
     *
     * @test
     */
    public function con_el_admin_que_avisa_el_resultado_promete_el_aviso()
    {
        Mail::fake();
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, , $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        // Lo que salió en el acto (el WhatsApp) se sigue diciendo como mandado; el mail, queda saliendo.
        $this->assertStringContainsString('El presupuesto N° 900005 mandado a Marcos Perez: por WhatsApp al 5493444001122', $confirmada['resultado']);
        $this->assertStringContainsString('Por mail queda saliendo a marcos@test.local', $confirmada['resultado']);
        $this->assertSame('No digas que el mail llegó: queda saliendo. Si rebota, le aviso por acá.', $confirmada['detalle']['aviso']);
    }

    // ---------------------------------------------------------------------
    // Mail que muere en el worker → fila → aviso → avisado
    // ---------------------------------------------------------------------

    /**
     * 🔴 EL CASO DE DEMO3: el mail encolado muere en el worker (failed() del mailable). Queda la fila, el
     * admin la ve en avisos-pendientes como envio_fallido (sin link), la manda y avisos/avisado la sella y
     * la deja en la conversación, una sola vez.
     *
     * @test
     */
    public function un_mail_que_muere_en_el_worker_vuelve_al_dueno_por_el_canal_de_avisos()
    {
        Mail::fake();
        $this->el_admin_avisa();
        $this->preparar_el_canal_del_admin();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $mail = Mail::queued(ComercioCityMail::class)->first();

        $this->assertNotNull($mail);

        // Lo que hace la cola cuando el job del mail tira (el 401 de demo3): SendQueuedMailable::failed().
        (new SendQueuedMailable($mail))->failed(new \RuntimeException('Expected response code 250 but got code "401"'));

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertNotNull($fila, 'El mail fallido tiene que quedar atado a su tarjeta.');
        $this->assertSame(AiEnvioFallido::TIPO_COMPROBANTE, $fila->tipo_envio);
        $this->assertSame(AiEnvioFallido::CANAL_MAIL, $fila->canal);
        $this->assertSame('marcos@test.local', $fila->destino);
        $this->assertSame('Marcos Perez', $fila->cliente);
        $this->assertSame('el presupuesto N° 900005', $fila->que);
        $this->assertSame((int) $this->dueno->id, $fila->owner_id);
        $this->assertSame((int) $conversation->id, $fila->ai_conversation_id);
        $this->assertTrue($fila->avisar_por_whatsapp);

        // Un segundo fallo del mismo mail (un reintento) no deja otra fila.
        (new SendQueuedMailable($mail))->failed(new \RuntimeException('otra vez'));
        $this->assertSame(1, AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->count());

        // Por WhatsApp sí salió: el cliente ya lo tiene, alcanza con decirlo (revisión del 19/10/2026).
        $texto = 'No le llegó el presupuesto N° 900005 a Marcos Perez por mail (marcos@test.local): el servidor de correo no lo aceptó. '
            . 'Por WhatsApp sí salió.';

        // GET avisos-pendientes, como lo consulta el admin: el tipo nuevo, sin ruta ni url.
        $pendientes = $this->getJson('api/admin-sync/asistente/avisos-pendientes?spa_url=' . urlencode(self::SPA_URL), ['X-Admin-Api-Key' => self::CLAVE]);

        $pendientes->assertStatus(200);

        $this->assertSame([[
            'id'                 => 'envio_fallido:' . $fila->uuid,
            'tipo'               => 'envio_fallido',
            'ai_conversation_id' => (int) $conversation->id,
            'texto'              => $texto,
            'ruta'               => '',
            'url'                => null,
        ]], $pendientes->json('avisos'));

        // POST avisos/avisado: sella y deja el aviso en la conversación.
        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => 'envio_fallido:' . $fila->uuid, 'whatsapp_message_id' => 'wamid.AVISO1'], ['X-Admin-Api-Key' => self::CLAVE])
            ->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertNotNull($fila->fresh()->avisado_por_whatsapp_at);

        $avisos = AiMessage::where('ai_conversation_id', $conversation->id)->where('contenido', $texto)->get();

        $this->assertCount(1, $avisos);
        $this->assertSame('wamid.AVISO1', $avisos->first()->whatsapp_message_id);
        $this->assertSame(AiMessage::CANAL_WHATSAPP, (string) $avisos->first()->canal);

        // Idempotente: un reintento del admin no duplica el mensaje.
        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => 'envio_fallido:' . $fila->uuid], ['X-Admin-Api-Key' => self::CLAVE])->assertStatus(200);
        $this->assertSame(1, AiMessage::where('ai_conversation_id', $conversation->id)->where('contenido', $texto)->count());

        // Ya no está pendiente, y un uuid que no existe es el 404 del contrato.
        $this->assertSame([], $this->getJson('api/admin-sync/asistente/avisos-pendientes', ['X-Admin-Api-Key' => self::CLAVE])->json('avisos'));
        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => 'envio_fallido:no-existe-este-uuid'], ['X-Admin-Api-Key' => self::CLAVE])->assertStatus(404);
    }

    /**
     * Sin el anuncio del admin la fila queda (es el registro), pero sin aviso: no se promete lo que nadie va
     * a mandar. Y una tarjeta que se propuso desde el panel tampoco pide aviso por WhatsApp.
     *
     * @test
     */
    public function sin_admin_que_avise_la_fila_queda_sin_aviso()
    {
        Mail::fake();

        $marcos = $this->cliente('Marcos Perez', null, 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta) = $this->mandar_el_presupuesto();

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->first()))->failed(new \RuntimeException('401'));

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertNotNull($fila);
        $this->assertFalse($fila->avisar_por_whatsapp);
        $this->assertSame([], AvisosDelAsistenteHelper::pendientes($this->dueno));

        // Desde el panel: aunque el admin avise, la tarjeta no vino por WhatsApp.
        $this->el_admin_avisa();

        $otro = $this->presupuesto($marcos, 900006);
        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900006 a Marcos', null, null);

        $del_panel = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => (int) $otro->num]);

        $fila_del_panel = EnviosFallidosDelAsistenteHelper::registrar($del_panel['tarjeta_id'], AiEnvioFallido::CANAL_MAIL, 'marcos@test.local', 'Marcos Perez', 'el servidor de correo no lo aceptó');

        $this->assertNotNull($fila_del_panel);
        $this->assertFalse($fila_del_panel->avisar_por_whatsapp);
        $this->assertSame([], AvisosDelAsistenteHelper::pendientes($this->dueno));

        // Sin tarjeta (un mail de la pantalla) no se registra nada, y tampoco revienta.
        $this->assertNull(EnviosFallidosDelAsistenteHelper::registrar(null, AiEnvioFallido::CANAL_MAIL, 'x@test.local', 'X', 'x'));
        $this->assertNull(EnviosFallidosDelAsistenteHelper::registrar(999999999, AiEnvioFallido::CANAL_MAIL, 'x@test.local', 'X', 'x'));
    }

    /**
     * El 550 que SwiftMailer no convierte en excepción: el job del mail termina "bien" y su failed() no
     * corre nunca. Lo ve el listener de MessageSent, que lee la tarjeta de los datos del mail.
     *
     * @test
     */
    public function el_550_que_no_lanza_lo_registra_el_listener()
    {
        Mail::fake();
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', null, 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta) = $this->mandar_el_presupuesto();

        $mail = Mail::queued(ComercioCityMail::class)->first();

        // El mailer por defecto reporta la casilla rechazada (lo que deja SwiftMailer con un 550 en el RCPT TO).
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->andReturn(['marcos@test.local']);

        (new AnotarMailRechazadoPorElServidor())->handle(new MessageSent(new \Swift_Message('Te enviamos un presupuesto'), $mail->buildViewData()));

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertNotNull($fila);
        $this->assertSame(EnviosFallidosDelAsistenteHelper::MOTIVO_MAIL_RECHAZADO, $fila->motivo);
        $this->assertSame('marcos@test.local', $fila->destino);
        $this->assertSame('Marcos Perez', $fila->cliente);
        $this->assertTrue($fila->avisar_por_whatsapp);

        // Un mail sin tarjeta (la pantalla) no registra nada.
        (new AnotarMailRechazadoPorElServidor())->handle(new MessageSent(new \Swift_Message('Nueva venta registrada'), []));

        $this->assertSame(1, AiEnvioFallido::where('owner_id', $this->dueno->id)->count());
    }

    // ---------------------------------------------------------------------
    // Cola sync: el fallo pasa adentro del turno
    // ---------------------------------------------------------------------

    /**
     * 🔴 Con QUEUE_CONNECTION=sync el mail sale adentro del ->queue(): si el transporte tira (el 401 de
     * demo3), el resultado del MISMO turno lo dice y la fila queda sellada como dicha: el admin no lo vuelve
     * a avisar.
     *
     * @test
     */
    public function con_la_cola_sync_el_fallo_se_dice_en_el_turno_y_no_se_avisa_dos_veces()
    {
        $this->el_admin_avisa();
        $this->usar_un_transporte_de_mail(new class extends Transport {
            public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
            {
                throw new \Swift_TransportException('Expected response code 250 but got code "401", with message "401 Forbidden"');
            }
        });

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertStringContainsString('mandado a Marcos Perez: por WhatsApp', $confirmada['resultado']);
        $this->assertStringContainsString('por mail NO salió (el servidor de correo no lo aceptó)', $confirmada['resultado']);
        $this->assertStringNotContainsString('queda saliendo', $confirmada['resultado']);

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertNotNull($fila);
        $this->assertNotNull($fila->dicho_en_el_turno_at, 'Ya se dijo en el turno: no se avisa dos veces.');
        $this->assertSame([], AvisosDelAsistenteHelper::pendientes($this->dueno));
    }

    /**
     * Lo mismo con un 550 adentro del turno: el transporte rechaza la casilla sin tirar, el listener
     * registra y el resultado del turno lo cuenta.
     *
     * @test
     */
    public function con_la_cola_sync_un_550_tambien_se_dice_en_el_turno()
    {
        $this->el_admin_avisa();
        $this->usar_un_transporte_de_mail(new class extends Transport {
            public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
            {
                $failedRecipients = array_keys((array) $message->getTo());

                return 0;
            }
        });

        $marcos = $this->cliente('Marcos Perez', null, 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        // Era el único canal: no salió nada, y la tarjeta queda sin confirmar con el motivo.
        $this->assertTrue(empty($confirmada['ok']), json_encode($confirmada));
        $this->assertStringContainsString('el servidor de correo rechazó la casilla', $confirmada['error']);
    }

    // ---------------------------------------------------------------------
    // WhatsApp rechazado por Meta después del sent
    // ---------------------------------------------------------------------

    /**
     * 🔴 El rango corregido: un `failed` después de un `sent` se guarda (antes valían lo mismo y se
     * descartaba), y como la fila trae su tarjeta, el rechazo vuelve al dueño. Un failed repetido no deja
     * otra fila y un mensaje sin tarjeta no registra nada.
     *
     * @test
     */
    public function un_whatsapp_rechazado_por_meta_despues_del_sent_vuelve_al_dueno()
    {
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', null);
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $mensaje = WhatsappChatMessage::where('direction', 'out')
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->first();

        $this->assertSame((int) $propuesta['tarjeta_id'], (int) $mensaje->ai_message_action_id, 'El WhatsApp que salió queda anotado con su tarjeta.');

        // Como un envío real: Kapso lo aceptó y el estado arranca pendiente.
        WhatsappChatMessage::where('id', $mensaje->id)->update(['wa_message_id' => 'wamid.N3RECHAZO', 'delivery_status' => 'pendiente']);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.sent', ['message' => ['id' => 'wamid.N3RECHAZO']]);
        $this->assertSame('enviado', $mensaje->fresh()->delivery_status);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', [
            'message' => ['id' => 'wamid.N3RECHAZO', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]],
        ]);

        $this->assertSame('fallido', $mensaje->fresh()->delivery_status, 'Un failed después del sent ya no se descarta.');

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->where('canal', AiEnvioFallido::CANAL_WHATSAPP)->first();

        $this->assertNotNull($fila);
        $this->assertSame('5493444001122', $fila->destino);
        $this->assertSame('Marcos Perez', $fila->cliente);
        $this->assertStringContainsString(EnviosFallidosDelAsistenteHelper::MOTIVO_WHATSAPP_NO_ENTREGADO, $fila->motivo);

        // Un failed repetido no avanza el estado ni deja otra fila.
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', ['message' => ['id' => 'wamid.N3RECHAZO']]);
        $this->assertSame(1, AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->count());

        $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertCount(1, $avisos);
        $this->assertStringStartsWith('No le llegó el presupuesto N° 900005 a Marcos Perez por WhatsApp (5493444001122): WhatsApp no lo entregó', $avisos[0]['texto']);

        // Un WhatsApp manual (sin tarjeta) que Meta rechaza no registra nada.
        $chat = WhatsappChat::find($mensaje->whatsapp_chat_id);
        WhatsappChatHelper::store_outbound_manual_message($chat, 'Mensaje del operador', 'wamid.MANUAL', $this->dueno->id);
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', ['message' => ['id' => 'wamid.MANUAL']]);

        $this->assertSame(1, AiEnvioFallido::where('owner_id', $this->dueno->id)->count());
    }

    // ---------------------------------------------------------------------
    // Recordatorios y promo: una fila por lote
    // ---------------------------------------------------------------------

    /**
     * 🔴 Dos recordatorios que no salen en el worker (Kapso no confirma) quedan en UNA fila de la tarjeta,
     * con cantidad y nombres; el aviso espera la calma del lote y dice los dos.
     *
     * @test
     */
    public function los_recordatorios_que_no_salen_en_el_worker_son_un_solo_aviso_por_lote()
    {
        $this->el_admin_avisa();

        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $this->venta_impaga($ana, 40, 300000);
        $juan = $this->cliente('Juan Perez', '5493444202020');
        $this->venta_impaga($juan, 45, 250000);

        $this->chat_abierto($ana);
        $this->chat_abierto($juan);

        list($conversation, $assistant) = $this->vuelta('mandales un recordatorio a los que me deben');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['monto_minimo' => 100000]);

        $this->assertSame(2, $this->para_mostrar($propuesta)['cantidad'], json_encode($propuesta));

        // La cola de verdad: los jobs quedan encolados y corren después, en el worker.
        Queue::fake();

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertSame('No digas que les llegó: salen en segundo plano. Si alguno no sale, le aviso por acá.', $confirmada['detalle']['aviso']);

        $jobs = Queue::pushed(SendRecordatorioCobroJob::class);

        $this->assertCount(2, $jobs);

        // En el worker, Kapso no confirma: los chats están en simulación y el negocio ya no es demo.
        $this->kapso_no_confirma([$ana, $juan]);

        foreach ($jobs as $job) {
            $job->handle();
        }

        $filas = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->get();

        $this->assertCount(1, $filas, 'Un aviso por lote, no uno por cliente.');
        $this->assertSame(AiEnvioFallido::TIPO_RECORDATORIOS, $filas[0]->tipo_envio);
        $this->assertSame(2, $filas[0]->cantidad);
        $this->assertEqualsCanonicalizing(['Ana Gomez', 'Juan Perez'], $filas[0]->lista_de_nombres());
        $this->assertSame('WhatsApp no confirmó el envío', $filas[0]->motivo);

        // Recién después de la calma del lote se avisa.
        $this->assertSame([], AvisosDelAsistenteHelper::pendientes($this->dueno));

        Carbon::setTestNow(Carbon::now()->addMinutes(EnviosFallidosDelAsistenteHelper::MINUTOS_DE_CALMA_DEL_LOTE + 1));

        try {

            $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

            $this->assertCount(1, $avisos);
            $this->assertSame('envio_fallido', $avisos[0]['tipo']);
            $this->assertStringStartsWith('De los recordatorios de cobro, 2 no salieron por WhatsApp: ', $avisos[0]['texto']);
            $this->assertStringContainsString('(WhatsApp no confirmó el envío).', $avisos[0]['texto']);

        } finally {

            Carbon::setTestNow();
        }
    }

    /**
     * Con la cola sync los recordatorios fallan ADENTRO del turno: el resultado lo dice y no queda aviso.
     *
     * @test
     */
    public function con_la_cola_sync_los_recordatorios_que_fallan_se_dicen_en_el_turno()
    {
        $this->el_admin_avisa();

        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $this->venta_impaga($ana, 40, 300000);
        $this->chat_abierto($ana);

        list($conversation, $assistant) = $this->vuelta('mandale un recordatorio a Ana');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', ['client_ids' => [$ana->id]]);

        $this->kapso_no_confirma([$ana]);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertStringContainsString('De esos, a 1 no le salió (WhatsApp no confirmó el envío): Ana Gomez.', $confirmada['resultado']);

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertNotNull($fila);
        $this->assertNotNull($fila->dicho_en_el_turno_at);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        try {
            $this->assertSame([], AvisosDelAsistenteHelper::pendientes($this->dueno));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Lo mismo con la promo: los rechazos del job quedan en una fila por tarjeta; el candado ocupado (que se
     * reencola sin haber mandado nada) no registra.
     *
     * @test
     */
    public function las_promos_que_no_salen_en_el_worker_son_un_solo_aviso_por_lote()
    {
        $this->el_admin_avisa();

        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $juan = $this->cliente('Juan Perez', '5493444202020');
        $this->chat_abierto($ana);
        $this->chat_abierto($juan);

        list($conversation, $assistant) = $this->vuelta('mandales una promo a todos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        Queue::fake();

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertSame('No digas que les llegó: sale en segundo plano. Si alguna no sale, le aviso por acá.', $confirmada['detalle']['aviso']);

        $jobs = Queue::pushed(SendPromocionWhatsappJob::class);

        $this->assertCount(2, $jobs);

        $this->kapso_no_confirma([$ana, $juan]);

        foreach ($jobs as $job) {
            $job->handle();
        }

        $filas = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->get();

        $this->assertCount(1, $filas);
        $this->assertSame(AiEnvioFallido::TIPO_PROMOCION, $filas[0]->tipo_envio);
        $this->assertSame(2, $filas[0]->cantidad);
        $this->assertSame('WhatsApp no confirmó el envío', $filas[0]->motivo);

        Carbon::setTestNow(Carbon::now()->addMinutes(EnviosFallidosDelAsistenteHelper::MINUTOS_DE_CALMA_DEL_LOTE + 1));

        try {
            $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

            $this->assertCount(1, $avisos);
            $this->assertStringStartsWith('De la promoción, 2 no salieron por WhatsApp: ', $avisos[0]['texto']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------------------------------------------------------------------
    // Revisión del 19/10/2026: lo que llega entre el GET y el POST del admin
    // ---------------------------------------------------------------------

    /**
     * 🔴 El admin trae "2 no salieron: Ana y Juan" y, mientras lo manda, falla Pedro. Pedro NO se suma a
     * la fila que ya se ofreció (el sello se lo llevaría sin que nadie se lo diga al dueño): abre otra,
     * con su propio aviso. Y la conversación queda con el texto que se ofreció (2), no con 3.
     *
     * @test
     */
    public function un_fallo_que_llega_despues_de_ofrecido_el_lote_abre_otro_aviso()
    {
        $this->el_admin_avisa();

        $this->cliente('Ana Gomez', '5493444101010');
        $this->cliente('Juan Perez', '5493444202020');
        $this->cliente('Pedro Diaz', '5493444303030');

        list($conversation, $assistant) = $this->vuelta('mandales una promo a todos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $tarjeta = (int) $propuesta['tarjeta_id'];

        EnviosFallidosDelAsistenteHelper::registrar($tarjeta, AiEnvioFallido::CANAL_WHATSAPP, '5493444101010', 'Ana Gomez', 'WhatsApp no confirmó el envío');
        EnviosFallidosDelAsistenteHelper::registrar($tarjeta, AiEnvioFallido::CANAL_WHATSAPP, '5493444202020', 'Juan Perez', 'WhatsApp no confirmó el envío');

        $ahora = Carbon::now();

        try {

            // El lote se calmó: el admin lo trae.
            Carbon::setTestNow($ahora->copy()->addMinutes(EnviosFallidosDelAsistenteHelper::MINUTOS_DE_CALMA_DEL_LOTE + 1));

            $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

            $this->assertCount(1, $avisos);

            $ofrecido = $avisos[0];

            $this->assertStringContainsString('2 no salieron por WhatsApp: Ana Gomez y Juan Perez', $ofrecido['texto']);

            // Entre el GET y el POST falla Pedro.
            EnviosFallidosDelAsistenteHelper::registrar($tarjeta, AiEnvioFallido::CANAL_WHATSAPP, '5493444303030', 'Pedro Diaz', 'WhatsApp no confirmó el envío');

            $this->assertSame(2, AiEnvioFallido::where('ai_message_action_id', $tarjeta)->count(), 'Pedro tiene que abrir otra fila: la primera ya se ofreció.');

            $this->assertTrue(AvisosDelAsistenteHelper::marcar_avisado($this->dueno, $ofrecido['id'], null, 'wamid.LOTE1'));

            $primera = AiEnvioFallido::where('ai_message_action_id', $tarjeta)->orderBy('id')->first();

            $this->assertSame(2, $primera->cantidad);
            $this->assertNotNull($primera->avisado_por_whatsapp_at);
            $this->assertSame(1, AiMessage::where('ai_conversation_id', $conversation->id)->where('contenido', $ofrecido['texto'])->count(), 'La conversación queda con lo que se le mandó al dueño.');

            // Cuando el de Pedro se calma, sale su propio aviso.
            Carbon::setTestNow($ahora->copy()->addMinutes(2 * (EnviosFallidosDelAsistenteHelper::MINUTOS_DE_CALMA_DEL_LOTE + 1)));

            $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

            $this->assertCount(1, $avisos);
            $this->assertStringStartsWith('No le salió la promoción a Pedro Diaz por WhatsApp (5493444303030): WhatsApp no confirmó el envío.', $avisos[0]['texto']);

        } finally {

            Carbon::setTestNow();
        }
    }

    /**
     * El texto que queda en la conversación al sellar es el que se le OFRECIÓ al admin: si entre el GET y
     * el POST falla también el WhatsApp del mismo comprobante, el mensaje del mail no se rearma (el dueño
     * recibió el que decía que por WhatsApp sí salió; el del WhatsApp tiene su propio aviso).
     *
     * @test
     */
    public function el_texto_que_queda_en_la_conversacion_es_el_que_se_ofrecio()
    {
        Mail::fake();
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $propuesta) = $this->mandar_el_presupuesto();

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->first()))->failed(new \RuntimeException('401'));

        $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertCount(1, $avisos);

        $ofrecido = $avisos[0];

        $this->assertStringContainsString('Por WhatsApp sí salió.', $ofrecido['texto']);

        // Entre el GET y el POST, Meta rechaza el WhatsApp del mismo presupuesto.
        EnviosFallidosDelAsistenteHelper::registrar($propuesta['tarjeta_id'], AiEnvioFallido::CANAL_WHATSAPP, '5493444001122', 'Marcos Perez', 'WhatsApp no lo entregó');

        $this->assertTrue(AvisosDelAsistenteHelper::marcar_avisado($this->dueno, $ofrecido['id']));

        $this->assertSame(1, AiMessage::where('ai_conversation_id', $conversation->id)->where('contenido', $ofrecido['texto'])->count());

        // El del WhatsApp sale aparte.
        $siguen = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertCount(1, $siguen);
        $this->assertStringContainsString('por WhatsApp (5493444001122)', $siguen[0]['texto']);
    }

    /**
     * Segunda revisión del 19/10/2026: vale lo PRIMERO que se ofreció. Si entre dos consultas del admin
     * cambia lo que diría el aviso (el WhatsApp del mismo comprobante falla), la segunda ofrece el mismo
     * texto que la primera y eso es lo que queda en la conversación.
     *
     * @test
     */
    public function vale_el_primer_texto_que_se_ofrecio()
    {
        Mail::fake();
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $propuesta) = $this->mandar_el_presupuesto();

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->first()))->failed(new \RuntimeException('401'));

        $primera = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertStringContainsString('Por WhatsApp sí salió.', $primera[0]['texto']);

        // Entre las dos consultas falla el WhatsApp del mismo presupuesto: rearmado, el aviso del mail cambiaría.
        EnviosFallidosDelAsistenteHelper::registrar($propuesta['tarjeta_id'], AiEnvioFallido::CANAL_WHATSAPP, '5493444001122', 'Marcos Perez', 'WhatsApp no lo entregó');

        $segunda = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $del_mail = null;

        foreach ($segunda as $aviso) {
            if ($aviso['id'] === $primera[0]['id']) {
                $del_mail = $aviso;
            }
        }

        $this->assertNotNull($del_mail);
        $this->assertSame($primera[0]['texto'], $del_mail['texto'], 'La segunda consulta ofrece el mismo texto que la primera.');

        $this->assertTrue(AvisosDelAsistenteHelper::marcar_avisado($this->dueno, $primera[0]['id']));

        $this->assertSame(1, AiMessage::where('ai_conversation_id', $conversation->id)->where('contenido', $primera[0]['texto'])->count());
    }

    /**
     * 🔴 Revisión del 19/10/2026: los envíos van en dos etapas y sin transacción, así que si en la primera
     * confirmación no salió nada la tarjeta vuelve a "propuesta" y su fila queda. Al reconfirmar, el 550
     * del mismo turno tiene que volver a contarse como "NO salió", no como "queda saliendo".
     *
     * @test
     */
    public function al_reconfirmar_la_misma_tarjeta_un_550_en_el_turno_sigue_contandose_como_que_no_salio()
    {
        $this->el_admin_avisa();
        $this->usar_un_transporte_de_mail(new class extends Transport {
            public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
            {
                $failedRecipients = array_keys((array) $message->getTo());

                return 0;
            }
        });

        $marcos = $this->cliente('Marcos Perez', null, 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900005 a Marcos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900005]);

        $this->cerrar_la_vuelta($assistant);

        $primera = ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $propuesta['tarjeta_id']);

        $this->assertTrue(empty($primera['ok']), json_encode($primera));
        $this->assertStringContainsString('el servidor de correo rechazó la casilla', $primera['error']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado(), 'No salió nada: se puede volver a confirmar.');

        $ahora = Carbon::now();

        try {

            Carbon::setTestNow($ahora->copy()->addMinutes(5));

            $segunda = ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $propuesta['tarjeta_id']);

            $this->assertTrue(empty($segunda['ok']), 'El 550 de la segunda vez no puede salir como "queda saliendo": ' . json_encode($segunda));
            $this->assertStringContainsString('el servidor de correo rechazó la casilla', $segunda['error']);

            $this->assertSame(1, AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->count());

        } finally {

            Carbon::setTestNow();
        }
    }

    /**
     * Revisión del 19/10/2026: un mail que no salió y no fue por WhatsApp. Si el negocio puede mandarlo
     * por WhatsApp (el módulo y el teléfono del cliente), el aviso ofrece eso: un 401 del servidor de
     * correo vuelve a fallar si se reintenta por mail. Sin teléfono, el reintento de siempre.
     *
     * @test
     */
    public function el_aviso_de_un_mail_que_no_salio_ofrece_mandarlo_por_whatsapp_si_se_puede()
    {
        Mail::fake();
        $this->el_admin_avisa();

        // El dueño pidió solo por mail, y el cliente tiene teléfono.
        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta) = $this->mandar_el_presupuesto(['canales' => ['mail']]);

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->first()))->failed(new \RuntimeException('401'));

        $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertCount(1, $avisos);
        $this->assertSame(
            'No le llegó el presupuesto N° 900005 a Marcos Perez por mail (marcos@test.local): el servidor de correo no lo aceptó. Si querés, se lo mando por WhatsApp.',
            $avisos[0]['texto']
        );

        $this->assertTrue(AvisosDelAsistenteHelper::marcar_avisado($this->dueno, $avisos[0]['id']));

        // Sin teléfono no se ofrece WhatsApp.
        $ana = $this->cliente('Ana Gomez', null, 'ana@test.local');
        Budget::create(['user_id' => $this->dueno->id, 'client_id' => $ana->id, 'num' => 900007, 'total' => 1000, 'moneda_id' => 1]);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900007 a Ana');

        $otra = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900007]);

        $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $otra['tarjeta_id']);

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->last()))->failed(new \RuntimeException('401'));

        $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertCount(1, $avisos);
        $this->assertStringEndsWith('Si querés, pedime que se lo mande de nuevo.', $avisos[0]['texto']);
    }

    /**
     * Segunda revisión del 19/10/2026: si el WhatsApp de ese mismo comprobante falló, el aviso del mail no
     * ofrece "se lo mando por WhatsApp" (sería ofrecer lo que acaba de fallar).
     *
     * @test
     */
    public function el_aviso_del_mail_no_ofrece_whatsapp_si_el_whatsapp_del_mismo_comprobante_fallo()
    {
        Mail::fake();
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        // Meta rechaza el WhatsApp después del sent, y el mail muere en el worker.
        $mensaje = WhatsappChatMessage::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();
        WhatsappChatMessage::where('id', $mensaje->id)->update(['wa_message_id' => 'wamid.N3LOSDOS', 'delivery_status' => 'enviado']);
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', ['message' => ['id' => 'wamid.N3LOSDOS']]);

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->first()))->failed(new \RuntimeException('401'));

        $del_mail = null;

        foreach (AvisosDelAsistenteHelper::pendientes($this->dueno) as $aviso) {
            if (strpos($aviso['texto'], 'por mail') !== false) {
                $del_mail = $aviso;
            }
        }

        $this->assertNotNull($del_mail);
        $this->assertStringNotContainsString('se lo mando por WhatsApp', $del_mail['texto']);
        $this->assertStringEndsWith('Si querés, pedime que se lo mande de nuevo.', $del_mail['texto']);
    }

    // ---------------------------------------------------------------------
    // Revisión del 19/10/2026: un failed que después Meta desmiente
    // ---------------------------------------------------------------------

    /**
     * 🔴 `failed` y después `delivered`: el mensaje queda entregado (le gana en el rango) y el aviso de
     * "no le llegó" que todavía no salió se neutraliza.
     *
     * @test
     */
    public function un_failed_que_despues_se_entrega_no_se_avisa()
    {
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', null);
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $mensaje = WhatsappChatMessage::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        WhatsappChatMessage::where('id', $mensaje->id)->update(['wa_message_id' => 'wamid.N3VUELVE', 'delivery_status' => 'pendiente']);

        $payload = ['message' => ['id' => 'wamid.N3VUELVE']];

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.sent', $payload);
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', $payload);

        $this->assertCount(1, AvisosDelAsistenteHelper::pendientes($this->dueno), 'Con el failed, el aviso queda pendiente.');

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.delivered', $payload);

        $this->assertSame('entregado', $mensaje->fresh()->delivery_status);

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertFalse($fila->avisar_por_whatsapp, 'Le llegó: no hay nada que avisar.');
        $this->assertSame([], AvisosDelAsistenteHelper::pendientes($this->dueno));
    }

    /**
     * Segunda revisión del 19/10/2026: un WhatsApp que falló y después se entregó queda neutralizado, y el
     * aviso del mail del mismo comprobante vuelve a decir "Por WhatsApp sí salió.".
     *
     * @test
     */
    public function el_aviso_del_mail_ignora_el_whatsapp_que_fallo_y_despues_se_entrego()
    {
        Mail::fake();
        $this->el_admin_avisa();

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list(, $propuesta, $confirmada) = $this->mandar_el_presupuesto();

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $mensaje = WhatsappChatMessage::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        WhatsappChatMessage::where('id', $mensaje->id)->update(['wa_message_id' => 'wamid.N3IDAYVUELTA', 'delivery_status' => 'enviado']);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', ['message' => ['id' => 'wamid.N3IDAYVUELTA']]);
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.delivered', ['message' => ['id' => 'wamid.N3IDAYVUELTA']]);

        $this->assertSame(0, AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->where('canal', AiEnvioFallido::CANAL_WHATSAPP)->value('cantidad'), 'Neutralizada.');

        (new SendQueuedMailable(Mail::queued(ComercioCityMail::class)->first()))->failed(new \RuntimeException('401'));

        $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

        $this->assertCount(1, $avisos);
        $this->assertStringEndsWith('el servidor de correo no lo aceptó. Por WhatsApp sí salió.', $avisos[0]['texto']);
    }

    /**
     * Segunda revisión del 19/10/2026: en un lote con más fallos que nombres guardados (la lista tiene tope),
     * el que después se entregó puede no estar nombrado; igual se le resta a la cantidad.
     *
     * @test
     */
    public function en_un_lote_de_mas_de_veinte_el_que_se_entrega_sin_nombre_guardado_igual_se_resta()
    {
        $this->el_admin_avisa();

        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo a todos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $tarjeta = (int) $propuesta['tarjeta_id'];

        $total = EnviosFallidosDelAsistenteHelper::MAXIMO_DE_NOMBRES + 2;

        for ($i = 1; $i <= $total; $i++) {
            EnviosFallidosDelAsistenteHelper::registrar($tarjeta, AiEnvioFallido::CANAL_WHATSAPP, '54934440' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'Cliente ' . $i, 'WhatsApp no lo entregó');
        }

        $fila = AiEnvioFallido::where('ai_message_action_id', $tarjeta)->first();

        $this->assertSame($total, $fila->cantidad);
        $this->assertCount(EnviosFallidosDelAsistenteHelper::MAXIMO_DE_NOMBRES, $fila->lista_de_nombres(), 'La lista tiene tope.');

        // El último (sin nombre guardado) estaba como fallido y Meta lo entrega.
        $ultimo = $this->cliente('Cliente ' . $total, '5493444099999');
        $chat = $this->chat_abierto($ultimo);
        $mensaje = WhatsappChatHelper::store_outbound_manual_message($chat, 'Promo', 'wamid.N3SINNOMBRE', $this->dueno->id);

        WhatsappChatMessage::where('id', $mensaje->id)->update(['ai_message_action_id' => $tarjeta, 'delivery_status' => 'fallido']);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.delivered', ['message' => ['id' => 'wamid.N3SINNOMBRE']]);

        $fila = $fila->fresh();

        $this->assertSame($total - 1, $fila->cantidad, 'Se resta aunque no esté entre los nombres guardados.');
        $this->assertCount(EnviosFallidosDelAsistenteHelper::MAXIMO_DE_NOMBRES, $fila->lista_de_nombres());
    }

    /**
     * En un lote se resta solo ese cliente: de "Ana y Juan no la recibieron", si a Ana después le llega,
     * el aviso habla solo de Juan.
     *
     * @test
     */
    public function en_un_lote_el_que_despues_se_entrega_sale_del_aviso()
    {
        $this->el_admin_avisa();

        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $juan = $this->cliente('Juan Perez', '5493444202020');
        $this->chat_abierto($ana);
        $this->chat_abierto($juan);

        list($conversation, $assistant) = $this->vuelta('mandales una promo a todos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $ids = [];

        foreach ([$ana, $juan] as $client) {

            $mensaje = WhatsappChatMessage::where('ai_message_action_id', $propuesta['tarjeta_id'])
                ->whereIn('whatsapp_chat_id', WhatsappChat::where('client_id', $client->id)->select('id'))
                ->first();

            $this->assertNotNull($mensaje, 'La promo que salió queda anotada con su tarjeta.');

            $wamid = 'wamid.LOTE' . $client->id;

            WhatsappChatMessage::where('id', $mensaje->id)->update(['wa_message_id' => $wamid, 'delivery_status' => 'enviado']);

            WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', ['message' => ['id' => $wamid]]);

            $ids[(int) $client->id] = $wamid;
        }

        $fila = AiEnvioFallido::where('ai_message_action_id', $propuesta['tarjeta_id'])->first();

        $this->assertSame(2, $fila->cantidad);

        // A Ana le llega igual.
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.delivered', ['message' => ['id' => $ids[(int) $ana->id]]]);

        $fila = $fila->fresh();

        $this->assertSame(1, $fila->cantidad);
        $this->assertSame(['Juan Perez'], $fila->lista_de_nombres());
        $this->assertTrue($fila->avisar_por_whatsapp);

        Carbon::setTestNow(Carbon::now()->addMinutes(EnviosFallidosDelAsistenteHelper::MINUTOS_DE_CALMA_DEL_LOTE + 1));

        try {
            $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno);

            $this->assertCount(1, $avisos);
            $this->assertStringStartsWith('No le salió la promoción a Juan Perez por WhatsApp', $avisos[0]['texto']);
            $this->assertStringNotContainsString('5493444101010', $avisos[0]['texto'], 'El teléfono de Ana no va en el aviso de Juan.');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------

    /**
     * Kapso no confirma el texto libre: el negocio deja de estar en envío simulado y los chats de esos
     * clientes quedan en simulación (el freno de WhatsappBotSendService::send_text() devuelve null sin
     * salir a la red, que es el "Kapso no confirmó" de los jobs).
     *
     * @param  array<int, Client>  $clientes
     * @return void
     */
    protected function kapso_no_confirma(array $clientes)
    {
        $this->config->envio_simulado = false;
        $this->config->save();

        foreach ($clientes as $client) {
            WhatsappChat::where('user_id', $this->dueno->id)->where('client_id', $client->id)->update(['last_inbound_simulated' => 1]);
        }
    }

    /**
     * Manda los mails de este test por un transporte de mentira (sin red).
     *
     * @param  \Illuminate\Mail\Transport\Transport  $transporte
     * @return void
     */
    protected function usar_un_transporte_de_mail(Transport $transporte)
    {
        app('mail.manager')->extend('de_prueba_n3', function () use ($transporte) {
            return $transporte;
        });

        config(['mail.mailers.de_prueba_n3' => ['transport' => 'de_prueba_n3']]);
        config(['mail.default' => 'de_prueba_n3']);

        app('mail.manager')->forgetMailers();
    }
}
