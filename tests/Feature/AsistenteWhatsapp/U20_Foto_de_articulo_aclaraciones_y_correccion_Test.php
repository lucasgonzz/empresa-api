<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Image;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Misión asistente-diario-20261118 — A1 y A2 (17/11/2026): cambiar la foto de un artículo.
 *
 * 🔴 A1 (Truvari, conv 42). La tarjeta de reemplazar una foto esperaba el sí. El dueño contestó «sí, pero
 * respetá el tamaño», el asistente volvió a preguntar en texto (sin tarjeta nueva) y el «Si» siguiente ya no
 * confirmaba nada: la regla «la tarjeta es del ÚLTIMO mensaje del asistente» la dejaba afuera. Se
 * protege acá que ese sí confirme, y que la tolerancia sea estrecha (nada de confirmar si en el medio el
 * asistente propuso otra cosa o no preguntó).
 *
 * 🔴 A2 (Ferre Total, conv 59). Con una foto ya registrada en cuatro artículos llegó otra distinta y el
 * asistente dijo «es la misma imagen» sin compararlas. Ahora la herramienta compara los archivos: la misma
 * foto da un error que lo dice con certeza, y una distinta arma una tarjeta que espera el sí con el dato.
 *
 * Y las reglas del prompt (imagen generada que no se publica, sí + aclaración, foto nueva = corrección).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 */
class U20_Foto_de_articulo_aclaraciones_y_correccion_Test extends AsistenteWhatsappTestCase
{
    /** Archivos que la confirmación dejó en storage/app/public (fuera del disco falso). */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');

