<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CargaSinPropuestaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\SiATarjetaPendienteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\SiQueRearmaLaCargaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119, rama B — A5: doble confirmación tras un «sí».
 *
 * 🔴 EL CASO REAL (Ferre Total, conv 72, 18/11/2026). En el 669 quedaron una actualización masiva (subcategoría de
 * 30 tornillos) y una foto; Pablo pidió dejar la foto que tenían (670), el asistente canceló la foto y preguntó
 * «Queda solo la subcategoría… ¿La registro?» (671) sin tarjeta nueva. El «Si» (672) recibía la nota de
 * SiQueRearmaLaCargaIaHelper («no habías armado ninguna propuesta, armala ahora»): el modelo armó OTRA masiva y
 * contestó «Confirmame el sí una vez más» (673). Recién el segundo «Si» (674) la registró.
 *
 * Se protege: que ese sí reciba la nota con LA tarjeta del 669 (y no la de rearmar), que la tolerancia sea estrecha
 * (sin pregunta, con otra acción en el medio, con un envío o por el panel no hay nota), que una tarjeta vieja de
 * más atrás no se cuele, que la masiva no se confirme sola en código, la guarda de «¿Los publico?» y
 * «¿Confirmás los 145…?» sin propuesta, y la regla del prompt.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class U190_Un_solo_si_para_una_tarjeta_ya_armada_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => null,
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-u190',
            'services.deepseek.model'          => 'deepseek-general-u190',
            'services.deepseek.model_agil'     => 'deepseek-flash-u190',
            'services.deepseek.model_profundo' => 'deepseek-pro-u190',
            'services.deepseek.model_vision'   => 'deepseek-vision-u190',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio un solo sí U190',
            'company_name' => 'Ferretería U190',
            'email'        => 'un-solo-si-u190-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    protected function conversacion()
    {
        return AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);
    }

    protected function mensaje(AiConversation $conversation, $rol, $contenido, $estado = 'listo', $canal = AiMessage::CANAL_WHATSAPP)
    {
        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => $rol,
            'contenido'            => $contenido,
            'estado'               => $estado,
            'acciones_habilitadas' => $rol === 'assistant',
            'canal'                => $canal,
        ]);
    }

    protected function tarjeta(AiConversation $conversation, AiMessage $mensaje, $tipo, $estado = AiMessageAction::ESTADO_PROPUESTA, $titulo = 'Actualización masiva')
    {
        return AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'ai_message_id'      => $mensaje->id,
            'tipo'               => $tipo,
            'clave'              => $tipo . ':u190:' . uniqid(),
            'estado'             => $estado,
            'datos'              => ['payload' => []],
            'presentacion'       => ['titulo' => $titulo],
        ]);
    }

    /**
     * La escena 668-672 de Ferre Total. Devuelve [conversation, assistant que contesta el «Si», masiva del 669].
     *
     * @param  string  $aclaracion  Lo que contestó el asistente (671).
     * @param  string  $canal
     */
    protected function escena_del_669($aclaracion = 'Cancelé la foto: los 30 conservan la que ya tienen. Queda solo la subcategoría: los 30 pasan a *Trompeta Madera Grueso*. ¿La registro?', $canal = AiMessage::CANAL_WHATSAPP)
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', 'Sí, sí, usa esa foto y cambia la subcategoría.', 'listo', $canal);
        $propuso = $this->mensaje($conversation, 'assistant', 'Dejo dos cargas listas: la subcategoría y la foto. ¿Registro las dos?', 'listo', $canal);
        $masiva = $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_ACTUALIZACION_MASIVA, AiMessageAction::ESTADO_PROPUESTA, 'Actualización masiva · 30 artículos');
        $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_FOTO_ARTICULO, AiMessageAction::ESTADO_CANCELADA, 'Foto de artículo');

        $this->mensaje($conversation, 'user', 'Si todos tienen foto, dejale la foto que tienen y cambiale solamente la subcategoría.', 'listo', $canal);
        $this->mensaje($conversation, 'assistant', $aclaracion, 'listo', $canal);
        $this->mensaje($conversation, 'user', 'Si', 'listo', $canal);
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente', $canal);

        return [$conversation, $contestando, $masiva];
    }

    // ---------------------------------------------------------------------
    // La causa: qué nota recibe el sí
    // ---------------------------------------------------------------------

    /**
     * 🔴 El «Si» del 672 contesta la masiva del 669: la nota de «armala de nuevo» ya no aplica y la del helper nuevo
     * nombra esa tarjeta. La masiva NO se confirma en código (NUNCA_AUTO_CONFIRMABLES): sigue siendo del modelo.
     *
     * @test
     */
    public function el_si_despues_de_cancelar_la_foto_apunta_a_la_masiva_que_quedo()
    {
        list($conversation, $contestando, $masiva) = $this->escena_del_669();

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando), 'Una masiva no se confirma sola por un sí.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $masiva->fresh()->estado_guardado());

        $this->assertFalse(SiQueRearmaLaCargaIaHelper::aplica($conversation, $contestando), 'Había propuesta: no se le dice que la arme de nuevo.');

        $tarjetas = SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando);
        $this->assertCount(1, $tarjetas, 'Solo la masiva: la foto está cancelada.');
        $this->assertSame((int) $masiva->id, (int) $tarjetas[0]->id);

        $nota = SiATarjetaPendienteIaHelper::nota($tarjetas);
        $this->assertStringContainsString('confirmar_carga_pendiente con tarjeta_id ' . $masiva->id, $nota);
        $this->assertStringContainsString('No la vuelvas a proponer', $nota);
    }

    /**
     * El turno real: la nota que viaja pegada al «Si» es la de la tarjeta, no la de rearmar, y el turno escala.
     *
     * @test
     */
    public function el_turno_manda_la_nota_de_la_tarjeta_y_no_la_de_rearmar()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push([
                    'model'       => 'deepseek-pro-u190',
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => 'Listo.']],
                    'usage'       => ['input_tokens' => 10, 'output_tokens' => 2],
                ], 200)
                ->whenEmpty(Http::response(['error' => 'agotada'], 500)),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $contestando, $masiva) = $this->escena_del_669();

        (new AsistenteIaService())->responder($conversation, $contestando);

        $body = null;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $body = json_decode($par[0]->body(), true);
                break;
            }
        }

        $this->assertNotNull($body, 'El turno tiene que haber llamado al modelo.');

        $ultimo = end($body['messages']);
        $texto = is_string($ultimo['content']) ? $ultimo['content'] : json_encode($ultimo['content'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('confirmar_carga_pendiente con tarjeta_id ' . $masiva->id, $texto);
        $this->assertStringNotContainsString('no habías armado ninguna propuesta', $texto);
        $this->assertSame('deepseek-pro-u190', $body['model'], 'Decidir una carga arranca escalado.');
    }

    /**
     * Si la aclaración no pregunta nada, el «sí» no se sabe a qué contesta: no hay nota.
     *
     * @test
     */
    public function sin_pregunta_en_la_aclaracion_no_hay_nota()
    {
        list($conversation, $contestando) = $this->escena_del_669('Cancelé la foto: los 30 conservan la que ya tienen.');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    /**
     * Si en el medio el asistente hizo otra cosa (una acción propia), la tolerancia no corre.
     *
     * @test
     */
    public function con_otra_accion_en_el_medio_no_hay_nota()
    {
        list($conversation, $contestando) = $this->escena_del_669();

        $aclaracion = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')
            ->where('id', '<', $contestando->id)->orderBy('id', 'DESC')->first();

        $this->tarjeta($conversation, $aclaracion, AiMessageAction::TIPO_GASTO, AiMessageAction::ESTADO_CONFIRMADA, 'Gasto');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    /**
     * Por el panel la confirmación es la tarjeta con su botón: no hay nota (ni confirmar_carga_pendiente).
     *
     * @test
     */
    public function por_el_panel_no_hay_nota()
    {
        list($conversation, $contestando) = $this->escena_del_669(
            'Cancelé la foto. Queda solo la subcategoría. ¿La registro?',
            AiMessage::CANAL_SISTEMA
        );

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    /**
     * Un envío a clientes esperando tiene su propio circuito (el sí al bloque recién visto): no hay nota.
     *
     * @test
     */
    public function un_envio_esperando_queda_afuera()
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', 'mandale el presupuesto a Marcos');
        $propuso = $this->mensaje($conversation, 'assistant', 'Lo mando por WhatsApp a Marcos. ¿Lo mando?');
        $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_ENVIO_COMPROBANTE, AiMessageAction::ESTADO_PROPUESTA, 'Envío');
        $this->mensaje($conversation, 'user', 'agregale el mail también');
        $this->mensaje($conversation, 'assistant', 'Va por WhatsApp y por mail. ¿Confirmás?');
        $this->mensaje($conversation, 'user', 'Si');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    /**
     * Una masiva VIEJA que quedó colgada más atrás (la del 661) no se cuela: la nota es solo la del mensaje más
     * nuevo que dejó tarjetas, y esa vieja queda fuera de la ventana de aclaraciones.
     *
     * @test
     */
    public function una_tarjeta_vieja_de_mas_atras_no_se_cuela()
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', 'A todos ponele esta imagen y activalos en la tienda.');
        $viejo = $this->mensaje($conversation, 'assistant', 'Voy a publicar los 30. ¿Lo registro?');
        $vieja = $this->tarjeta($conversation, $viejo, AiMessageAction::TIPO_ACTUALIZACION_MASIVA, AiMessageAction::ESTADO_PROPUESTA, 'Publicar 30');

        $this->mensaje($conversation, 'user', 'Haceme un listado en Excel.');
        $this->mensaje($conversation, 'assistant', 'Acá tenés el Excel.');
        $this->mensaje($conversation, 'user', 'Trompeta Madera Grueso');
        $this->mensaje($conversation, 'assistant', 'Falta la foto.');

        $this->mensaje($conversation, 'user', 'Sí, cambiá la subcategoría.');
        $propuso = $this->mensaje($conversation, 'assistant', 'Los 30 pasan a Trompeta Madera Grueso. ¿La registro?');
        $nueva = $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_ACTUALIZACION_MASIVA, AiMessageAction::ESTADO_PROPUESTA, 'Subcategoría 30');

        $this->mensaje($conversation, 'user', 'Si');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $tarjetas = SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando);

        $this->assertCount(1, $tarjetas);
        $this->assertSame((int) $nueva->id, (int) $tarjetas[0]->id);
        $this->assertNotSame((int) $vieja->id, (int) $tarjetas[0]->id);
    }

    /**
     * Un sí con algo más («sí, pero…») no es un sí corto: decide el modelo como siempre, sin nota.
     *
     * @test
     */
    public function un_si_con_algo_mas_no_tiene_nota()
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', 'Cambiá la subcategoría.');
        $propuso = $this->mensaje($conversation, 'assistant', 'Los 30 pasan a Trompeta. ¿La registro?');
        $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_ACTUALIZACION_MASIVA);
        $this->mensaje($conversation, 'user', 'si pero solo los de 6');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    // ---------------------------------------------------------------------
    // La pregunta sin propuesta («¿Los publico?») ahora se ve
    // ---------------------------------------------------------------------

    // (los tests de la revisión independiente del 19/11 están al final del archivo)

    /**
     * 🔴 687 y 721 de Ferre Total: «¿Los publico…?» y «¿Confirmás los 145…?» piden confirmar una carga. Con la
     * guarda de CargaSinPropuestaIaHelper, el turno que pregunta sin haber armado la propuesta se reintenta para
     * armarla ahí mismo, y el sí siguiente la confirma. «¿Me confirmás el nombre?» sigue siendo un dato.
     *
     * @test
     */
    public function la_guarda_ve_publicar_y_confirmar_con_cantidad()
    {
        $this->assertTrue(CargaSinPropuestaIaHelper::pide_confirmar_una_carga(
            'Para la tienda: son los 27 remaches de las medidas 3,5, 4, 5 y 6 que salieron en el Excel. ¿Los publico en la tienda junto con la foto?'
        ));
        $this->assertTrue(CargaSinPropuestaIaHelper::pide_confirmar_una_carga(
            'Lo que sí puedo dejar listo en una sola tarjeta: la foto y la activación. ¿Confirmás los 145 con las dos fotos y publicados?'
        ));
        $this->assertTrue(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar('Son 33 remaches. ¿Los activo en la tienda?'));

        $this->assertFalse(CargaSinPropuestaIaHelper::pide_confirmar_una_carga('Tengo 3 clientes con ese nombre. ¿Me confirmás el nombre?'));
        $this->assertFalse(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar('El artículo 12 está activo en la tienda.'));
    }

    // ---------------------------------------------------------------------
    // Prompt
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function el_prompt_de_whatsapp_trae_la_regla_de_un_solo_si()
    {
        $conversation = $this->conversacion();

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->comercio->fresh(), true, true);

        $this->assertStringContainsString('Un solo sí por carga', $prompt);
        $this->assertStringContainsString('nunca digas «confirmame el sí una vez más»', $prompt);

        $sin_acciones = (new AsistenteIaService())->build_system_prompt($conversation, $this->comercio->fresh(), false, true);
        $this->assertStringNotContainsString('Un solo sí por carga', $sin_acciones);
    }

    // ---------------------------------------------------------------------
    // Revisión independiente (19/11/2026): lista blanca, canal y varias tarjetas
    // ---------------------------------------------------------------------

    /**
     * 🔴 BLOQUEANTE de la revisión: una VENTA con una aclaración en el medio («hacela con 5» → «Perfecto, 5. ¿La
     * registro?» sin tarjeta nueva) NO lleva la nota: confirmar la tarjeta vieja registraría 3 martillos, no 5.
     *
     * @test
     */
    public function una_venta_con_aclaracion_en_el_medio_no_tiene_nota()
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', 'Vendele 3 martillos a Juan');
        $propuso = $this->mensaje($conversation, 'assistant', 'Venta de 3 martillos a Juan por $ 30.000. ¿La registro?');
        $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_VENTA, AiMessageAction::ESTADO_PROPUESTA, 'Venta');
        $this->mensaje($conversation, 'user', 'hacela con 5');
        $this->mensaje($conversation, 'assistant', 'Perfecto, 5 martillos. ¿La registro?');
        $this->mensaje($conversation, 'user', 'Sí');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando), 'Tampoco la confirma el código.');
    }

    /**
     * La lista blanca con aclaraciones es cerrada y sin plata; un gasto corregido en la charla tampoco lleva nota.
     *
     * @test
     */
    public function la_lista_blanca_con_aclaraciones_no_tiene_plata()
    {
        $tipos = SiATarjetaPendienteIaHelper::tipos_con_aclaraciones();

        $this->assertSame([
            AiMessageAction::TIPO_ACTUALIZACION_MASIVA,
            AiMessageAction::TIPO_FOTO_ARTICULO,
            AiMessageAction::TIPO_RENOMBRE_MASIVO,
        ], $tipos);

        foreach ([AiMessageAction::TIPO_VENTA, AiMessageAction::TIPO_PAGO, AiMessageAction::TIPO_GASTO, AiMessageAction::TIPO_PRESUPUESTO] as $plata) {
            $this->assertNotContains($plata, $tipos);
        }

        $conversation = $this->conversacion();
        $this->mensaje($conversation, 'user', 'Anotá el gasto de nafta, 5000');
        $propuso = $this->mensaje($conversation, 'assistant', 'Gasto de nafta por $ 5.000. ¿Lo registro?');
        $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_GASTO, AiMessageAction::ESTADO_PROPUESTA, 'Gasto');
        $this->mensaje($conversation, 'user', 'eran 6000');
        $this->mensaje($conversation, 'assistant', 'Entonces $ 6.000. ¿Lo registro?');
        $this->mensaje($conversation, 'user', 'Si');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    /**
     * Un renombre masivo con aclaración en el medio sí lleva la nota (está en la lista blanca).
     *
     * @test
     */
    public function un_renombre_masivo_con_aclaracion_tiene_nota()
    {
        $conversation = $this->conversacion();
        $this->mensaje($conversation, 'user', 'Cambiá ESMALTE AC. MULT. por ESMALTE ACCIÓN MÚLTIPLE');
        $propuso = $this->mensaje($conversation, 'assistant', 'Son 145 artículos. ¿Los renombro?');
        $renombre = $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_RENOMBRE_MASIVO, AiMessageAction::ESTADO_PROPUESTA, 'Renombre masivo');
        $this->mensaje($conversation, 'user', 'son todos de Sudamericana?');
        $this->mensaje($conversation, 'assistant', 'Sí, los 145 son de PINTURAS SUDAMERICANAS SRL. ¿Los renombro?');
        $this->mensaje($conversation, 'user', 'Si');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $tarjetas = SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando);

        $this->assertCount(1, $tarjetas);
        $this->assertSame((int) $renombre->id, (int) $tarjetas[0]->id);
    }

    /**
     * Revisión, punto 3: una tarjeta propuesta en la PANTALLA no recibe nota por un sí de WhatsApp (se confirma con
     * el botón).
     *
     * @test
     */
    public function una_tarjeta_propuesta_en_la_pantalla_no_tiene_nota()
    {
        $conversation = $this->conversacion();
        $this->mensaje($conversation, 'user', 'Cambiá la subcategoría', 'listo', AiMessage::CANAL_SISTEMA);
        $propuso = $this->mensaje($conversation, 'assistant', 'Los 30 pasan a Trompeta. ¿La registro?', 'listo', AiMessage::CANAL_SISTEMA);
        $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_ACTUALIZACION_MASIVA);
        $this->mensaje($conversation, 'user', 'Si');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $this->assertSame([], SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando));
    }

    /**
     * Revisión, punto 1: con varias tarjetas la nota las lista y NO ordena confirmarlas todas.
     *
     * @test
     */
    public function con_varias_tarjetas_la_nota_no_ordena_confirmar_todas()
    {
        $conversation = $this->conversacion();
        $this->mensaje($conversation, 'user', 'Ponele la foto y publicalos');
        $propuso = $this->mensaje($conversation, 'assistant', 'Dejo la foto y la publicación. ¿Registro las dos?');
        $foto = $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_FOTO_ARTICULO, AiMessageAction::ESTADO_PROPUESTA, 'Foto de artículo');
        $masiva = $this->tarjeta($conversation, $propuso, AiMessageAction::TIPO_ACTUALIZACION_MASIVA, AiMessageAction::ESTADO_PROPUESTA, 'Publicar');
        $this->mensaje($conversation, 'user', 'la foto no, solo publicalos');
        $this->mensaje($conversation, 'assistant', 'Entonces solo la publicación. ¿La registro?');
        $this->mensaje($conversation, 'user', 'Si');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $tarjetas = SiATarjetaPendienteIaHelper::tarjetas($conversation, $contestando);
        $this->assertCount(2, $tarjetas);

        $nota = SiATarjetaPendienteIaHelper::nota($tarjetas);

        $this->assertStringContainsString('#' . $foto->id, $nota);
        $this->assertStringContainsString('#' . $masiva->id, $nota);
        $this->assertStringContainsString('SOLO las que tu última pregunta incluía', $nota);
        $this->assertStringContainsString('preguntale cuál', $nota);
        $this->assertStringNotContainsString('con cada una', $nota);
    }

    /**
     * La regla del prompt dice que la tarjeta vieja no vale si la carga cambió.
     *
     * @test
     */
    public function el_prompt_dice_que_la_tarjeta_vieja_no_vale_si_cambio_la_carga()
    {
        $prompt = (new AsistenteIaService())->build_system_prompt($this->conversacion(), $this->comercio->fresh(), true, true);

        $this->assertStringContainsString('si en la charla la carga CAMBIÓ', $prompt);
        $this->assertStringContainsString('Nunca confirmes la vieja con', $prompt);
    }
}
