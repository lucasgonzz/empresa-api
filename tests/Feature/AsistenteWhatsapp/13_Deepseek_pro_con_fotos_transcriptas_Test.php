<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Http\Controllers\Helpers\asistente_ia\TranscripcionDeFotosIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\AiTokenUsage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Misión asistente-deepseek-pro-razona (24/9/2026): con DeepSeek, un turno ESCALADO con fotos razona
 * en Pro, y las fotos le llegan transcriptas por el modelo con visión.
 *
 * Pro (`deepseek-v4-pro`) no ve imágenes: medido contra la API real, con una foto contesta "No puedo
 * ver la imagen". Hasta esta misión un turno con foto corría en Flash aunque fuera escalado. Ahora:
 *   1. UNA llamada a Flash, sin pensar, con todas las fotos, que las transcribe;
 *   2. el turno entero en Pro con el thinking prendido desde la vuelta 0 y SIN bloques `image`;
 *   3. la transcripción de cada foto se cachea 24 h: las fotos reenviadas no se vuelven a pagar;
 *   4. si la transcripción falla, el turno sigue como antes (Flash con visión);
 *   5. con `services.deepseek.pro_con_transcripcion` en false, todo como antes;
 *   6. Anthropic, byte a byte igual.
 *
 * 🔴 Ningún test sale a la red: Http::fake con red de seguridad, y las claves son de prueba.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Deepseek_pro_con_fotos_transcriptas_Test extends AsistenteWhatsappTestCase
{
    /** Lo que "lee" el modelo con visión en los fakes. */
    const TRANSCRIPCION = 'Botella de aceite de girasol Natura 1,5 L. Código de barras: 7 798111 212032';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');

        /* Ids inventados a propósito: las aserciones prueban el cableado config → payload. */
        config([
            'services.anthropic.api_key'              => 'clave-anthropic-de-prueba-p13',
            'services.anthropic.model'                => 'claude-general-p13',
            'services.anthropic.model_agil'           => 'claude-agil-p13',
            'services.anthropic.model_profundo'       => 'claude-profundo-p13',
            'services.deepseek.api_key'               => 'clave-deepseek-de-prueba-p13',
            'services.deepseek.model'                 => 'deepseek-general-p13',
            'services.deepseek.model_agil'            => 'deepseek-flash-p13',
            'services.deepseek.model_profundo'        => 'deepseek-pro-p13',
            'services.deepseek.model_vision'          => 'deepseek-vision-p13',
            'services.deepseek.pro_con_transcripcion' => true,
        ]);
    }

    // ---------------------------------------------------------------------------------- helpers

    /**
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @param  int  $lado  Lado del PNG: cambia los bytes, así dos fotos no tienen el mismo hash.
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $orden = 1, $lado = 30)
    {
        $recurso = imagecreatetruecolor($lado, $lado);
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
     * La respuesta del modelo con visión: texto plano con una sección por foto.
     *
     * @param  string  $texto
     * @return array
     */
    protected function transcripcion($texto)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 900, 'output_tokens' => 60],
        ];
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
     * Una vuelta de tool_use CON su bloque `thinking`, como la devuelve un modelo que piensa.
     *
     * @return array
     */
    protected function tool_use_pensando()
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [
                ['type' => 'thinking', 'thinking' => 'La persona quiere cargar el producto.', 'signature' => 'firma'],
                ['type' => 'tool_use', 'id' => 'toolu_p13_1', 'name' => 'que_puedo_cargar', 'input' => ['entidad' => 'article']],
            ],
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
     * Cuántos bloques `image` lleva un payload entero (todos sus mensajes).
     *
     * @param  array  $messages
     * @return int
     */
    protected function imagenes_en(array $messages)
    {
        $cuantas = 0;

        foreach ($messages as $mensaje) {

            if (!is_array($mensaje['content'])) {
                continue;
            }

            foreach ($mensaje['content'] as $bloque) {

                if (($bloque['type'] ?? '') === 'image') {
                    $cuantas++;
                }
            }
        }

        return $cuantas;
    }

    /**
     * El texto del último turno del dueño de un payload, sea string o bloques.
     *
     * @param  array  $messages
     * @return string
     */
    protected function texto_del_ultimo_user(array $messages)
    {
        $ultimo = null;

        foreach ($messages as $mensaje) {

            if ($mensaje['role'] === 'user') {
                $ultimo = $mensaje;
            }
        }

        if (is_string($ultimo['content'])) {
            return $ultimo['content'];
        }

        $texto = '';

        foreach ($ultimo['content'] as $bloque) {

            if (($bloque['type'] ?? '') === 'text') {
                $texto .= $bloque['text'] . "\n";
            }
        }

        return $texto;
    }

    /**
     * Un turno con foto propia en el mensaje del dueño, listo para responder.
     *
     * @param  string  $texto
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiMessage}
     */
    protected function turno_con_foto($texto = 'Cargá este producto')
    {
        $conversation = $this->conversacion_whatsapp();
        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => $texto]);
        $this->guardar_foto($con_foto);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $assistant];
    }

    // ------------------------------------------------------------------------------------ tests

    /**
     * 🔴 El caso central. DeepSeek en "ágil" con una foto propia (el turno arranca escalado):
     * primera llamada a Flash SIN pensar con la imagen; después el turno entero en Pro CON el
     * thinking prendido y sin un solo bloque `image`, con la transcripción en el texto del dueño.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_deepseek_y_foto_transcribe_con_flash_y_razona_en_pro_pensando()
    {
        $this->dueno_en('deepseek', 'agil');

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->transcripcion('FOTO 1: ' . self::TRANSCRIPCION), 200)
                ->push($this->tool_use_pensando(), 200)
                ->push($this->end_turn('Es un aceite Natura. ¿A qué costo lo cargo?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->turno_con_foto();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Es un aceite Natura. ¿A qué costo lo cargo?', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'Una transcripción y dos vueltas del loop.');

        /* 1. La transcripción: el modelo que ve, sin pensar, con la foto. */
        $this->assertSame('deepseek-vision-p13', $bodies[0]['model']);
        $this->assertSame('disabled', $bodies[0]['thinking']['type'], 'Para leer una foto no hace falta razonar.');
        $this->assertSame(1, $this->imagenes_en($bodies[0]['messages']), 'La foto viaja a la transcripción.');
        $this->assertArrayNotHasKey('tools', $bodies[0], 'La transcripción no lleva herramientas.');
        $this->assertStringContainsString('SÓLO el tipo', $bodies[0]['system'], 'El prompt prohíbe transcribir montos de una factura.');

        /* 2. El turno: Pro pensando desde la vuelta 0, en todas las vueltas, sin imágenes. */
        foreach ([1, 2] as $indice) {
            $this->assertSame('deepseek-pro-p13', $bodies[$indice]['model'], 'Vuelta ' . $indice . ': razona Pro.');
            $this->assertSame('enabled', $bodies[$indice]['thinking']['type'], 'Vuelta ' . $indice . ': Pro piensa desde el arranque.');
            $this->assertSame(0, $this->imagenes_en($bodies[$indice]['messages']), 'Vuelta ' . $indice . ': Pro no ve imágenes, no le puede llegar ninguna.');
        }

        $texto_del_dueno = $this->texto_del_ultimo_user($bodies[1]['messages']);

        $this->assertMatchesRegularExpression('/\[Foto 1 \(la que mandó la persona hoy a las \d{2}:\d{2}\): /u', $texto_del_dueno);
        $this->assertStringContainsString('7 798111 212032', $texto_del_dueno, 'Los dígitos del código de barras llegan tal cual.');
        $this->assertStringContainsString('otro modelo que sí las ve', $texto_del_dueno, 'Pro sabe que la transcripción la hizo otro modelo.');
        $this->assertStringContainsString('Cargá este producto', $texto_del_dueno, 'El texto del dueño viaja igual.');

        /* 3. El gasto: la transcripción con su proceso, las vueltas del chat con el suyo. */
        $transcripciones = AiTokenUsage::where('ai_conversation_id', $conversation->id)
            ->where('proceso', TranscripcionDeFotosIaHelper::PROCESO)
            ->get();

        $this->assertCount(1, $transcripciones);
        $this->assertSame('deepseek', $transcripciones[0]->proveedor);
        $this->assertSame('deepseek-vision-p13', $transcripciones[0]->modelo);
        $this->assertSame(900, (int) $transcripciones[0]->input_tokens);

        $del_chat = AiTokenUsage::where('ai_conversation_id', $conversation->id)
            ->where('proceso', TopeDeTokensHelper::PROCESO_CHAT)
            ->pluck('modelo')
            ->all();

        $this->assertSame(['deepseek-pro-p13', 'deepseek-pro-p13'], $del_chat);

        /*
         * El tope: la transcripción NO es una interacción del dueño, pero sus tokens SÍ se pagaron y
         * cuentan en el mes (900 + 60 de la transcripción y 18 por cada una de las dos vueltas).
         */
        $estado = TopeDeTokensHelper::estado(User::find($this->comercio->id));

        $this->assertSame(2, $estado['consumo_interacciones']);
        $this->assertSame(960 + 18 + 18, $estado['consumo_tokens']);
    }

    /**
     * El caché: el turno siguiente, con la foto sin usar reenviada, no vuelve a pagar la lectura.
     * Dueño en "profundo" para que el segundo turno (sin foto propia) también vaya escalado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_turno_siguiente_con_la_foto_reenviada_no_la_vuelve_a_transcribir()
    {
        $this->dueno_en('deepseek', 'profundo');

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->transcripcion('FOTO 1: ' . self::TRANSCRIPCION), 200)
                ->push($this->end_turn('Es un aceite. ¿Lo cargo?'), 200)
                ->push($this->end_turn('Listo, lo cargo con 1500 de costo.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->turno_con_foto('Mirá este producto');

        (new AsistenteIaService())->responder($conversation, $assistant);

        $assistant->update(['estado' => 'listo', 'contenido' => 'Es un aceite. ¿Lo cargo?']);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargalo con 1500 de costo']);
        $segundo = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $segundo);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'Una sola transcripción para los dos turnos.');
        $this->assertSame('deepseek-vision-p13', $bodies[0]['model']);

        $segundo_turno = $bodies[2];

        $this->assertSame('deepseek-pro-p13', $segundo_turno['model']);
        $this->assertSame('enabled', $segundo_turno['thinking']['type']);
        $this->assertSame(0, $this->imagenes_en($segundo_turno['messages']));
        $this->assertStringContainsString('7 798111 212032', $this->texto_del_ultimo_user($segundo_turno['messages']), 'La transcripción cacheada viaja en lugar de la foto reenviada.');

        $this->assertSame(1, AiTokenUsage::where('ai_conversation_id', $conversation->id)
            ->where('proceso', TranscripcionDeFotosIaHelper::PROCESO)
            ->count());
    }

    /**
     * Si la API rechaza la transcripción, el turno cae a lo de antes: Flash con la foto y el
     * thinking del turno escalado. Nada se rompe y la persona recibe su respuesta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_la_transcripcion_falla_el_turno_sigue_con_flash_viendo_la_foto()
    {
        $this->dueno_en('deepseek', 'agil');

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push(['error' => ['type' => 'authentication_error', 'message' => 'clave inválida']], 401)
                ->push($this->end_turn('Veo un aceite.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->turno_con_foto();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Veo un aceite.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assertSame('deepseek-vision-p13', $bodies[1]['model'], 'Sin transcripción, el turno va al modelo que ve.');
        $this->assertSame('enabled', $bodies[1]['thinking']['type'], 'Con el thinking del turno escalado, como antes.');
        $this->assertSame(1, $this->imagenes_en($bodies[1]['messages']), 'Y la foto viaja como imagen.');

        $this->assertSame(0, AiTokenUsage::where('ai_conversation_id', $conversation->id)
            ->where('proceso', TranscripcionDeFotosIaHelper::PROCESO)
            ->count(), 'Un 401 no se pagó: no hay fila.');
    }

    /**
     * Lo mismo con un timeout (la excepción de conexión de Laravel): el turno no se rompe.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_timeout_de_la_transcripcion_tampoco_rompe_el_turno()
    {
        $this->dueno_en('deepseek', 'profundo');

        $llamadas = 0;
        $fin = $this->end_turn('Veo un aceite.');

        Http::fake(function ($request) use (&$llamadas, $fin) {
            $llamadas++;

            if ($llamadas === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($fin, 200);
        });

        list($conversation, $assistant) = $this->turno_con_foto();

        $this->assertSame('Veo un aceite.', (new AsistenteIaService())->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $ultimo = end($bodies);

        $this->assertSame('deepseek-vision-p13', $ultimo['model']);
        $this->assertSame(1, $this->imagenes_en($ultimo['messages']));
    }

    /**
     * Dos fotos en el mismo mensaje: una sola llamada de transcripción con las dos, y cada una con
     * su número. Si la respuesta trae sección para una sola, no se usa a medias: Flash con visión.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function dos_fotos_van_en_una_sola_transcripcion_y_a_medias_no_se_usa()
    {
        $this->dueno_en('deepseek', 'agil');

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->transcripcion("FOTO 1: Frente de una lata de arvejas.\n\n**FOTO 2:** Código de barras: 7 790580 123456"), 200)
                ->push($this->end_turn('Son arvejas.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $con_fotos = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargá esto']);
        $this->guardar_foto($con_fotos, 1, 30);
        $this->guardar_foto($con_fotos, 2, 31);
        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assertSame(2, $this->imagenes_en($bodies[0]['messages']), 'Las dos fotos en UNA llamada.');
        $this->assertSame('deepseek-pro-p13', $bodies[1]['model']);

        $texto = $this->texto_del_ultimo_user($bodies[1]['messages']);

        $this->assertStringContainsString('[Foto 1 (', $texto);
        $this->assertStringContainsString('lata de arvejas', $texto);
        $this->assertStringContainsString('[Foto 2 (', $texto);
        $this->assertStringContainsString('7 790580 123456', $texto);

        /*
         * A medias: sección para la foto 1 y nada para la 2 → no se usa. Http::swap() primero: en
         * Laravel 8 Http::fake() ACUMULA stubs, y el sequence de arriba (ya vacío) ganaría el match.
         */
        Http::swap(new \Illuminate\Http\Client\Factory());

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->transcripcion('FOTO 1: Frente de una lata de arvejas.'), 200)
                ->push($this->end_turn('Son arvejas.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $otra = $this->conversacion_whatsapp();
        $con_fotos = $this->mensaje($otra, 'user', 'listo', ['contenido' => 'Cargá esto otro']);
        $this->guardar_foto($con_fotos, 1, 32);
        $this->guardar_foto($con_fotos, 2, 33);
        $assistant = $this->mensaje($otra, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($otra, $assistant);

        $bodies = $this->bodies_enviados();
        $ultimo = end($bodies);

        $this->assertSame('deepseek-vision-p13', $ultimo['model'], 'Una transcripción a medias no se usa.');
        $this->assertSame(2, $this->imagenes_en($ultimo['messages']));
    }

    /**
     * Con el interruptor apagado, todo como antes de esta misión: una sola llamada al modelo con
     * visión, con la foto y pensando. Es la mitad "B" de la comparación.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_el_interruptor_apagado_todo_como_antes()
    {
        config(['services.deepseek.pro_con_transcripcion' => false]);

        $this->dueno_en('deepseek', 'profundo');

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->end_turn('Veo un aceite.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->turno_con_foto();

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(1, $bodies);
        $this->assertSame('deepseek-vision-p13', $bodies[0]['model']);
        $this->assertSame('enabled', $bodies[0]['thinking']['type']);
        $this->assertSame(1, $this->imagenes_en($bodies[0]['messages']));
        $this->assertSame(0, AiTokenUsage::where('proceso', TranscripcionDeFotosIaHelper::PROCESO)
            ->where('ai_conversation_id', $conversation->id)
            ->count());
    }

    /**
     * Anthropic, byte a byte igual: todos sus modelos ven, así que nunca transcribe.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_anthropic_nunca_se_transcribe()
    {
        $this->dueno_en('anthropic', 'profundo');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->end_turn('Veo un aceite.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->turno_con_foto();

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(1, $bodies);
        $this->assertSame('claude-profundo-p13', $bodies[0]['model']);
        $this->assertArrayNotHasKey('thinking', $bodies[0]);
        $this->assertSame(1, $this->imagenes_en($bodies[0]['messages']));
    }

    /**
     * Un turno que NO va escalado desde el arranque (dueño en "ágil", sin foto propia: sólo la
     * reenviada de la tanda) no transcribe: un "gracias" no paga una lectura ni el modelo caro.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_turno_agil_con_la_foto_reenviada_no_transcribe()
    {
        $this->dueno_en('deepseek', 'agil');

        Http::fake([
            'api.deepseek.com/*' => Http::response($this->end_turn('De nada.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá este producto']);
        $this->guardar_foto($con_foto);
        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Es un aceite.']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Gracias']);
        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(1, $bodies);
        $this->assertSame('deepseek-vision-p13', $bodies[0]['model']);
        $this->assertSame('disabled', $bodies[0]['thinking']['type']);
    }
}
