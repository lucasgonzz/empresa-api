<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Pending;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;

/**
 * Misión asistente-por-whatsapp — la confirmación por texto (§3.5 del plan).
 *
 * Por WhatsApp no hay tarjeta que tocar: el asistente dice los datos exactos, pregunta, y cuando el
 * dueño contesta que sí, la IA llama a confirmar_carga_pendiente.
 *
 * 🔴 EL TEST QUE NO SE NEGOCIA es el del mismo turno. Si la IA pudiera proponer y confirmar en una
 * sola pasada del loop, el dueño se enteraría del gasto cuando ya está registrado — que es
 * exactamente lo contrario de lo que el flujo de tarjetas vino a garantizar. La guarda compara
 * `ai_message_actions.ai_message_id` contra el assistant que se está generando.
 *
 * Las tarjetas de este archivo son tareas de la agenda (tarea_nueva): es la carga que no depende de
 * cajas ni del fixture de plata, igual que en 11_Acciones_esquema_y_endpoints_Test.
 */
class Confirmacion_por_texto_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();
    }

    /**
     * Una tarjeta de tarea nueva, colgada de un mensaje del assistant.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $overrides
     * @return \App\Models\AiMessageAction
     */
    protected function tarjeta($conversation, $mensaje, array $overrides = [])
    {
        return AiMessageAction::create(array_merge([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $mensaje->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $conversation->auth_user_id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva:wsp',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [
                'detalle'               => 'Pagar el alquiler ' . uniqid(),
                'fecha_realizacion'     => Carbon::today()->addDays(3)->format('Y-m-d'),
                'es_recurrente'         => false,
                'unidad_frecuencia_id'  => null,
                'cantidad_frecuencia'   => null,
                'fecha_fin_recurrencia' => null,
                'expense_concept_id'    => null,
                'expense_amount'        => null,
                'notas'                 => null,
            ],
            'presentacion'       => [
                'titulo'    => 'Tarea en la agenda',
                'renglones' => [['etiqueta' => 'Qué', 'valor' => 'Pagar el alquiler']],
                'aviso'     => null,
            ],
        ], $overrides));
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function las_dos_herramientas_solo_se_declaran_en_el_canal_whatsapp()
    {
        $servicio = new AsistenteIaService();

        $del_sistema = array_column($servicio->build_tools(true, false), 'name');

        $this->assertNotContains('confirmar_carga_pendiente', $del_sistema, 'En la pantalla la confirmación es el botón.');
        $this->assertNotContains('cancelar_carga_pendiente', $del_sistema);
        $this->assertNotContains('proponer_compra_con_factura', $del_sistema, 'Las fotos no existen en el canal del sistema.');

        $de_whatsapp = array_column($servicio->build_tools(true, true), 'name');

        $this->assertContains('confirmar_carga_pendiente', $de_whatsapp);
        $this->assertContains('cancelar_carga_pendiente', $de_whatsapp);
        $this->assertContains('proponer_compra_con_factura', $de_whatsapp);

        $this->assertContains('proponer_gasto', $de_whatsapp, 'Las de siempre siguen estando.');
    }

    /**
     * 🔴 Las dos puntas de toda herramienta del canal viven juntas en HerramientasDeCarga: la
     * definición y su `case` en el despacho. Es la misma regla que ya verifica
     * 15_Acciones_service_y_job_Test para las diez de la pantalla, extendida a las del canal.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function toda_herramienta_del_canal_declarada_tiene_su_despacho_en_el_mismo_archivo()
    {
        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        $nombres = HerramientasDeCarga::nombres(true);

        $this->assertCount(13, $nombres, 'Diez de la pantalla más las tres del canal de WhatsApp.');

        foreach ($nombres as $nombre) {
            $this->assertStringContainsString(
                "case '" . $nombre . "':",
                $contenido,
                'La herramienta ' . $nombre . ' está declarada y no se despacha: la IA la llamaría y recibiría "Tool desconocida".'
            );
        }

        $this->assertTrue(HerramientasDeCarga::maneja('confirmar_carga_pendiente'));
        $this->assertTrue(HerramientasDeCarga::maneja('cancelar_carga_pendiente'));
    }

    /**
     * En el canal del sistema la herramienta no existe aunque alguien la nombre: la IA recibe
     * "Tool desconocida" y no confirma nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_el_canal_sistema_confirmar_es_una_tool_desconocida()
    {
        $conversation = $this->conversacion_whatsapp();

        $propuso = $this->mensaje($conversation, 'assistant', 'listo');
        $tarjeta = $this->tarjeta($conversation, $propuso);

        $del_sistema = $this->mensaje($conversation, 'assistant', 'pendiente', [
            'canal'                => AiMessage::CANAL_SISTEMA,
            'acciones_habilitadas' => true,
        ]);

        $resultado = HerramientasDeCarga::ejecutar(
            'confirmar_carga_pendiente',
            ['tarjeta_id' => $tarjeta->id],
            $conversation,
            $del_sistema
        );

        $this->assertTrue($resultado['is_error']);
        $this->assertStringContainsString('Tool desconocida', $resultado['content']);

        $this->assertEquals(
            AiMessageAction::ESTADO_PROPUESTA,
            AiMessageAction::find($tarjeta->id)->estado_guardado(),
            'La tarjeta no se puede haber tocado.'
        );
    }

    /**
     * 🔴 LA GUARDA QUE NO SE NEGOCIA.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_se_puede_confirmar_una_carga_propuesta_en_el_mismo_turno()
    {
        $conversation = $this->conversacion_whatsapp();

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        // La tarjeta la propuso ESTE mismo mensaje, que es justo lo que no se puede confirmar.
        $tarjeta = $this->tarjeta($conversation, $generandose);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $generandose, $tarjeta->id);

        $this->assertFalse($resultado['ok']);
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_MISMO_TURNO, $resultado['error']);

        $this->assertEquals(
            AiMessageAction::ESTADO_PROPUESTA,
            AiMessageAction::find($tarjeta->id)->estado_guardado(),
            'Rechazada quiere decir que la tarjeta queda intacta, esperando el sí de la persona.'
        );

        $this->assertEquals(
            0,
            Pending::where('user_id', $this->comercio->id)->count(),
            'Y sobre todo: no se registró nada.'
        );
    }

    /**
     * Lo mismo para cancelar: cancelar lo que se acaba de proponer, sin que la persona haya dicho
     * nada, es igual de raro que confirmarlo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function tampoco_se_puede_cancelar_en_el_mismo_turno()
    {
        $conversation = $this->conversacion_whatsapp();

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $tarjeta = $this->tarjeta($conversation, $generandose);

        $resultado = ConfirmacionPorTextoIaHelper::cancelar($conversation, $generandose, $tarjeta->id);

        $this->assertFalse($resultado['ok']);
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_MISMO_TURNO, $resultado['error']);

        $this->assertEquals(
            AiMessageAction::ESTADO_PROPUESTA,
            AiMessageAction::find($tarjeta->id)->estado_guardado()
        );
    }

    /**
     * El camino feliz: la tarjeta la propuso un mensaje ANTERIOR, la persona contestó que sí, y la
     * carga se registra de verdad.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function confirma_una_carga_propuesta_en_un_turno_anterior_y_la_registra()
    {
        $conversation = $this->conversacion_whatsapp();

        $propuso = $this->mensaje($conversation, 'assistant', 'listo');
        $tarjeta = $this->tarjeta($conversation, $propuso);

        // El "sí" del dueño, y el assistant que lo está contestando.
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí, dale']);
        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $antes = Pending::where('user_id', $this->comercio->id)->count();

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta->id);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));
        $this->assertEquals(AiMessageAction::ESTADO_CONFIRMADA, $resultado['estado']);
        $this->assertNotEquals('', $resultado['resultado'], 'La IA le tiene que poder repetir al dueño lo que quedó.');

        $this->assertEquals(
            $antes + 1,
            Pending::where('user_id', $this->comercio->id)->count(),
            'La tarea tiene que haber quedado en la agenda de verdad.'
        );

        $this->assertEquals(
            AiMessageAction::ESTADO_CONFIRMADA,
            AiMessageAction::find($tarjeta->id)->estado_guardado()
        );
    }

    /**
     * 🔴 La carga corre adentro de un job, sin sesión, y los helpers de plata leen la persona de
     * `Auth`. Este test fija que la autenticación quede como estaba DESPUÉS de la confirmación: un
     * worker compartido que se quedara con el usuario de este dueño firmaría la próxima carga del
     * próximo cliente con la persona equivocada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_autenticacion_de_la_persona_se_restaura_despues_de_confirmar()
    {
        $conversation = $this->conversacion_whatsapp();

        $propuso = $this->mensaje($conversation, 'assistant', 'listo');
        $tarjeta = $this->tarjeta($conversation, $propuso);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $this->assertNull(auth()->user(), 'El test arranca sin sesión, como el worker.');

        ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta->id);

        $this->assertNull(
            auth()->user(),
            'Después de confirmar, la autenticación tiene que volver a estar como estaba.'
        );
    }

    /**
     * Una tarjeta ya resuelta avisa el motivo y NO duplica la carga.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_carga_ya_confirmada_avisa_y_no_se_duplica()
    {
        $conversation = $this->conversacion_whatsapp();

        $propuso = $this->mensaje($conversation, 'assistant', 'listo');
        $tarjeta = $this->tarjeta($conversation, $propuso);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta->id);

        $despues_de_la_primera = Pending::where('user_id', $this->comercio->id)->count();

        $segundo = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta->id);

        $this->assertFalse($segundo['ok']);
        $this->assertNotNull($segundo['error'], 'La IA tiene que poder contar el motivo tal cual.');

        $this->assertEquals(
            $despues_de_la_primera,
            Pending::where('user_id', $this->comercio->id)->count(),
            'Confirmar dos veces la misma tarjeta no puede dejar dos tareas.'
        );
    }

    /**
     * Un id de tarjeta que no es de esta conversación no se toca: es la misma tenencia que el
     * botón de la pantalla, resuelta acá porque no hay request del que sacarla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_tarjeta_de_otra_conversacion_no_se_confirma()
    {
        $mia = $this->conversacion_whatsapp();
        $ajena = $this->conversacion_whatsapp(null, $this->otro_dueno());

        $propuso = $this->mensaje($ajena, 'assistant', 'listo');

        $tarjeta = AiMessageAction::create([
            'ai_conversation_id' => $ajena->id,
            'ai_message_id'      => $propuso->id,
            'user_id'            => $ajena->user_id,
            'auth_user_id'       => $ajena->auth_user_id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva:ajena',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Ajena', 'renglones' => [], 'aviso' => null],
        ]);

        $contestando = $this->mensaje($mia, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($mia, $contestando, $tarjeta->id);

        $this->assertFalse($resultado['ok']);
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_NO_ENCONTRADA, $resultado['error']);

        $this->assertEquals(
            AiMessageAction::ESTADO_PROPUESTA,
            AiMessageAction::find($tarjeta->id)->estado_guardado()
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function cancelar_una_carga_de_un_turno_anterior_la_deja_cancelada_sin_registrar_nada()
    {
        $conversation = $this->conversacion_whatsapp();

        $propuso = $this->mensaje($conversation, 'assistant', 'listo');
        $tarjeta = $this->tarjeta($conversation, $propuso);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $antes = Pending::where('user_id', $this->comercio->id)->count();

        $resultado = ConfirmacionPorTextoIaHelper::cancelar($conversation, $contestando, $tarjeta->id);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $this->assertEquals(
            AiMessageAction::ESTADO_CANCELADA,
            AiMessageAction::find($tarjeta->id)->estado_guardado()
        );

        $this->assertEquals($antes, Pending::where('user_id', $this->comercio->id)->count());
    }

    /**
     * El prompt del canal REEMPLAZA lo de la tarjeta: sin eso, el asistente le diría al dueño "te
     * dejé la tarjeta para que la confirmes", el dueño la buscaría, no la encontraría, y el gasto
     * no se cargaría nunca.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_prompt_de_whatsapp_corrige_lo_de_la_tarjeta_y_solo_va_en_ese_canal()
    {
        $servicio = new AsistenteIaService();

        $conversation = $this->conversacion_whatsapp();

        $de_whatsapp = $servicio->build_system_prompt($conversation, $this->comercio, true, true);

        $this->assertStringContainsString('Estás hablando por WhatsApp', $de_whatsapp);
        $this->assertStringContainsString('confirmar_carga_pendiente', $de_whatsapp);
        $this->assertStringContainsString('REEMPLAZA lo de la tarjeta', $de_whatsapp);
        $this->assertStringContainsString('No podés proponer y confirmar en el mismo mensaje', $de_whatsapp);

        $del_sistema = $servicio->build_system_prompt($conversation, $this->comercio, true, false);

        $this->assertStringNotContainsString('Estás hablando por WhatsApp', $del_sistema);
        $this->assertStringNotContainsString('confirmar_carga_pendiente', $del_sistema);

        // Y el bloque de carga de siempre no se fue a ningún lado.
        $this->assertStringContainsString('Qué podés cargar, siempre con una tarjeta que la persona confirma:', $del_sistema);
        $this->assertStringContainsString('Qué podés cargar, siempre con una tarjeta que la persona confirma:', $de_whatsapp);
    }

    /**
     * Sin las herramientas de carga no hay nada que confirmar, así que el renglón de la
     * confirmación por texto no se escribe.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_acciones_el_prompt_de_whatsapp_no_habla_de_confirmar()
    {
        $conversation = $this->conversacion_whatsapp();

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->comercio, false, true);

        $this->assertStringContainsString('Estás hablando por WhatsApp', $prompt);
        $this->assertStringNotContainsString('confirmar_carga_pendiente', $prompt);
        $this->assertStringContainsString('Solo podés LEER', $prompt, 'Sin acciones, el renglón de solo lectura de siempre.');
    }
}
