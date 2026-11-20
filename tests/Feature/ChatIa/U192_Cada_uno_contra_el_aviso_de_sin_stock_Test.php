<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ReferenciaALoAnteriorIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119, rama B — A7: «Ponele 4 a cada uno» después de un aviso de sin stock.
 *
 * 🔴 EL CASO REAL (Truvari, conv 43, 18/11/2026). La conversación la abrió el aviso automático «📦 Se quedaron sin
 * stock: Escorihuela Gascon Chardonnay y LAGARDE CHARDONNAY» (646) y un segundo después llegó el recordatorio de las
 * 08:00 (647). Fernando contestó «Ponele 4 unidades de stock a cada uno» (648) y el asistente: «No tengo en esta
 * conversación una lista de artículos». Dos causas, medidas en el código:
 *   1. el historial que ve el modelo tiene que empezar por 'user', y los dos avisos del arranque se TIRABAN;
 *   2. la referencia («cada uno») no estaba en la lista cerrada y, aunque estuviera, se resolvía solo contra el
 *      último mensaje del asistente (el recordatorio).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 *
 * @group chat-ia
 */
class U192_Cada_uno_contra_el_aviso_de_sin_stock_Test extends TestCase
{
    use DatabaseTransactions;

    const AVISO = "📦 Se quedaron sin stock: zz-u192 Escorihuela Gascon Chardonnay y zz-u192 LAGARDE CHARDONNAY.\nSi querés, te ayudo a reponerlos.";

