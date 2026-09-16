<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\AiConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Misión asistente-por-whatsapp — la política de conversaciones del canal (§3.3 del plan).
 *
 * En el sistema el dueño abre una conversación nueva con un botón y cambia entre ellas; en WhatsApp
 * no hay dónde hacer eso, así que la política está repartida: el admin resuelve la CITA (es el
 * único que conoce los wamid y manda `ai_conversation_id` solo si la dedujo de una) y este API
 * resuelve el CORTE POR TIEMPO de 6 horas.
 *
 * Lo que protege este archivo es que las dos mitades no se pisen y que ninguna se cruce de dueño.
 */
class Conversaciones_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_corte_son_seis_horas_y_esta_escrito_como_constante()
    {
        $this->assertEquals(
            6,
            AsistenteCanalHelper::HORAS_CORTE,
            'El corte de 6 horas es la decisión de Lucas en la Fase 2: no se cambia sin volver a preguntarle.'
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function sin_ninguna_conversacion_previa_se_abre_una_nueva()
    {
        $antes = AiConversation::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->mandar()->assertStatus(202);

        $this->assertEquals(
            $antes + 1,
            AiConversation::where('user_id', $this->comercio->id)->count(),
            'El primer mensaje del dueño tiene que abrir una conversación.'
        );

        $conversation = AiConversation::find($respuesta->json('ai_conversation_id'));

        $this->assertEquals(AiConversation::ORIGEN_WHATSAPP, $conversation->origen);
        $this->assertEquals($this->comercio->id, (int) $conversation->user_id);
        $this->assertEquals($this->comercio->id, (int) $conversation->auth_user_id, 'En este canal habla solo el dueño.');
        $this->assertNull($conversation->titulo, 'El título lo infiere el job, igual que en la pantalla.');
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function dentro_de_las_seis_horas_sigue_la_misma_conversacion()
    {
        $vieja = $this->conversacion_whatsapp(Carbon::now()->subHours(5)->subMinutes(30));

        $respuesta = $this->mandar()->assertStatus(202);

        $this->assertEquals(
            (int) $vieja->id,
            (int) $respuesta->json('ai_conversation_id'),
            'A cinco horas y media de la última, es la misma charla.'
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function pasadas_las_seis_horas_se_abre_una_nueva()
    {
        $vieja = $this->conversacion_whatsapp(Carbon::now()->subHours(6)->subMinutes(1));

        $respuesta = $this->mandar()->assertStatus(202);

        $this->assertNotEquals(
            (int) $vieja->id,
            (int) $respuesta->json('ai_conversation_id'),
            'Pasado el corte, el que escribe a la mañana y vuelve a la tarde arranca limpio.'
        );
    }

    /**
     * La cita: el admin mandó el `ai_conversation_id` que dedujo de un mensaje citado, y eso pasa
     * por encima del corte por tiempo aunque la conversación sea de hace días.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_ai_conversation_id_entra_en_esa_aunque_sea_vieja()
    {
        $antigua = $this->conversacion_whatsapp(Carbon::now()->subDays(9));

        $reciente = $this->conversacion_whatsapp(Carbon::now()->subMinutes(2));

        $respuesta = $this->mandar(['ai_conversation_id' => $antigua->id])->assertStatus(202);

        $this->assertEquals(
            (int) $antigua->id,
            (int) $respuesta->json('ai_conversation_id'),
            'Responder citando un mensaje viejo reabre esa conversación.'
        );

        $this->assertNotEquals((int) $reciente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * Un `ai_conversation_id` que ya no existe (el dueño borró la conversación desde el sistema)
     * NO es un error: se ignora y se sigue por el corte por tiempo. Un 422 dejaría al dueño sin
     * respuesta a un mensaje perfectamente válido.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_ai_conversation_id_que_no_existe_se_ignora_y_no_rompe()
    {
        $reciente = $this->conversacion_whatsapp(Carbon::now()->subMinutes(2));

        $respuesta = $this->mandar(['ai_conversation_id' => 99999999])->assertStatus(202);

        $this->assertEquals((int) $reciente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * 🔴 La conversación de otro dueño no se toca ni aunque el admin mande su id.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_conversacion_de_otro_dueno_no_se_toca()
    {
        $otro = $this->otro_dueno();

        $ajena = $this->conversacion_whatsapp(Carbon::now()->subMinutes(1), $otro);

        $respuesta = $this->mandar(['ai_conversation_id' => $ajena->id])->assertStatus(202);

        $this->assertNotEquals(
            (int) $ajena->id,
            (int) $respuesta->json('ai_conversation_id'),
            'Un id de otro comercio no puede meter el mensaje de este dueño en la charla del otro.'
        );

        $conversation = AiConversation::find($respuesta->json('ai_conversation_id'));

        $this->assertEquals($this->comercio->id, (int) $conversation->user_id);
    }

    /**
     * Una conversación del PANEL (origen 'usuario') no se continúa por WhatsApp: el canal abre y
     * sigue las suyas. Si no, el primer mensaje de WhatsApp caería en cualquier charla que el dueño
     * hubiera tenido abierta en la pantalla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_continua_una_conversacion_del_panel()
    {
        $del_panel = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->comercio->id,
            'last_message_at' => Carbon::now()->subMinutes(1),
        ]);

        $respuesta = $this->mandar()->assertStatus(202);

        $this->assertNotEquals((int) $del_panel->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * Sin `last_message_at` no hay con qué medir el corte, y una conversación sin actividad no es
     * "la de recién": se abre una nueva.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_conversacion_sin_actividad_no_cuenta_como_reciente()
    {
        $sin_actividad = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);

        $respuesta = $this->mandar()->assertStatus(202);

        $this->assertNotEquals((int) $sin_actividad->id, (int) $respuesta->json('ai_conversation_id'));
    }
}
