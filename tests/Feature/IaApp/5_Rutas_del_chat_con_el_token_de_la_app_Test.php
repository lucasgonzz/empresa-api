<?php

namespace Tests\Feature\IaApp;

use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Misión app-ia-y-memoria — las rutas del chat que ya existían andan con el token de la app (contrato
 * C5: "sin cambios; tienen que andar con Bearer").
 *
 * La app no tiene cookie: entra con el token `ia-app`. Lo que protege este archivo es que la
 * configuración del asistente, el consumo y la foto de un mensaje contesten igual que con la cookie, y
 * que ninguna toque el candado de sesión única (`session_id` / `last_activity`).
 */
class Rutas_del_chat_con_el_token_de_la_app_Test extends IaAppTestCase
{
    public function test_la_configuracion_y_el_consumo_del_asistente_andan_con_bearer()
    {
        $this->getJson('api/user/asistente-config', $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonStructure(['confianza', 'pensamiento']);

        $this->como_otro_cliente();

        $this->putJson('api/user/asistente-config', ['confianza' => 'cauteloso', 'pensamiento' => 'agil'], $this->de_la_app())
            ->assertStatus(200);

        $this->assertSame('cauteloso', User::find($this->dueno->id)->agente_confianza);

        $this->como_otro_cliente();

        $this->getJson('api/mi-consumo-ia', $this->de_la_app())->assertStatus(200);
    }

    public function test_la_foto_de_un_mensaje_se_baja_con_bearer()
    {
        $conversation = $this->conversacion(['titulo' => 'Fotos']);

        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'estado'             => 'listo',
            'contenido'          => 'la factura',
            'canal'              => 'app',
        ]);

        $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $mensaje->id . '/1.webp';

        // El endpoint lee con storage_path(): el disco local REAL, que se limpia al terminar.
        Storage::disk('local')->put($path, 'bytes-de-la-foto');
        $this->limpiar_al_final('asistente_imagenes/' . $this->dueno->id . '/' . $mensaje->id);

        AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => 16,
        ]);

        $this->get('api/ai-mensajes/' . $mensaje->id . '/imagen/1', $this->de_la_app())->assertStatus(200);
    }

    public function test_ninguna_toca_el_candado_de_sesion_unica()
    {
        $hace_un_rato = Carbon::now()->subMinutes(5)->startOfSecond();

        User::where('id', $this->dueno->id)->update(['session_id' => 'la-caja', 'last_activity' => $hace_un_rato]);

        foreach (['api/ia-app/yo', 'api/ai-conversations', 'api/user/asistente-config', 'api/mi-consumo-ia'] as $ruta) {
            $this->como_otro_cliente();
            $this->getJson($ruta, $this->de_la_app())->assertStatus(200);
        }

        $despues = User::find($this->dueno->id);

        $this->assertSame('la-caja', $despues->session_id);
        $this->assertSame($hace_un_rato->toDateTimeString(), Carbon::parse($despues->last_activity)->toDateTimeString());
    }
}