    const RECORDATORIO = "⏰ Te recuerdo: *Revisar si ingresó un pedido nuevo en la tienda online y avisar* (hoy 08:00).\nCuando lo hagas, avisame y lo marco como hecho.";

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Vinoteca U192',
            'company_name' => 'Bebidas U192',
            'email'        => 'cada-uno-u192-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        Article::create(['name' => 'zz-u192 Escorihuela Gascon Chardonnay', 'user_id' => $this->comercio->id, 'status' => 'active', 'stock' => 0]);
        Article::create(['name' => 'zz-u192 LAGARDE CHARDONNAY', 'user_id' => $this->comercio->id, 'status' => 'active', 'stock' => 0]);
    }

    protected function mensaje(AiConversation $conversation, $rol, $contenido, $estado = 'listo')
    {
        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => $rol,
            'contenido'            => $contenido,
            'estado'               => $estado,
            'acciones_habilitadas' => $rol === 'assistant',
            'canal'                => AiMessage::CANAL_WHATSAPP,
        ]);
    }

    /** La conv 43: aviso, recordatorio, «Ponele 4… a cada uno» y el assistant que lo contesta. */
    protected function la_conv_43()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);

        $this->mensaje($conversation, 'assistant', self::AVISO);
        $this->mensaje($conversation, 'assistant', self::RECORDATORIO);
        $this->mensaje($conversation, 'user', 'Ponele 4 unidades de stock a cada uno');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        return [$conversation, $contestando];
    }

    protected function texto_del_turno(array $turno)
    {
        if (is_string($turno['content'])) {
            return $turno['content'];
        }

        return json_encode($turno['content'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 🔴 Causa 1: los avisos con los que arranca la conversación viajan al modelo, detrás de un turno del sistema que
     * dice qué son.
     *
     * @test
     */
    public function los_avisos_del_arranque_entran_en_el_historial()
    {
        list($conversation, $contestando) = $this->la_conv_43();

        $turnos = (new AsistenteIaService())->build_messages_payload($conversation, (int) $contestando->id);

        $this->assertSame('user', $turnos[0]['role']);
        $this->assertStringContainsString('esta conversación arrancó con mensajes que le mandaste vos', $this->texto_del_turno($turnos[0]));

        $todo = '';
        foreach ($turnos as $turno) {
            $todo .= $this->texto_del_turno($turno) . "\n";
        }

        $this->assertStringContainsString('Se quedaron sin stock', $todo, 'El aviso de sin stock tiene que llegarle al modelo.');
        $this->assertStringContainsString('Te recuerdo', $todo);
        $this->assertStringContainsString('Ponele 4 unidades de stock a cada uno', $this->texto_del_turno(end($turnos)));
    }

    /**
     * Un historial RECORTADO (conversación larga cuyo primer mensaje que entra es del asistente) sigue descartando
     * como siempre: el turno del sistema es solo para el arranque real.
     *
     * @test
     */
    public function el_recorte_de_una_conversacion_larga_no_suma_el_turno_del_sistema()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);

        for ($i = 0; $i < AsistenteIaService::MAX_MENSAJES_HISTORIAL / 2 + 1; $i++) {
            $this->mensaje($conversation, 'user', 'pregunta ' . $i);
            $this->mensaje($conversation, 'assistant', 'respuesta ' . $i);
        }

        $this->mensaje($conversation, 'user', 'una más');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $turnos = (new AsistenteIaService())->build_messages_payload($conversation, (int) $contestando->id);

        $this->assertSame('user', $turnos[0]['role']);
        $this->assertStringNotContainsString('esta conversación arrancó con mensajes', $this->texto_del_turno($turnos[0]));
    }

    /**
     * 🔴 Causa 2: «cada uno» es una referencia, y se resuelve contra la lista del aviso aunque el último mensaje del
     * asistente sea el recordatorio.
     *
     * @test
     */
    public function cada_uno_se_resuelve_contra_el_aviso_y_no_contra_el_recordatorio()
    {
        list($conversation, $contestando) = $this->la_conv_43();

        $nota = ReferenciaALoAnteriorIaHelper::nota($conversation, $contestando);

        $this->assertNotNull($nota);
        $this->assertStringContainsString('«cada uno»', $nota);
        $this->assertStringContainsString('zz-u192 Escorihuela Gascon Chardonnay', $nota);
        $this->assertStringContainsString('zz-u192 LAGARDE CHARDONNAY', $nota);
        $this->assertStringContainsString('incluidos los avisos automáticos', $nota);
    }

    /**
     * La ventana es la de lo que la persona tiene delante: una lista de ANTES de su mensaje anterior no se cuela.
     *
     * @test
     */
    public function una_lista_de_antes_del_mensaje_anterior_de_la_persona_no_se_cuela()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);

        $this->mensaje($conversation, 'assistant', self::AVISO);
        $this->mensaje($conversation, 'user', 'Gracias, después veo');
        $this->mensaje($conversation, 'assistant', 'De nada. Te recuerdo la reunión de las 10.');
        $this->mensaje($conversation, 'user', 'Ponele 4 unidades de stock a cada uno');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $nota = ReferenciaALoAnteriorIaHelper::nota($conversation, $contestando);

        $this->assertNotNull($nota, 'Hay referencia: se manda el recorte de la última respuesta, como siempre.');
        $this->assertStringNotContainsString('articulo_id', $nota);
    }

    /**
     * «cada uno» sin un verbo de carga no es una referencia (la lista sigue cerrada por el verbo).
     *
     * @test
     */
    public function cada_uno_sin_verbo_de_carga_no_es_referencia()
    {
        $this->assertFalse(ReferenciaALoAnteriorIaHelper::usa_una_referencia('¿Cuánto sale cada uno?'));
        $this->assertTrue(ReferenciaALoAnteriorIaHelper::usa_una_referencia('Ponele 4 unidades de stock a cada uno'));
        $this->assertTrue(ReferenciaALoAnteriorIaHelper::usa_una_referencia('Cambiá el precio de cada una'));
    }

    /**
     * El prompt de carga trae la regla.
     *
     * @test
     */
    public function el_prompt_trae_la_regla_de_cada_uno()
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->comercio->fresh(), true, true);

        $this->assertStringContainsString('«A cada uno», «a ambos», «a esos»', $prompt);
        $this->assertStringContainsString('Un recordatorio o un aviso de otra cosa en el medio no la tapa', $prompt);
    }
}