        config(['services.deepseek.api_key' => 'clave-de-prueba']);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_a_limpiar as $nombre) {
            $ruta = storage_path() . '/app/public/' . $nombre;

            if ($nombre !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        parent::tearDown();
    }

    /**
     * Una foto de un color dado (dos colores = dos archivos distintos) colgada de un mensaje.
     */
    protected function guardar_foto(AiMessage $mensaje, $color = '#0B84F8')
    {
        $binario = (string) (new ImageManager())->canvas(12, 12, $color)->encode('png');
        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/1.png';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen($binario),
        ]);
    }

    protected function articulo($nombre)
    {
        return Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);
    }

    /**
     * La escena de conv 42: la foto, la tarjeta propuesta (ya 'listo'), la aclaración del dueño, la respuesta
     * del asistente SIN tarjeta nueva y el «Si». Devuelve el assistant que contesta ese «Si», todavía pendiente.
     *
     * @param  string  $respuesta_del_asistente  Lo que contestó el asistente a la aclaración.
     * @param  bool  $con_tarjeta_en_el_medio  true si esa respuesta trajo una tarjeta propia.
     */
    protected function escena_con_aclaracion($respuesta_del_asistente, $con_tarjeta_en_el_medio = false)
    {
        $articulo = $this->articulo('zz-u20 Lagarde Proyecto Hermanas');
        $conversation = $this->conversacion_whatsapp();

        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'foto']);
        $this->guardar_foto($con_foto);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['articulo_id' => $articulo->id]
        );
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $propone->estado = 'listo';
        $propone->contenido = 'Voy a reemplazar la foto. ¿Lo hago?';
        $propone->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí, hacelo, pero respetá el tamaño de la imagen, ¿se entiende?']);

        $aclara = $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => $respuesta_del_asistente, 'acciones_habilitadas' => true]);

        if ($con_tarjeta_en_el_medio) {
            AiMessageAction::create([
                'ai_conversation_id' => $conversation->id,
                'user_id'            => $this->comercio->id,
                'auth_user_id'       => $this->comercio->id,
                'ai_message_id'      => $aclara->id,
                'tipo'               => AiMessageAction::TIPO_GASTO,
                'clave'              => 'gasto:u20',
                'estado'             => AiMessageAction::ESTADO_PROPUESTA,
                'datos'              => ['monto' => 10],
                'presentacion'       => ['titulo' => 'Otra cosa'],
            ]);
        }

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Si']);
        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $contestando, (int) $respuesta['tarjeta_id'], $articulo];
    }

    protected function anotar_archivos(Article $articulo)
    {
        foreach (Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) $image->hosting_url);
        }
    }

    /**
     * 🔴 A1: el «Si» después de una aclaración contestada con otra pregunta, sin tarjeta nueva, confirma la
     * única tarjeta pendiente. Antes quedaba en manos del modelo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_si_despues_de_una_aclaracion_sin_tarjeta_nueva_confirma_la_tarjeta()
    {
        list($conversation, $contestando, $tarjeta_id, $articulo) = $this->escena_con_aclaracion('Entiendo: la foto mantiene su proporción. ¿Confirmás?');

        $resultado = ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando);

        $this->anotar_archivos($articulo);

        $this->assertNotNull($resultado, 'El sí contesta la pregunta de la tarjeta: tiene que confirmarla.');
        $this->assertSame($tarjeta_id, $resultado['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
    }

    /**
     * La tolerancia es estrecha: si lo que contestó el asistente no pregunta nada, el «Si» no se sabe a qué
     * contesta y decide el modelo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_la_respuesta_del_medio_no_pregunta_nada_no_se_confirma_sola()
    {
        list($conversation, $contestando, $tarjeta_id) = $this->escena_con_aclaracion('Entiendo, la foto mantiene su proporción.');

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Si en el medio el asistente armó OTRA tarjeta, ya hay dos pendientes (o el sí es de la otra): no se
     * confirma ninguna sola.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_en_el_medio_hubo_otra_tarjeta_no_se_confirma_sola()
    {
        list($conversation, $contestando, $tarjeta_id) = $this->escena_con_aclaracion('Te dejé otra cosa. ¿Confirmás?', true);

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    // ------------------------------------------------------------------------------------ A2

    /**
     * Registra una foto en un artículo (propuesta + confirmación como el botón) y devuelve [conversation, articulo].
     */
    protected function articulo_con_foto_registrada()
    {
        $articulo = $this->articulo('zz-u20 Destapa cano 20 mts');
        $conversation = $this->conversacion_whatsapp();

        $primera = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'a este agregale la imagen']);
        $this->guardar_foto($primera, '#0B84F8');

        $propone = $this->mensaje($conversation, 'assistant', 'listo', ['acciones_habilitadas' => true]);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['articulo_id' => $articulo->id]
        );
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->actingAs($this->comercio);
        $confirmada = ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, (int) $respuesta['tarjeta_id']);
        $this->assertTrue(!empty($confirmada['ok']), json_encode($confirmada));

        $this->anotar_archivos($articulo);

        return [$conversation, $articulo];
    }

    /**
     * 🔴 A2: la MISMA foto (otro mensaje, mismos bytes) se reconoce por el archivo y la herramienta lo dice.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_misma_foto_reenviada_se_reconoce_comparando_el_archivo()
    {
        list($conversation, $articulo) = $this->articulo_con_foto_registrada();

        $reenvio = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'esta es']);
        $this->guardar_foto($reenvio, '#0B84F8');

        $asistente = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $asistente,
            ['articulo_id' => $articulo->id]
        );

        $this->assertFalse(!empty($respuesta['ok']), 'No hay tarjeta para una foto idéntica a la ya registrada.');
        $this->assertStringContainsString('EXACTAMENTE la misma', json_encode($respuesta, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 🔴 A2: una foto DISTINTA llega después de registrar otra en el mismo artículo: tarjeta que espera el sí,
     * con el dato de que ya había una registrada (y nunca «es la misma»).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_distinta_despues_de_registrar_otra_arma_tarjeta_con_el_aviso()
    {
        list($conversation, $articulo) = $this->articulo_con_foto_registrada();

        $segunda = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'esta es la imagen que tenés que ponerle']);
        $this->guardar_foto($segunda, '#D81B60');

        $asistente = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $asistente,
            ['articulo_id' => $articulo->id]
        );

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue(!empty($respuesta['requiere_confirmacion']), 'Con otra foto ya registrada nunca se pone sola.');
        $this->assertStringContainsString('OTRA foto', $respuesta['foto_ya_registrada_antes']);
        $this->assertStringContainsString('reemplazar_fotos: true', $respuesta['foto_ya_registrada_antes']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
    }

    // ------------------------------------------------------------------------------------ prompt

    protected function prompt_de_whatsapp()
    {
        $conversation = $this->conversacion_whatsapp();

        return (new AsistenteIaService())->build_system_prompt($conversation, $this->comercio->fresh(), true, true);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_prompt_trae_las_reglas_de_cambiar_la_foto()
    {
        $prompt = $this->prompt_de_whatsapp();

        $this->assertStringContainsString('Un sí con una aclaración que NO cambia la tarjeta es un sí', $prompt);
        $this->assertStringContainsString('llamá a confirmar_carga_pendiente con esa tarjeta en ese mismo turno', $prompt);
        $this->assertStringContainsString('leer una etiqueta se equivoca', $prompt);
        $this->assertStringContainsString('NUNCA digas «es la misma imagen»', $prompt);
        $this->assertStringContainsString('reemplazar_fotos: true', $prompt);
    }

    /**
     * 🔴 A1: con pedir_imagen disponible por WhatsApp, el prompt dice desde el principio que una imagen
     * generada no se publica en el artículo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_regla_de_la_imagen_generada_esta_en_el_bloque_de_imagenes_por_whatsapp()
    {
        $servicio = new AsistenteIaService();
        $metodo = new \ReflectionMethod($servicio, 'bloque_de_la_imagen_por_whatsapp');
        $metodo->setAccessible(true);

        $bloque = $metodo->invoke($servicio);

        $this->assertStringContainsString('Una imagen GENERADA por IA NO se publica en un artículo', $bloque);
        $this->assertStringContainsString('Nunca digas «sí, puedo»', $bloque);
    }
}
