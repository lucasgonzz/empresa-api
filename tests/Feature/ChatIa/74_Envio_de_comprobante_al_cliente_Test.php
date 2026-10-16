<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Mail\ComercioCityMail;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Budget;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\WhatsappBotSendService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes (16/10/2026) — proponer_envio_de_comprobante.
 *
 * El caso real (S05 del registro del 16/10): "mandale el presupuesto 900004 a Marcos por WhatsApp"
 * terminaba en "no puedo mandar mensajes a terceros, te paso el link". Lo que protege este archivo:
 *  - la propuesta resuelve el comprobante DEL DUEÑO por el número que ve, su cliente, y por canal
 *    a qué número o mail va, si sale como mensaje o como plantilla, y el texto exacto — o por qué no;
 *  - NUNCA se ejecuta sola: ni en "directo", ni con el "dale" determinista, ni en el mismo turno;
 *  - con el sí en el mensaje siguiente sale por el camino de siempre (send_sale / send_comprobante,
 *    new_sale / nuevo_presupuesto / nuevo_recibo) y el resultado dice qué salió por cada canal;
 *  - el número de otro negocio no existe para este dueño.
 *
 * Nada sale a Kapso (envío simulado + Http::fake + assertNothingSent) ni a SMTP (Mail::fake).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envio_de_comprobante_al_cliente_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
        Mail::fake();
    }

    protected function presupuesto($client, $num = 900004, $total = 99813.83, $owner_id = null)
    {
        return Budget::create([
            'user_id'   => is_null($owner_id) ? $this->dueno->id : $owner_id,
            'client_id' => $client->id,
            'num'       => $num,
            'total'     => $total,
            'moneda_id' => 1,
        ]);
    }

    // ---------------------------------------------------------------------
    // La propuesta
    // ---------------------------------------------------------------------

    /**
     * 🔴 S05: el presupuesto por WhatsApp y por mail. La tarjeta dice a quién, a qué número y mail,
     * cómo sale y el texto; en "directo" NO se manda nada hasta el sí.
     *
     * @test
     */
    public function el_presupuesto_se_propone_con_destino_y_texto_y_no_sale_solo_en_directo()
    {
        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $presupuesto = $this->presupuesto($marcos);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004 a Marcos');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', [
            'tipo'   => 'presupuesto',
            'numero' => 900004,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_ENVIO_COMPROBANTE, $respuesta['tipo']);
        $this->assertArrayNotHasKey('estado', $respuesta, 'Se ejecutó solo en directo.');

        // Lo que se le muestra al dueño antes del sí, y la instrucción de mostrarlo.
        $this->assertSame(DestinatariosIaHelper::COMO_MOSTRARLO, $respuesta['nota']);
        $this->assertSame('Marcos Perez', $respuesta['para_mostrar']['destinatarios']);
        $this->assertSame(1, $respuesta['para_mostrar']['cantidad']);
        $this->assertSame('5493444001122', $respuesta['para_mostrar']['por_whatsapp']);
        $this->assertSame('marcos@test.local', $respuesta['para_mostrar']['por_mail']);
        // Sin chat abierto sale como plantilla cc_cli_comprobante: el texto es el de la plantilla.
        $this->assertStringContainsString('Hola Marcos Perez, te enviamos el comprobante 900004', $respuesta['para_mostrar']['texto_que_sale']);
        $this->assertStringContainsString('Te enviamos un presupuesto', $respuesta['para_mostrar']['texto_que_sale']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame('Mandar el presupuesto N° 900004 a Marcos Perez', $tarjeta->presentacion['titulo']);
        $this->assertSame('Mandar', $tarjeta->presentacion['texto_confirmar']);

        $etiquetas = array_column($tarjeta->presentacion['renglones'], 'valor', 'etiqueta');

        $this->assertStringContainsString('5493444001122 (como plantilla', $etiquetas['Por WhatsApp']);
        $this->assertSame('marcos@test.local', $etiquetas['Por mail']);
        $this->assertStringContainsString('/budget/pdf/' . $presupuesto->id . '/1/0', $etiquetas['PDF']);
        $this->assertStringContainsString('t=', $etiquetas['PDF'], 'El link sale con su token: el cliente no tiene sesión.');

        // Nada salió.
        $this->assertSame(0, WhatsappChatMessage::where('direction', 'out')->whereIn('whatsapp_chat_id', function ($q) {
            $q->select('id')->from('whatsapp_chats')->where('user_id', $this->dueno->id);
        })->count());
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    /**
     * 🔴 Con el sí en el mensaje siguiente sale por los dos canales, por el molde de la venta, y el
     * resultado lo dice canal por canal.
     *
     * @test
     */
    public function con_el_si_del_mensaje_siguiente_el_presupuesto_sale_por_whatsapp_y_por_mail()
    {
        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $presupuesto = $this->presupuesto($marcos);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004 a Marcos');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', [
            'tipo'   => 'presupuesto',
            'numero' => 900004,
        ]);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertTrue(!empty($confirmada['ok']), json_encode($confirmada));
        $this->assertSame('confirmada', $confirmada['estado']);
        $this->assertSame(ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA, $confirmada['nota']);
        $this->assertStringContainsString('El presupuesto N° 900004 mandado a Marcos Perez', $confirmada['resultado']);
        $this->assertCount(2, $confirmada['detalle']['enviados'], json_encode($confirmada));

        // WhatsApp: la plantilla cc_cli_comprobante con el PDF, guardada en el chat (que se creó).
        $mensaje = WhatsappChatMessage::where('direction', 'out')->whereIn('whatsapp_chat_id', function ($q) {
            $q->select('id')->from('whatsapp_chats')->where('user_id', $this->dueno->id);
        })->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('plantilla', $mensaje->source);
        $this->assertSame('cc_cli_comprobante', $mensaje->template_meta_name);
        $this->assertSame('document', $mensaje->media_type);
        $this->assertStringContainsString('/budget/pdf/' . $presupuesto->id, $mensaje->media_url);
        $this->assertTrue(WhatsappBotSendService::es_id_simulado($mensaje->wa_message_id));

        // Mail: el mismo molde que la venta, con el link al PDF.
        Mail::assertQueued(ComercioCityMail::class, function ($mail) {
            return $mail->hasTo('marcos@test.local')
                && $mail->payload->subject === 'Te enviamos un presupuesto'
                && strpos(json_encode($mail->payload->links), 'budget') !== false;
        });

        Http::assertNothingSent();
    }

    /**
     * La venta por WhatsApp con la ventana abierta: send_sale() tal cual (documento con su epígrafe).
     * Y por mail, new_sale() tal cual, con la marca send_mail que deja el botón del listado.
     *
     * @test
     */
    public function la_venta_sale_por_send_sale_y_por_new_sale()
    {
        $ana = $this->cliente('Ana Gomez', '5493444002233', 'ana@test.local');
        $this->chat_abierto($ana);

        $venta = $this->venta_impaga($ana, 0, 12500, 4882);

        list($conversation, $assistant) = $this->vuelta('pasale la venta 4882 a Ana');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', [
            'tipo'    => 'venta',
            'numero'  => 4882,
        ]);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));
        $this->assertStringContainsString('Comprobante de tu compra en ZZE Ferreteria Envios', $propuesta['para_mostrar']['texto_que_sale']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        $mensaje = WhatsappChatMessage::where('direction', 'out')->whereIn('whatsapp_chat_id', function ($q) {
            $q->select('id')->from('whatsapp_chats')->where('user_id', $this->dueno->id);
        })->first();

        $this->assertSame('manual', $mensaje->source, 'La venta sale por send_sale() con la persona: source manual, como el botón.');
        $this->assertSame('Comprobante de tu compra en ZZE Ferreteria Envios', $mensaje->body);
        $this->assertStringContainsString('/sale/pdf/' . $venta->id, $mensaje->media_url);

        Mail::assertQueued(ComercioCityMail::class, function ($mail) {
            return $mail->hasTo('ana@test.local') && $mail->payload->subject === 'Nueva venta registrada';
        });

        $this->assertTrue((bool) Sale::find($venta->id)->send_mail, 'La marca send_mail del botón del listado.');
    }

    /**
     * El recibo se identifica por el número que ve el dueño ("Pago N° X" = num_receipt) y solo entre
     * los pagos de CLIENTES. Por mail sale con su molde.
     *
     * @test
     */
    public function el_recibo_se_busca_por_su_numero_de_pago_y_sale_por_mail()
    {
        $luis = $this->cliente('Luis Diaz', null, 'luis@test.local');

        $recibo = CurrentAcount::create([
            'user_id'     => $this->dueno->id,
            'client_id'   => $luis->id,
            'status'      => 'pago_from_client',
            'num_receipt' => 77,
            'detalle'     => 'Pago N°77',
            'haber'       => 30000,
            'debe'        => 0,
            'saldo'       => 0,
            'moneda_id'   => 1,
        ]);

        list($conversation, $assistant) = $this->vuelta('mandale el recibo 77 a Luis por mail');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', [
            'tipo'    => 'recibo',
            'numero'  => 77,
            'canales' => ['mail'],
        ]);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertSame('Mandar el recibo N° 77 a Luis Diaz', $tarjeta->presentacion['titulo']);
        $this->assertSame(['mail'], $tarjeta->datos['canales']);
        $this->assertStringContainsString('/current-acount/pdf/' . $recibo->id, $tarjeta->datos['pdf_url']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        Mail::assertQueued(ComercioCityMail::class, function ($mail) {
            return $mail->hasTo('luis@test.local') && $mail->payload->subject === 'Te enviamos el recibo de tu pago';
        });
    }

    // ---------------------------------------------------------------------
    // Los gates
    // ---------------------------------------------------------------------

    /**
     * Por canal: sin teléfono no va por WhatsApp, con la plantilla sin aprobar y sin ventana tampoco,
     * y la tarjeta lo dice. Si NINGÚN canal se puede, no hay tarjeta: error claro.
     *
     * @test
     */
    public function un_canal_que_no_se_puede_se_dice_y_sin_ninguno_no_hay_tarjeta()
    {
        // Plantilla sin aprobar y sin chat: por WhatsApp no; por mail sí.
        $this->desaprobar_plantilla('cc_cli_comprobante');

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900004]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(['mail'], $tarjeta->datos['canales']);

        $etiquetas = array_column($tarjeta->presentacion['renglones'], 'valor', 'etiqueta');

        $this->assertStringContainsString('plantilla de comprobante todavía no está aprobada', $etiquetas['No va por WhatsApp']);

        // Sin teléfono ni mail: no hay por dónde.
        $sin_nada = $this->cliente('Sin Datos', null, null);
        $this->presupuesto($sin_nada, 900005);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900005]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('No se lo puedo mandar a Sin Datos', $respuesta['error']);
        $this->assertStringContainsString('no tiene teléfono cargado', $respuesta['error']);
        $this->assertStringContainsString('no tiene un mail válido', $respuesta['error']);

        // Sin el módulo de WhatsApp y pidiendo WhatsApp: tampoco.
        $this->sacar_extension('whatsapp');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', [
            'tipo' => 'presupuesto', 'numero' => 900004, 'canales' => ['whatsapp'],
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('módulo de WhatsApp', $respuesta['error']);

        // Sin el módulo de mails a clientes y pidiendo mail: tampoco.
        $this->sacar_extension('enviar_mail_a_clientes');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', [
            'tipo' => 'presupuesto', 'numero' => 900004, 'canales' => ['mail'],
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('módulo de mails a clientes', $respuesta['error']);
    }

    /**
     * 🔴 Aislamiento: el número de un presupuesto de OTRO negocio no existe para este dueño.
     *
     * @test
     */
    public function el_comprobante_de_otro_negocio_no_se_encuentra()
    {
        $otro = User::create([
            'name'     => 'ZZE Otro negocio',
            'email'    => 'zze-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajeno = $this->cliente('Cliente Ajeno', '5493444009999', 'ajeno@test.local', $otro->id);
        $this->presupuesto($ajeno, 912345, 5000, $otro->id);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 912345');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 912345]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertSame('No encontré ningún presupuesto N° 912345 en este negocio.', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Un empleado sin permiso para ver los presupuestos tampoco los manda.
     *
     * @test
     */
    public function un_empleado_sin_permiso_de_ver_presupuestos_no_los_manda()
    {
        $empleado = User::create([
            'name'     => 'ZZE Empleado',
            'email'    => 'zze-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004');

        $conversation->auth_user_id = $empleado->id;
        $conversation->save();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900004]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('No tenés permiso para ver los presupuestos', $respuesta['error']);
    }

    // ---------------------------------------------------------------------
    // Nunca sola
    // ---------------------------------------------------------------------

    /**
     * 🔴 Ni el "dale" determinista ni la confirmación en el mismo turno: el sí lo resuelve el modelo,
     * en el mensaje siguiente.
     *
     * @test
     */
    public function el_dale_determinista_y_el_mismo_turno_no_la_mandan()
    {
        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');
        $this->presupuesto($marcos);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900004]);

        // En el mismo turno, la guarda de siempre.
        $mismo_turno = ConfirmacionPorTextoIaHelper::confirmar($conversation, $assistant, $propuesta['tarjeta_id']);

        $this->assertTrue(empty($mismo_turno['ok']));
        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_MISMO_TURNO, $mismo_turno['error']);

        // Un "dale" suelto en el mensaje siguiente: el determinista NO la confirma (decide el modelo).
        $assistant->estado = 'listo';
        $assistant->save();

        list($conversation, $siguiente) = $this->vuelta('dale', $conversation);

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $siguiente));

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado());
        Mail::assertNothingQueued();
    }

    /**
     * Las tres guardas de NUNCA_AUTO_CONFIRMABLES para los tres envíos: la constante, el filtro de
     * auto_confirmables_de() en los tres modos, y el `case` sin la puerta de la auto-confirmación
     * (leído como texto, igual que los tests 36, 51 y 55).
     *
     * @test
     */
    public function los_tres_envios_no_se_auto_ejecutan_en_ningun_modo()
    {
        $tipos = [
            AiMessageAction::TIPO_ENVIO_COMPROBANTE,
            AiMessageAction::TIPO_RECORDATORIOS_COBRO,
            AiMessageAction::TIPO_PROMOCION_WHATSAPP,
        ];

        foreach ($tipos as $tipo) {

            $this->assertLessThanOrEqual(30, strlen($tipo), 'La columna `tipo` es string(30).');
            $this->assertContains($tipo, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
            $this->assertNotContains($tipo, HerramientasDeCarga::AUTO_CONFIRMABLES);
            $this->assertNotContains($tipo, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);

            foreach (ConfianzaDelAgenteIaHelper::MODOS as $modo) {
                $this->assertNotContains($tipo, HerramientasDeCarga::auto_confirmables_de($modo), $tipo . ' en ' . $modo);
            }
        }

        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        foreach (['proponer_envio_de_comprobante', 'proponer_recordatorios_de_cobro', 'proponer_promocion_por_whatsapp'] as $herramienta) {

            $desde = strpos($contenido, "case '" . $herramienta . "':");

            $this->assertNotFalse($desde, $herramienta . ' no se despacha');

            $hasta = strpos($contenido, 'case ', $desde + 10);

            $this->assertStringNotContainsString('quizas_auto_confirmar', substr($contenido, $desde, $hasta - $desde), $herramienta);
        }
    }
}
