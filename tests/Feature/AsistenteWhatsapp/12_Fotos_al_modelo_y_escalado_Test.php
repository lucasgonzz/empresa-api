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

    // ------------------------------------------------------------------------------------ k

    /**
     * 🔴 Anthropic en "ágil": un turno con foto arranca con el modelo de "profundo" desde la
     * primera llamada, sin esperar a que el modelo pida una carga.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_anthropic_un_turno_con_foto_arranca_en_profundo()
    {
        $this->dueno_en('anthropic', 'agil');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->end_turn('Veo una botella.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargá este producto']);
        $this->guardar_foto($con_foto);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(1, $bodies);
        $this->assertSame('claude-profundo-p12', $bodies[0]['model']);
        $this->assertArrayNotHasKey('thinking', $bodies[0], 'Anthropic no lleva clave thinking.');
    }

    /**
     * 🔴 DeepSeek en "ágil": un turno con foto va al modelo con visión (Pro no ve) CON el thinking
     * prendido desde la vuelta 0. Y la vuelta siguiente, con el bloque `thinking` que devolvió el
     * modelo en el historial, lo mantiene prendido (no es el 400 de prenderlo a mitad de turno).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_deepseek_un_turno_con_foto_arranca_con_vision_y_pensando()
    {
        $this->dueno_en('deepseek', 'agil');

        $con_pensamiento = [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [
                ['type' => 'thinking', 'thinking' => 'La persona quiere cargar el producto.', 'signature' => 'firma'],
                ['type' => 'tool_use', 'id' => 'toolu_p12_1', 'name' => 'que_puedo_cargar', 'input' => ['entidad' => 'article']],
            ],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($con_pensamiento, 200)
                ->push($this->end_turn('¿A qué precio lo cargo?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargá este producto']);
        $this->guardar_foto($con_foto);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);

        foreach ($bodies as $indice => $body) {
            $this->assertSame('deepseek-vision-p12', $body['model'], 'Vuelta ' . $indice . ': Pro no ve imágenes.');
            $this->assertSame('enabled', $body['thinking']['type'], 'Vuelta ' . $indice . ': el turno con foto piensa desde el inicio.');
        }
    }

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
            'api.anthropic.com/*' => Http::response($this->end_turn('Vendiste 3.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => '¿Cuánto vendí hoy?']);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('claude-agil-p12', $this->bodies_enviados()[0]['model']);
    }

    /**
     * ⚠️ Segundo chequeo adversarial: las fotos REENVIADAS de la última tanda viajan (el modelo las
     * sigue viendo) pero no escalan. Un "gracias" después de una foto no arranca en el modelo caro.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function las_fotos_reenviadas_viajan_pero_no_escalan()
    {
        $this->dueno_en('anthropic', 'agil');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->end_turn('De nada.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá este producto']);
        $this->guardar_foto($con_foto);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Es un termo.']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Gracias']);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $body = $this->bodies_enviados()[0];

        $this->assertSame('claude-agil-p12', $body['model'], 'Un "gracias" no se paga con el modelo caro.');
        $this->assertSame(1, $this->imagenes_del_ultimo_turno($body['messages']), 'La foto sin usar viaja igual.');
    }
}
