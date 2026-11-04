<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Misión asistente-fotos-barras-y-compras, correcciones del 24/9/2026 — j y k: qué fotos ve el
 * modelo y con qué modelo arranca un turno con foto.
 *
 * j) Sólo el ÚLTIMO mensaje del dueño manda sus fotos en base64. En la prueba real, dos turnos
 *    después de mandar la foto del producto el modelo pidió "decime qué ves en la imagen". Ahora,
 *    si el último mensaje no trae foto, vuelven a viajar las fotos SIN USAR de la última tanda del
 *    dueño (máximo 3, y sólo si están a 3 mensajes suyos de distancia o menos).
 *
 * k) Con el dueño en "ágil" las malas decisiones de un pedido con foto las tomaba el modelo rápido
 *    ANTES de llamar a cualquier tool de carga, así que el escalado nunca entraba. Un turno con foto
 *    arranca escalado desde la vuelta 0: Anthropic con el modelo de "profundo"; DeepSeek con el
 *    modelo con visión (Pro no ve) y el thinking prendido desde el inicio.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Fotos_al_modelo_y_escalado_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p12',
            'services.anthropic.model'          => 'claude-general-p12',
            'services.anthropic.model_agil'     => 'claude-agil-p12',
            'services.anthropic.model_profundo' => 'claude-profundo-p12',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba-p12',
            'services.deepseek.model'           => 'deepseek-general-p12',
            'services.deepseek.model_agil'      => 'deepseek-flash-p12',
            'services.deepseek.model_profundo'  => 'deepseek-pro-p12',
            'services.deepseek.model_vision'    => 'deepseek-vision-p12',
        ]);
    }

    /**
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $orden = 1)
    {
        $recurso = imagecreatetruecolor(30, 30);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/' . $orden . '.webp';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => $orden,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * @param  string  $proveedor
     * @param  string  $pensamiento
     * @return void
     */
    protected function dueno_en($proveedor, $pensamiento)
    {
        User::where('id', $this->comercio->id)->update([
            'agente_proveedor'   => $proveedor,
            'agente_pensamiento' => $pensamiento,
        ]);
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto = 'Listo.')
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * @return array<int, array>
     */
    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            $bodies[] = json_decode($par[0]->body(), true);
        }

        return $bodies;
    }

    /**
     * Cuántos bloques `image` lleva el último turno de un payload.
     *
     * @param  array  $payload
     * @return int
     */
    protected function imagenes_del_ultimo_turno(array $payload)
    {
        $ultimo = end($payload);

        if (!is_array($ultimo['content'])) {
            return 0;
        }

        $cuantas = 0;

        foreach ($ultimo['content'] as $bloque) {
            if (($bloque['type'] ?? '') === 'image') {
                $cuantas++;
            }
        }

        return $cuantas;
    }

    // ------------------------------------------------------------------------------------ j

    /**
     * 🔴 La foto del producto, una respuesta del asistente y "cargalo con 1500 de costo": la foto
     * sin usar vuelve a viajar con el último mensaje, para que el modelo la siga viendo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function las_fotos_sin_usar_de_la_ultima_tanda_vuelven_a_viajar()
    {
        $conversation = $this->conversacion_whatsapp();

        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá este producto']);
        $this->guardar_foto($con_foto);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿Qué querés hacer con él?']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargalo con 1500 de costo']);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertSame(1, $this->imagenes_del_ultimo_turno($payload), 'La foto sin usar tiene que viajar con el último mensaje.');

        $ultimo = end($payload);
        $this->assertSame('text', $ultimo['content'][1]['type'], 'Las imágenes primero y el texto al final, como siempre.');
        $this->assertStringContainsString('Cargalo con 1500 de costo', $ultimo['content'][1]['text']);
        $this->assertTrue(is_string($payload[0]['content']), 'En su propio turno la foto sigue viajando como "[Foto adjunta]".');
    }

    /**
     * Los topes: máximo 3 fotos (las más nuevas), nunca una ya usada, y nada si la tanda quedó a
     * más de 3 mensajes del dueño de distancia.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function las_fotos_de_la_tanda_tienen_tope_y_distancia()
    {
        $conversation = $this->conversacion_whatsapp();

        $con_fotos = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá']);

        $fotos = [];

        for ($i = 1; $i <= 4; $i++) {
            $fotos[] = $this->guardar_foto($con_fotos, $i);
        }

        AiMessageImagen::where('id', $fotos[3]->id)->update(['gestionada_at' => Carbon::now()]);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargalas']);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertSame(3, $this->imagenes_del_ultimo_turno($payload), 'Tres sin usar: las tres viajan (la usada no).');

        // Cuatro mensajes del dueño más tarde, la tanda ya es de otra charla.
        for ($i = 0; $i < 3; $i++) {
            $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Respuesta ' . $i]);
            $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Otra cosa ' . $i]);
        }

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertSame(0, $this->imagenes_del_ultimo_turno($payload));
    }

    /**
     * La foto de internet (colgada del mensaje del ASISTENTE por la búsqueda por código de barras)
     * no es una foto del dueño: no viaja como si la hubiera mandado él.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_internet_no_vuelve_a_viajar_como_del_dueno()
    {
        $conversation = $this->conversacion_whatsapp();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Buscá el 7790387000014']);

        $del_asistente = $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Es una yerba.']);
        $this->guardar_foto($del_asistente);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargala']);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertSame(0, $this->imagenes_del_ultimo_turno($payload));
    }

    /**
     * 🔴 Misión agente-mejoras-diario (análisis del 31/10/2026, #4): el dueño mandó a la mañana la
     * foto del QR, se le contestó, y dos horas y media después pegó una planilla de ventas. La foto
     * seguía «sin gestionar» y estaba a un solo mensaje suyo de distancia, así que volvía a viajar con
     * la planilla y la nota «Van de nuevo las fotos…»: el modelo le contestó otra vez lo del QR. Con el
     * tope de tiempo (más de 30 minutos entre la foto y el último mensaje del dueño) la foto ya no viaja
     * en base64 ni va la nota; las herramientas la siguen encontrando 24 horas por su cuenta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_hace_horas_no_vuelve_a_viajar_con_otro_tema()
    {
        $conversation = $this->conversacion_whatsapp();

        $con_foto  = $this->mensaje($conversation, 'user', 'listo', ['contenido' => '']);
        $foto      = $this->guardar_foto($con_foto);
        $respuesta = $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Corregí la provincia y tocá Crear QR.']);

        // La foto y su respuesta, dos horas y media antes del mensaje nuevo.
        $hace = Carbon::now()->subMinutes(150);
        AiMessage::whereIn('id', [$con_foto->id, $respuesta->id])->update(['created_at' => $hace]);
        AiMessageImagen::where('id', $foto->id)->update(['created_at' => $hace]);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => "C FINAL\t59.999,00\t04-oct\tSKU 17006", 'canal' => AiMessage::CANAL_SISTEMA]);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertSame(0, $this->imagenes_del_ultimo_turno($payload), 'Una foto de hace horas no viaja con un mensaje de otro tema.');
        $this->assertStringNotContainsString('Van de nuevo', json_encode(end($payload), JSON_UNESCAPED_UNICODE), 'Sin la foto tampoco va la nota de que vuelve a viajar.');
    }

    /**
     * El tope de tiempo no corta la charla de un momento: la foto de hace 20 minutos (menos que el
     * tope) sigue viajando con el mensaje nuevo, y la nota dice que es la MISMA foto de antes, para
     * que el modelo no la tome como una planilla nueva ni cambie lo ya acordado por releerla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_hace_un_rato_vuelve_a_viajar_como_referencia()
    {
        $conversation = $this->conversacion_whatsapp();

        $con_foto  = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá este producto']);
        $foto      = $this->guardar_foto($con_foto);
        $respuesta = $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿Qué querés hacer con él?']);

        $hace = Carbon::now()->subMinutes(20);
        AiMessage::whereIn('id', [$con_foto->id, $respuesta->id])->update(['created_at' => $hace]);
        AiMessageImagen::where('id', $foto->id)->update(['created_at' => $hace]);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargalo con 1500 de costo']);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertSame(1, $this->imagenes_del_ultimo_turno($payload), 'Dentro del tope la foto sin usar sigue viajando.');

        $ultimo = end($payload);
        $this->assertStringContainsString('las MISMAS fotos que mandó antes', $ultimo['content'][1]['text']);
        $this->assertStringContainsString('no digas que llegó otra planilla', $ultimo['content'][1]['text']);
    }

    // ------------------------------------------------------------------------------------ k

    /**
     * Y un turno SIN foto con el dueño en "ágil" sigue arrancando en el ágil: el escalado por foto
     * no encarece las consultas.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_foto_el_turno_sigue_arrancando_en_agil()
    {
        $this->dueno_en('anthropic', 'agil');

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->end_turn('Vendiste 3.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => '¿Cuánto vendí hoy?']);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(config('services.deepseek.model_agil'), $this->bodies_enviados()[0]['model']);
    }


}
