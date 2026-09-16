<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Misión asistente-por-whatsapp — las fotos que el dueño manda por WhatsApp (§3.4 del plan).
 *
 * 🔴 El test que más importa de este archivo es el del BASE64: solo el último mensaje del usuario
 * manda sus fotos, y las anteriores viajan como "[Foto adjunta]". Sin esa regla, cada turno reenvía
 * todo el historial de imágenes y el costo de una conversación crece sin techo — cinco fotos
 * charladas a la mañana se pagarían de nuevo en cada pregunta de la tarde.
 */
class Imagenes_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();

        Queue::fake();

        // Las fotos van al disco 'local' (privado): se aísla para no ensuciar storage/app.
        Storage::fake('local');
    }

    /**
     * Una foto cualquiera, con el tamaño que devuelve una cámara.
     *
     * @param  string  $nombre
     * @return \Illuminate\Http\UploadedFile
     */
    protected function foto($nombre = 'factura.jpg')
    {
        return UploadedFile::fake()->image($nombre, 800, 1000);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_se_guarda_redimensionada_en_el_disco_privado()
    {
        // El POST del mensaje con fotos es multipart: se arma con call() porque postJson no sube
        // archivos, que es exactamente como lo manda el admin.
        $respuesta = $this->call(
            'POST',
            'api/admin-sync/asistente/mensajes',
            ['texto' => 'Esto es la compra de Distribuidora Sur', 'tipo' => 'imagen'],
            [],
            ['imagenes' => [$this->foto()]],
            $this->transformHeadersToServerVars($this->headers())
        );

        $respuesta->assertStatus(202);

        $user = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))
            ->where('rol', 'user')
            ->orderBy('id', 'DESC')
            ->first();

        $imagenes = AiMessageImagen::where('ai_message_id', $user->id)->get();

        $this->assertCount(1, $imagenes);

        $imagen = $imagenes[0];

        $this->assertEquals($this->comercio->id, (int) $imagen->user_id, 'La tenencia va desnormalizada, sin join.');
        $this->assertEquals(1, (int) $imagen->orden);
        $this->assertEquals('image/webp', $imagen->mime, 'Se guarda re-encodeada como webp, igual que el escaneo.');
        $this->assertNull($imagen->gestionada_at, 'Una foto recién llegada todavía no la usó ninguna carga.');

        $this->assertStringContainsString(
            'asistente_imagenes/' . $this->comercio->id . '/' . $user->id . '/',
            $imagen->path
        );

        Storage::disk('local')->assertExists($imagen->path);

        $this->assertGreaterThan(0, (int) $imagen->bytes);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function mas_de_tres_fotos_en_un_mensaje_es_422_y_no_deja_nada()
    {
        $this->assertEquals(3, AsistenteImagenHelper::MAX_IMAGENES);

        $respuesta = $this->call(
            'POST',
            'api/admin-sync/asistente/mensajes',
            ['texto' => 'Cuatro fotos', 'tipo' => 'imagen'],
            [],
            ['imagenes' => [$this->foto('a.jpg'), $this->foto('b.jpg'), $this->foto('c.jpg'), $this->foto('d.jpg')]],
            $this->transformHeadersToServerVars($this->headers())
        );

        $respuesta->assertStatus(422);

        $this->assertEquals(
            0,
            AiMessageImagen::where('user_id', $this->comercio->id)->count(),
            'Un 422 de validación se decide ANTES de crear el mensaje: no puede dejar ni una foto ni un mensaje sin contestar.'
        );

        $this->assertEquals(0, AiMessage::whereIn('ai_conversation_id', function ($q) {
            $q->select('id')->from('ai_conversations')->where('user_id', $this->comercio->id);
        })->count());
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function un_archivo_que_no_es_imagen_soportada_es_422()
    {
        $respuesta = $this->call(
            'POST',
            'api/admin-sync/asistente/mensajes',
            ['texto' => 'Te mando el pdf', 'tipo' => 'imagen'],
            [],
            ['imagenes' => [UploadedFile::fake()->create('factura.pdf', 40)]],
            $this->transformHeadersToServerVars($this->headers())
        );

        $respuesta->assertStatus(422);
    }

    /**
     * 🔴 Solo el ÚLTIMO mensaje del usuario manda sus fotos en base64. Ver el docblock de la clase.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function solo_el_ultimo_mensaje_del_usuario_manda_las_fotos_en_base64()
    {
        $conversation = $this->conversacion_whatsapp();

        $viejo = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá esta factura']);
        $this->guardar_foto($viejo);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿De qué proveedor es?']);

        $nuevo = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Y esta otra también']);
        $this->guardar_foto($nuevo);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertCount(3, $payload);

        $this->assertTrue(
            is_string($payload[0]['content']),
            'El mensaje viejo viaja como texto: su foto ya se pagó una vez.'
        );

        $this->assertStringContainsString(
            '[Foto adjunta]',
            $payload[0]['content'],
            'La IA tiene que saber que hubo una foto, para poder decir "la que me mandaste recién".'
        );

        $this->assertTrue(is_array($payload[2]['content']), 'El último mensaje del usuario viaja como bloques.');

        $this->assertEquals('image', $payload[2]['content'][0]['type']);
        $this->assertEquals('base64', $payload[2]['content'][0]['source']['type']);
        $this->assertNotEmpty($payload[2]['content'][0]['source']['data']);

        $this->assertEquals('text', $payload[2]['content'][1]['type'], 'Las imágenes van primero y el texto al final.');
        $this->assertEquals('Y esta otra también', $payload[2]['content'][1]['text']);
    }

    /**
     * 🔴 El media_type sale DE LOS BYTES y nunca de la columna: si la columna dijera una cosa y el
     * archivo fuera otra, Anthropic rebota el request entero con un 400 que no nombra la imagen.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_media_type_sale_de_los_bytes_y_no_de_la_columna()
    {
        $conversation = $this->conversacion_whatsapp();

        $mensaje = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Esta foto']);

        // Se guarda un PNG de verdad pero con la columna mintiendo.
        $imagen = $this->guardar_foto($mensaje, imagecreatetruecolor(20, 20));

        $imagen->mime = 'image/jpeg';
        $imagen->save();

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertEquals(
            'image/png',
            $payload[0]['content'][0]['source']['media_type'],
            'Los bytes no mienten; la columna sí puede.'
        );
    }

    /**
     * Una foto cuyo archivo ya no está se saltea sin voltear el mensaje: el texto del dueño vale
     * por sí solo y el asistente puede contestar que no le llegó la foto.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_sin_archivo_se_saltea_y_el_texto_viaja_igual()
    {
        $conversation = $this->conversacion_whatsapp();

        $mensaje = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Esta es la factura']);

        AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => 'asistente_imagenes/no/existe/1.webp',
            'mime'          => 'image/webp',
            'bytes'         => 100,
        ]);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertCount(1, $payload);
        $this->assertEquals('Esta es la factura', $payload[0]['content'], 'Sin foto legible, el turno vuelve a ser texto plano.');
    }

    /**
     * El chat de la pantalla, sin fotos, arma EXACTAMENTE el mismo payload que antes de la misión:
     * `content` string y nada de bloques.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_conversacion_sin_fotos_arma_el_payload_de_siempre()
    {
        $conversation = $this->conversacion_whatsapp();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'hola']);
        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'hola, ¿qué necesitás?']);

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertCount(2, $payload);
        $this->assertEquals(['role' => 'user', 'content' => 'hola'], $payload[0]);
        $this->assertEquals(['role' => 'assistant', 'content' => 'hola, ¿qué necesitás?'], $payload[1]);
    }

    /**
     * Guarda una foto de verdad en el disco fake y deja su fila.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  mixed  $recurso  Recurso GD, o null para un webp cualquiera.
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $recurso = null)
    {
        if (is_null($recurso)) {
            $recurso = imagecreatetruecolor(30, 30);
        }

        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $orden = AiMessageImagen::where('ai_message_id', $mensaje->id)->count() + 1;

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
}
