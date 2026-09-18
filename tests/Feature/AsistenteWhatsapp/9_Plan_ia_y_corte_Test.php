<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Misión foto-sucursal-y-asistente-configurable — el plan que empuja el admin y el corte por tope en
 * el canal de WhatsApp.
 *
 * Protege: el receptor PUT admin-sync/plan-ia guarda los tres campos del contrato en el dueño (y un
 * tope 0 se guarda como "sin tope"), exige la clave cuando está configurada, y el canal de WhatsApp
 * contesta con el texto de límite sin despachar el job cuando el dueño superó su tope.
 */
class Plan_ia_y_corte_Test extends AsistenteWhatsappTestCase
{
    /** @test */
    public function el_receptor_guarda_el_plan_en_el_dueno()
    {
        $r = $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 60000000,
            'tope_interacciones_diarias' => 50,
        ], $this->headers());

        $r->assertStatus(200)->assertJson(['ok' => true]);

        $dueno = $this->comercio->fresh();
        $this->assertSame('Intermedio', (string) $dueno->plan_ia_nombre);
        $this->assertSame(60000000, (int) $dueno->plan_ia_tope_tokens_mensual);
        $this->assertSame(50, (int) $dueno->plan_ia_tope_interacciones_diarias);
    }

    /** @test */
    public function un_tope_cero_se_guarda_como_sin_tope()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Sin límite',
            'tope_tokens_mensual'        => 0,
            'tope_interacciones_diarias' => 0,
        ], $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();
        $this->assertNull($dueno->plan_ia_tope_tokens_mensual);
        $this->assertNull($dueno->plan_ia_tope_interacciones_diarias);
    }

    /** @test */
    public function sin_la_clave_el_receptor_corta_con_401()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 100,
            'tope_interacciones_diarias' => 5,
        ], $this->headers(false))->assertStatus(401);
    }

    /** @test */
    public function el_canal_de_whatsapp_corta_por_tope_sin_despachar_el_job()
    {
        Queue::fake();
        $this->dar_extension(self::SLUG_ASISTENTE);

        $this->comercio->plan_ia_tope_interacciones_diarias = 3;
        $this->comercio->save();

        for ($i = 0; $i < 3; $i++) {
            AiTokenUsage::create([
                'user_id'                     => $this->comercio->id,
                'auth_user_id'                => $this->comercio->id,
                'proceso'                     => 'chat_mensaje',
                'proveedor'                   => 'anthropic',
                'modelo'                      => 'claude-sonnet-5',
                'input_tokens'                => 10,
                'output_tokens'               => 10,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
                'created_at'                  => Carbon::now(),
                'updated_at'                  => Carbon::now(),
            ]);
        }

        $this->mandar(['texto' => '¿Cómo venís hoy?', 'whatsapp_message_id' => 'wamid.tope.' . uniqid()])
             ->assertStatus(202);

        $assistant = AiMessage::where('rol', 'assistant')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($assistant);
        $this->assertSame('listo', (string) $assistant->estado);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, (string) $assistant->contenido);

        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }
}
