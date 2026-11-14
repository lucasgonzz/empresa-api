<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ColumnasDeLaAppIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContenidoDeFotosIaHelper as Contenido;
use App\Http\Controllers\Helpers\asistente_ia\ConversacionesAnterioresIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FotosDeLaConversacionIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Exceptions\AsistenteIaException;
use App\Models\AiMessageImagen;
use App\Models\AiPlanilla;
use App\Models\AiTokenUsage;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Toda foto de un turno de DeepSeek se analiza una sola vez con Claude y queda en texto (misión agente-venta-por-planilla,
 * parte 2, pedido de Lucas del 02/11/2026). Ver ContenidoDeFotosIaHelper.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Fotos_en_texto_Test extends PlanillaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Modelo ficticio distinto del Haiku fijo de respaldo: los dos usan el mismo proveedor y prompt.
        config(['services.fotos_del_asistente.activa' => true, 'services.fotos_del_asistente.modelo' => 'claude-analisis-c109']);

        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->save();

        // Una foto común: el clasificador dice que no es una planilla.
        $this->clasificador = false;
    }

    /**
     * Las llamadas de análisis a Claude.
     *
     * @return array
     */
    protected function analisis_con_claude()
    {
        $pedidos = [];

        foreach (Http::recorded() as $par) {

            $cuerpo = json_decode($par[0]->body(), true);

            if (($cuerpo['model'] ?? '') === config('services.fotos_del_asistente.modelo') && is_string($cuerpo['system'] ?? null) && mb_strpos($cuerpo['system'], 'Sos un transcriptor de fotos') !== false) {
                $pedidos[] = $cuerpo;
            }
        }

        return $pedidos;
    }

    /**
     * Las transcripciones temporales usan el Haiku fijo de develop, distinto del análisis persistente.
     *
     * @return array
     */
    protected function transcripciones_de_respaldo()
    {
        $pedidos = [];

        foreach (Http::recorded() as $par) {

            $cuerpo = json_decode($par[0]->body(), true);

            if (($cuerpo['model'] ?? '') === ProveedorIaHelper::MODELO_HAIKU_IMAGENES && is_string($cuerpo['system'] ?? null) && mb_strpos($cuerpo['system'], 'Sos un transcriptor de fotos') !== false) {
                $pedidos[] = $cuerpo;
            }
        }

        return $pedidos;
    }

    /** La falta de lectura se informa por el error controlado, antes de conversar con una imagen. */
    protected function assert_falla_tecnica_al_responder($conversation, $assistant)
    {
        try {
            $this->responder($conversation, $assistant);
            $this->fail('Sin una lectura completa de la foto el turno tiene que fallar.');
        } catch (AsistenteIaException $e) {
            $this->assertSame(AsistenteIaException::MOTIVO_FALLA_TECNICA, $e->motivo());
        }
    }

    /**
     * @test
     */
    public function una_foto_se_analiza_una_sola_vez_con_claude_y_queda_guardada()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '¿Qué marca es?');
        $foto = $this->foto($pedido);

        $this->responder($conversation, $this->assistant($conversation));

        $foto = $foto->fresh();

        $this->assertSame('Un martillo galponero marca Acme, con mango de madera.', $foto->contenido);
        $this->assertSame('lo-que-diga-el-proveedor', $foto->contenido_modelo);
        $this->assertNotNull($foto->contenido_at);

        $analisis = $this->analisis_con_claude();

        $this->assertCount(1, $analisis);
        $this->assertSame('claude-analisis-c109', $analisis[0]['model']);
        $this->assertArrayNotHasKey('temperature', $analisis[0]);
        $this->assertArrayNotHasKey('thinking', $analisis[0]);
        $this->assertStringContainsString('¿Qué marca es?', json_encode($analisis[0]['messages'], JSON_UNESCAPED_UNICODE), 'Con el pedido de la persona.');

        // El gasto, con su proveedor y modelo reales.
        $uso = AiTokenUsage::where('ai_conversation_id', $conversation->id)->where('proceso', 'chat_transcripcion_foto')->first();

        $this->assertNotNull($uso);
        $this->assertSame('anthropic', $uso->proveedor);

        // Segundo turno: no se vuelve a analizar.
        $this->del_dueno($conversation, 'Gracias');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(1, $this->analisis_con_claude());

        // Ni aunque pase el tiempo: no vence.
        Carbon::setTestNow(Carbon::now()->addDays(3));

        $this->del_dueno($conversation, '¿Y ahora?');
        $this->responder($conversation, $this->assistant($conversation));

        Carbon::setTestNow();

        $this->assertCount(1, $this->analisis_con_claude());
    }

    /**
     * 🔴 Ningún request a DeepSeek lleva una imagen si la foto tiene contenido: ni la del último mensaje ni la de la tanda.
     *
     * @test
     */
    public function deepseek_no_ve_la_imagen_de_una_foto_analizada()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $pedido = $this->del_dueno($conversation, '¿Qué marca es?');
        $foto = $this->foto($pedido);

        $this->responder($conversation, $this->assistant($conversation));

        $this->del_dueno($conversation, '¿De qué color es el mango?');
        $this->responder($conversation, $this->assistant($conversation));

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertCount(2, $cuerpos);

        foreach ($cuerpos as $cuerpo) {

            $json = json_encode($cuerpo['messages'], JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('"type":"image"', $json);
            $this->assertStringContainsString('[Foto que mandó el dueño, ya analizada: Un martillo galponero marca Acme', $json);
            $this->assertStringContainsString('[imagen_id ' . $foto->id . ']', $json, 'Las herramientas la siguen pudiendo usar por su id.');
            $this->assertStringContainsString('no le digas a la persona que no podés ver la foto', $json);
        }

        // Sin la nota de «van de nuevo las mismas fotos»: ya no van.
        $this->assertStringNotContainsString('Van de nuevo', json_encode($cuerpos[1]['messages'], JSON_UNESCAPED_UNICODE));

        // Y sigue sin gestionar: nadie la usó todavía.
        $this->assertNull($foto->fresh()->gestionada_at);
        $this->assertCount(1, FotosDeLaConversacionIaHelper::tanda_de((int) $conversation->id, (int) $this->dueno->id, (int) $pedido->id));
    }

    /**
     * Una foto vieja llega como texto, en el mensaje con el que llegó: no es un agregado al último.
     *
     * @test
     */
    public function una_foto_vieja_llega_como_texto_en_su_lugar()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $pedido = $this->del_dueno($conversation, 'Mirá este martillo');
        $foto = $this->foto($pedido);

        $foto->contenido = 'Un martillo viejo con mango rojo.';
        $foto->contenido_modelo = 'claude-haiku-5-5';
        $foto->gestionada_at = Carbon::now();
        $foto->save();

        $viejo = $this->assistant($conversation);
        $viejo->contenido = 'Lindo martillo.';
        $viejo->estado = 'listo';
        $viejo->save();

        foreach (['Otra cosa', 'Y otra más', 'Una tercera'] as $texto) {

            $this->del_dueno($conversation, $texto);

            $respuesta = $this->assistant($conversation);
            $respuesta->contenido = 'Bien.';
            $respuesta->estado = 'listo';
            $respuesta->save();
        }

        $this->del_dueno($conversation, '¿Cuánto vendí hoy?');

        $this->responder($conversation, $this->assistant($conversation));

        $mensajes = $this->requests_del_modelo_que_conversa()[0]['messages'];

        $this->assertStringContainsString('Mirá este martillo', json_encode($mensajes[0], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('[Foto que mandó el dueño, ya analizada: Un martillo viejo con mango rojo.]', json_encode($mensajes[0], JSON_UNESCAPED_UNICODE));

        $ultimo = json_encode($mensajes[count($mensajes) - 1], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('¿Cuánto vendí hoy?', $ultimo);
        $this->assertStringNotContainsString('Un martillo viejo con mango rojo', $ultimo);

        $this->assertSame([], $this->analisis_con_claude(), 'Una foto que ya tiene su texto no se analiza.');
    }

    /**
     * El tope por foto, con su aviso.
     *
     * @test
     */
    public function una_foto_larga_se_corta_en_el_historial_con_aviso()
    {
        $this->analisis_de_la_foto = 'FOTO 1: ' . str_repeat('palabra ', 1000);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, 'Mirá'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertGreaterThan(Contenido::LARGO_EN_EL_HISTORIAL, mb_strlen($foto->fresh()->contenido), 'Se guarda entero.');

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('[…sigue, cortado por largo]', $json);
        $this->assertLessThan(Contenido::LARGO_EN_EL_HISTORIAL + 1500, mb_substr_count($json, 'palabra') * 8);
    }

    /**
     * F4: el turno con foto propia sigue yendo a Pro con thinking, aunque ya no haya imágenes.
     *
     * @test
     */
    public function el_turno_con_foto_propia_sigue_en_pro_con_thinking()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $this->foto($this->del_dueno($conversation, 'Dame de alta este martillo'));

        $this->responder($conversation, $this->assistant($conversation));

        $cuerpo = $this->requests_del_modelo_que_conversa()[0];

        $this->assertSame('deepseek-pro-c107', $cuerpo['model']);
        $this->assertSame('enabled', $cuerpo['thinking']['type']);

        // Y un turno sin foto propia, con la foto en el historial, no se encarece.
        $this->del_dueno($conversation, 'Gracias');
        $this->responder($conversation, $this->assistant($conversation));

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertSame('deepseek-flash-c107', $cuerpos[count($cuerpos) - 1]['model']);
    }

    /**
     * Una configuración legada Anthropic también usa Haiku para las fotos y DeepSeek para conversar.
     *
     * @test
     */
    public function con_la_configuracion_legada_anthropic_se_conversa_por_texto_en_deepseek()
    {
        $this->dueno->agente_proveedor = 'anthropic';
        $this->dueno->save();

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame('Un martillo galponero marca Acme, con mango de madera.', $foto->fresh()->contenido);
        $this->assertCount(1, $this->analisis_con_claude());

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('"type":"image"', $json);
        $this->assertStringContainsString('ya analizada', $json);

        // El texto guardado de otro momento también sigue viajando sin releer la foto.
        $foto = $foto->fresh();
        $foto->contenido = 'Texto de cuando era de DeepSeek.';
        $foto->save();

        $this->del_dueno($conversation, 'Gracias');
        $this->responder($conversation, $this->assistant($conversation));

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertStringContainsString('Texto de cuando era de DeepSeek', json_encode($cuerpos[count($cuerpos) - 1]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $this->analisis_con_claude());
        $this->assert_deepseek_conversa_sin_imagenes();
    }

    /**
     * Si fallan las dos lecturas de Haiku, el turno falla sin inventar contenido ni mandar imágenes a DeepSeek.
     *
     * @test
     */
    public function con_haiku_caido_falla_sin_guardar_contenido_y_reintenta_el_analisis_despues_de_cinco_minutos()
    {
        $this->analisis_de_la_foto = null;
        $this->transcripcion_de_respaldo = null;
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

        $this->assert_falla_tecnica_al_responder($conversation, $this->assistant($conversation));

        $this->assertCount(1, $this->analisis_con_claude());
        $this->assertCount(1, $this->transcripciones_de_respaldo());
        $this->assertNull($foto->fresh()->contenido, 'Una lectura fallida no deja contenido.');
        $this->assertNull($foto->fresh()->contenido_at);
        $this->assertSame([], $this->requests_del_modelo_que_conversa(), 'Sin lectura no se conversa sobre la foto.');

        // El análisis persistente espera cinco minutos; el respaldo puede volver a intentar leer la foto.
        $this->del_dueno($conversation, 'Gracias');
        $this->assert_falla_tecnica_al_responder($conversation, $this->assistant($conversation));

        $this->assertCount(1, $this->analisis_con_claude());

        $this->assertCount(2, $this->transcripciones_de_respaldo());
        $this->assertSame([], $this->requests_del_modelo_que_conversa());
        $this->assertNull($foto->fresh()->contenido);

        // Pasados los 5 minutos y con Claude de vuelta, se analiza y queda.
        $this->analisis_de_la_foto = 'FOTO 1: Ahora sí se vio.';

        Carbon::setTestNow(Carbon::now()->addMinutes(Contenido::MINUTOS_PARA_REINTENTAR + 1));

        try {
            $this->del_dueno($conversation, '¿La viste?');
            $this->responder($conversation, $this->assistant($conversation));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('Ahora sí se vio.', $foto->fresh()->contenido);
        $this->assertCount(2, $this->analisis_con_claude());
        $this->assert_deepseek_conversa_sin_imagenes();
    }

    /**
     * El respaldo de Haiku permite conversar si falló el análisis persistente, pero su texto temporal no se guarda.
     *
     * @test
     */
    public function el_respaldo_de_haiku_solo_envia_texto_a_deepseek_y_no_persiste_la_lectura_fallida()
    {
        $this->analisis_de_la_foto = null;
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(1, $this->analisis_con_claude());
        $this->assertCount(1, $this->transcripciones_de_respaldo());
        $this->assertNull($foto->fresh()->contenido);
        $this->assertNull($foto->fresh()->contenido_modelo);
        $this->assertNull($foto->fresh()->contenido_at);
        $this->assertNull($foto->fresh()->gestionada_at);

        $this->assertStringContainsString('Lectura transitoria con Haiku', json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assert_deepseek_conversa_sin_imagenes();

        // Dentro de los cinco minutos no se repaga el análisis fallido ni la transcripción temporal ya cacheada.
        $this->del_dueno($conversation, 'Gracias');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertCount(1, $this->analisis_con_claude());
        $this->assertCount(1, $this->transcripciones_de_respaldo());
        $this->assertNull($foto->fresh()->contenido);
        $this->assertCount(2, $this->requests_del_modelo_que_conversa());
        $this->assert_deepseek_conversa_sin_imagenes();
    }

    /**
     * Sin clave de Anthropic no hay lector: se frena el turno sin delegar la imagen ni guardar contenido.
     *
     * @test
     */
    public function sin_clave_de_anthropic_falla_sin_enviar_la_imagen_a_deepseek()
    {
        config(['services.anthropic.api_key' => null]);

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

        $this->assert_falla_tecnica_al_responder($conversation, $this->assistant($conversation));

        $this->assertNull($foto->fresh()->contenido);
        $this->assertSame([], $this->analisis_con_claude());
        $this->assertSame([], $this->transcripciones_de_respaldo());
        $this->assertSame([], $this->requests_del_modelo_que_conversa());
    }

    /**
     * Sin la columna de persistencia, Haiku sigue transcribiendo sin guardar contenido ni enviar imágenes a DeepSeek.
     *
     * @test
     */
    public function sin_la_columna_se_transcribe_con_haiku_sin_persistir()
    {
        $columnas = new \ReflectionProperty(ColumnasDeLaAppIaHelper::class, 'columnas');
        $columnas->setAccessible(true);

        $antes = $columnas->getValue();

        $columnas->setValue(null, array_merge($antes, ['ai_message_imagenes.contenido' => false]));

        try {

            $this->falsear_ia();

            $conversation = $this->conversacion();
            $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

            $this->responder($conversation, $this->assistant($conversation));

            $this->assertSame([], $this->analisis_con_claude());
            $this->assertCount(1, $this->transcripciones_de_respaldo());
            $this->assertNull($foto->fresh()->contenido);
            $this->assert_deepseek_conversa_sin_imagenes();

        } finally {

            $columnas->setValue(null, $antes);
        }
    }

    /**
     * Apagar la persistencia mantiene la transcripción temporal de Haiku.
     *
     * @test
     */
    public function con_la_persistencia_apagada_haiku_transcribe_sin_guardar_contenido()
    {
        config(['services.fotos_del_asistente.activa' => false]);

        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame([], $this->analisis_con_claude());
        $this->assertCount(1, $this->transcripciones_de_respaldo());
        $this->assertNull($foto->fresh()->contenido);
        $this->assert_deepseek_conversa_sin_imagenes();
    }

    /**
     * Comparte el tope de tiempo del turno: si no queda, la foto va por el camino viejo y se reintenta después.
     *
     * @test
     */
    public function sin_tiempo_en_el_turno_no_se_analiza()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, 'Mirá'));

        Contenido::analizar_las_nuevas($conversation, $this->assistant($conversation), $this->dueno->fresh(), time() - 101);

        $this->assertSame([], $this->analisis_con_claude());
        $this->assertNull($foto->fresh()->contenido);
    }

    /**
     * F2 y F3: una planilla no se vuelca: su contenido es la tabla, y al modelo le llega la marca.
     *
     * @test
     */
    public function una_planilla_no_se_vuelca_en_el_historial()
    {
        $this->clasificador = true;
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, 'Cargá estas ventas'));

        $this->responder($conversation, $this->assistant($conversation));

        $foto = $foto->fresh();

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertStringContainsString('Ana Prueba Uno', $foto->contenido);
        $this->assertStringStartsWith(Contenido::PREFIJO_DE_TABLA, $foto->contenido_modelo);
        $this->assertSame([], $this->analisis_con_claude(), 'Ya se leyó: sin otra llamada.');

        $json = json_encode($this->requests_del_modelo_que_conversa()[0]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('[Foto: planilla N°' . $planilla->id . ' ya leída', $json);
        $this->assertStringNotContainsString('Ana Prueba Uno', $json);
        $this->assertStringNotContainsString('"type":"image"', $json);
    }

    /**
     * Una tabla que no es de ventas queda como contenido, y es lo que recibe el modelo.
     *
     * @test
     */
    public function una_tabla_que_no_es_de_ventas_queda_como_contenido()
    {
        $this->clasificador = true;
        $this->todos_leen([['77900011', 'Martillo galponero', '12', '15.000,00']], ['CODIGO', 'DESCRIPCION', 'STOCK', 'PRECIO']);
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Cuánto stock hay?'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertStringContainsString('Martillo galponero | 12', $foto->fresh()->contenido);
        $this->assertFalse(Contenido::es_una_planilla($foto->fresh()));
        $this->assertSame([], $this->analisis_con_claude());
    }

    /**
     * F6: agrega la respuesta, no pregunta dos veces y respeta el tope por turno.
     *
     * @test
     */
    public function volver_a_mirar_agrega_y_respeta_el_tope()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, '¿Qué marca es?'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->del_dueno($conversation, '¿Qué lote dice?');
        $assistant = $this->assistant($conversation);

        $primera = $this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué lote dice?'], $conversation, $assistant);

        $this->assertTrue($primera['ok'], json_encode($primera));
        $this->assertSame('El lote dice L-204.', $primera['respuesta']);
        $this->assertSame((int) $foto->id, $primera['imagen_id']);

        $contenido = $foto->fresh()->contenido;

        $this->assertStringContainsString('Un martillo galponero', $contenido);
        $this->assertStringContainsString("Pregunta: ¿Qué lote dice?\nRespuesta: El lote dice L-204.", $contenido);

        $preguntas = function () {

            $cuantas = 0;

            foreach (Http::recorded() as $par) {

                $cuerpo = json_decode($par[0]->body(), true);

                if (is_string($cuerpo['system'] ?? null) && mb_strpos($cuerpo['system'], 'contestá SOLO esta pregunta') !== false) {
                    $cuantas++;
                }
            }

            return $cuantas;
        };

        $this->assertSame(1, $preguntas());

        // Lo mismo: ya está, y no se vuelve a pagar ni cuenta.
        $repetida = $this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Que lote dice'], $conversation, $assistant);

        $this->assertSame('El lote dice L-204.', $repetida['respuesta']);
        $this->assertSame(1, $preguntas());

        // La segunda de verdad, por su id.
        $this->assertTrue($this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué color tiene?', 'imagen_id' => $foto->id], $conversation, $assistant)['ok']);

        // Y la tercera, no.
        $tercera = $this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué dice atrás?'], $conversation, $assistant);

        $this->assertFalse($tercera['ok']);
        $this->assertSame('sin_mas_miradas', $tercera['codigo']);
        $this->assertSame(2, $preguntas());

        // En el turno siguiente vuelve a poder.
        $this->del_dueno($conversation, 'Mirá atrás');

        $this->assertTrue($this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué dice atrás?'], $conversation, $this->assistant($conversation))['ok']);

        // Lo que se preguntó viaja con la foto.
        $this->del_dueno($conversation, 'Gracias');
        $this->responder($conversation, $this->assistant($conversation));

        $cuerpos = $this->requests_del_modelo_que_conversa();

        $this->assertStringContainsString('El lote dice L-204.', json_encode($cuerpos[count($cuerpos) - 1]['messages'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * F6: no sirve para una planilla, y solo mira las fotos de esta conversación.
     *
     * @test
     */
    public function volver_a_mirar_no_toca_una_planilla_ni_una_foto_ajena()
    {
        $this->clasificador = true;
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, 'Cargá'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->del_dueno($conversation, 'En la foto el renglón 2 dice otra cosa');

        $respuesta = $this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué dice el renglón 2?', 'imagen_id' => $foto->id], $conversation, $this->assistant($conversation));

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('es_una_planilla', $respuesta['codigo']);

        $otra = $this->conversacion();
        $this->del_dueno($otra, 'Mirá');

        $ajena = $this->herramienta('volver_a_mirar_la_foto', ['pregunta' => '¿Qué dice?', 'imagen_id' => $foto->id], $otra, $this->assistant($otra));

        $this->assertFalse($ajena['ok']);
    }

    /**
     * F6: está al final de la lista, y el prompt dice que primero va el texto.
     *
     * @test
     */
    public function la_herramienta_y_la_regla_estan()
    {
        $nombres = HerramientasDeCarga::nombres();

        $this->assertSame('volver_a_mirar_la_foto', $nombres[count($nombres) - 1]);

        $prompt = (new AsistenteIaService())->build_system_prompt($this->conversacion(), $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('Foto que mandó el dueño, ya analizada', $prompt);
        $this->assertStringContainsString('volver_a_mirar_la_foto', $prompt);
        $this->assertStringContainsString('no sirve para cambiar celdas de una planilla', $prompt);
    }

    /**
     * F4: las herramientas que usan el archivo por su id siguen encontrando la foto.
     *
     * @test
     */
    public function una_foto_analizada_se_sigue_pudiendo_usar_por_su_id()
    {
        $this->falsear_ia();

        $conversation = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversation, 'Mirá'));

        $this->responder($conversation, $this->assistant($conversation));

        $this->assertNotNull($foto->fresh()->contenido);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $encontrada = FotosDeLaConversacionIaHelper::por_id($contexto, (int) $foto->id);

        $this->assertNotNull($encontrada);
        $this->assertInstanceOf(AiMessageImagen::class, $encontrada);
        $this->assertSame((int) $foto->id, (int) $encontrada->id);
    }

    /**
     * F7: al leer una conversación anterior se ve lo que había en la foto, sin su id y sin volcar una planilla.
     *
     * @test
     */
    public function una_conversacion_anterior_muestra_lo_que_habia_en_la_foto()
    {
        $vieja = $this->conversacion();

        $foto = $this->foto($this->del_dueno($vieja, 'Mirá este martillo'));

        $foto->contenido = 'Un martillo viejo con mango rojo.';
        $foto->contenido_modelo = 'claude-haiku-5-5';
        $foto->save();

        $de_planilla = $this->foto($this->del_dueno($vieja, 'Cargá esto'), 1500, 200, 2);

        $de_planilla->contenido = 'Ana Prueba Uno | 1.000,00';
        $de_planilla->contenido_modelo = Contenido::PREFIJO_DE_TABLA . 'claude-opus-5-5';
        $de_planilla->save();

        $leido = ConversacionesAnterioresIaHelper::leer($this->conversacion(), ['conversacion_id' => $vieja->id]);

        $json = json_encode($leido, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('[Foto que mandó el dueño, ya analizada: Un martillo viejo con mango rojo.]', $json);
        $this->assertStringNotContainsString('Ana Prueba Uno', $json);
    }
}
