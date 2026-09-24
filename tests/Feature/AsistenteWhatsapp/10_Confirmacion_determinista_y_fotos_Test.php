<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoSucursalIaHelper;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Image;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Misión asistente-fotos-barras-y-compras (24/9/2026) — A1 y A2: el "sí" del dueño que confirma la
 * tarjeta sin pasar por el modelo, y la foto que las herramientas encuentran solas aunque se haya
 * charlado en el medio.
 *
 * 🔴 EL CASO QUE SOSTIENE A1 (demo3, conv 10, 24/9/2026): "Dale" a la tarjeta de la foto de un
 * artículo, una sola vuelta de 28 tokens, ninguna herramienta, y el asistente dijo "la foto quedó
 * asignada" con la tarjeta todavía en `propuesta`. Acá el proveedor de IA está falseado para
 * contestar SÓLO texto —exactamente lo que hizo el modelo aquel día— y la tarjeta tiene que quedar
 * confirmada igual, con la foto colgada del artículo.
 *
 * 🔴 EL CASO QUE SOSTIENE A2 (mismo día, misma conversación): después, el asistente pidió que le
 * reenviaran la foto, que seguía sin gestionar: la ventana de seis mensajes ya la había dejado
 * afuera. Y la contracara que no se puede romper: la foto que la búsqueda por código de barras
 * cuelga del mensaje del ASISTENTE no es una foto del dueño.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Confirmacion_determinista_y_fotos_Test extends AsistenteWhatsappTestCase
{
    /** Archivos que la asignación dejó en storage/app/public (fuera del disco falso). */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');

        // El loop necesita UNA clave para salir; la red está falseada con Http::fake.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
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
     * Una foto de verdad en el disco falso, colgada de un mensaje.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $orden = 1)
    {
        $binario = (string) (new ImageManager())->canvas(12, 12, '#0B84F8')->encode('png');

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/' . $orden . '.png';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => $orden,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * @param  string  $nombre
     * @return \App\Models\Article
     */
    protected function articulo($nombre)
    {
        return Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'status'  => 'active',
        ]);
    }

    /**
     * La escena de conv 10: la foto, la tarjeta de la foto del artículo propuesta por el asistente
     * (ya 'listo'), el "sí" del dueño y el assistant que lo contesta, todavía 'pendiente'.
     *
     * @param  string  $si
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiMessage, 2: int, 3: \App\Models\Article}
     */
    protected function escena_con_tarjeta($si = 'Dale')
    {
        $articulo = $this->articulo('zz-a1 Botella Stanley de aluminio');

        $conversation = $this->conversacion_whatsapp();

        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Ponele esta foto a la botella Stanley']);
        $this->guardar_foto($con_foto);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['articulo_id' => $articulo->id]
        );

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $propone->estado = 'listo';
        $propone->contenido = 'Le pongo esa foto a "Botella Stanley de aluminio". ¿La registro?';
        $propone->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $si]);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $contestando, (int) $respuesta['tarjeta_id'], $articulo];
    }

    /**
     * El proveedor contesta SÓLO texto, sin llamar a ninguna herramienta: lo que hizo el modelo Ágil
     * en demo3.
     *
     * @return void
     */
    protected function proveedor_que_solo_escribe()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'text', 'text' => 'Listo, la foto quedó asignada.'],
                ],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 28],
            ], 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * El texto del último mensaje `user` del payload que viajó al proveedor.
     *
     * @return string
     */
    protected function ultimo_user_enviado()
    {
        $texto = '';

        foreach (Http::recorded() as $par) {
            $cuerpo = json_decode((string) $par[0]->body(), true);

            if (!is_array($cuerpo) || !isset($cuerpo['messages'])) {
                continue;
            }

            $ultimo = end($cuerpo['messages']);

            $texto = is_array($ultimo['content']) ? json_encode($ultimo['content'], JSON_UNESCAPED_UNICODE) : (string) $ultimo['content'];
        }

        return $texto;
    }

    /**
     * Anota los archivos que la asignación dejó en storage/app/public para borrarlos al final.
     *
     * @param  \App\Models\Article  $articulo
     * @return void
     */
    protected function anotar_archivos(Article $articulo)
    {
        foreach (Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) $image->hosting_url);
        }
    }

    // ------------------------------------------------------------------------------------ A1

    /**
     * 🔴 El caso de demo3: el modelo no llama a ninguna herramienta y la tarjeta queda confirmada
     * igual, con la foto asignada de verdad. Y al modelo le llega la nota con el resultado real.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_dale_con_una_tarjeta_pendiente_la_confirma_aunque_el_modelo_no_llame_herramientas()
    {
        $this->proveedor_que_solo_escribe();

        list($conversation, $contestando, $tarjeta_id, $articulo) = $this->escena_con_tarjeta('Dale');

        (new AsistenteIaService())->responder($conversation, $contestando);

        $this->anotar_archivos($articulo);

        $tarjeta = AiMessageAction::find($tarjeta_id);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjeta->estado_guardado(), 'El sí del dueño tiene que confirmar la tarjeta sin depender del modelo.');

        $this->assertSame(
            1,
            Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count(),
            'La foto tiene que quedar colgada del artículo de verdad, no sólo en el texto.'
        );

        Http::assertSentCount(1);

        $nota = $this->ultimo_user_enviado();

        $this->assertStringContainsString('[El sistema ya confirmó la tarjeta #' . $tarjeta_id, $nota);
        $this->assertStringContainsString('Foto agregada al artículo', $nota, 'La nota lleva el resultado real de la ejecución.');
        $this->assertStringContainsString('no la vuelvas a confirmar', $nota);
    }

    /**
     * Un "no" no confirma: lo decide el modelo, como siempre.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_no_no_confirma_nada()
    {
        $this->proveedor_que_solo_escribe();

        list($conversation, $contestando, $tarjeta_id) = $this->escena_con_tarjeta('No, dejá');

        (new AsistenteIaService())->responder($conversation, $contestando);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertStringNotContainsString('[El sistema', $this->ultimo_user_enviado());
    }

    /**
     * Con DOS tarjetas pendientes no se sabe a cuál contesta el sí: no se confirma ninguna.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_dos_tarjetas_pendientes_el_si_no_confirma_ninguna()
    {
        $this->proveedor_que_solo_escribe();

        $conversation = $this->conversacion_whatsapp();

        $con_fotos = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Ponele estas fotos a los dos']);
        $this->guardar_foto($con_fotos, 1);
        $this->guardar_foto($con_fotos, 2);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $una = PropuestaFotoArticuloIaHelper::proponer($contexto, $propone, ['articulo_id' => $this->articulo('zz-a1 Mate')->id]);
        $otra = PropuestaFotoArticuloIaHelper::proponer($contexto, $propone, ['articulo_id' => $this->articulo('zz-a1 Termo')->id]);

        $this->assertTrue($una['ok'] && $otra['ok']);

        $propone->estado = 'listo';
        $propone->contenido = 'Te armé las dos. ¿Las registro?';
        $propone->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí']);

        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new AsistenteIaService())->responder($conversation, $contestando);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($una['tarjeta_id'])->estado_guardado());
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($otra['tarjeta_id'])->estado_guardado());
    }

    /**
     * La normalización y la lista cerrada: lo que confirma y lo que no.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function que_cuenta_como_un_si()
    {
        foreach (['Sí', 'si', 'Dale!', 'Daleee', 'ok', 'Okey 👍', 'Listo', 'de una', 'Sí, dale', 'dale gracias', 'Confirmo.', 'Cargalo por favor', 'Perfecto'] as $si) {
            $this->assertTrue(ConfirmacionDeterministaIaHelper::es_afirmacion($si), '"' . $si . '" es un sí.');
        }

        foreach (['No', 'no dale', 'Sí pero cambiale el precio', 'gracias', 'dale 5000', '', '👍', 'bueno', 'sí, y además cargame otro artículo que se llama distinto'] as $no) {
            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_afirmacion($no), '"' . $no . '" no es un sí inequívoco.');
        }
    }

    /**
     * 🔴 CAMBIÓ EL COMPORTAMIENTO PEDIDO (correcciones del 24/9/2026, decisión de la misión): hasta
     * esa ronda este test se llamaba en_el_canal_del_sistema_no_confirma_por_texto y fijaba que un
     * "Dale" TIPEADO en el panel del chat no confirmaba nada. Pero Lucas escribe también desde el
     * panel, y ahí el modelo no tiene confirmar_carga_pendiente: el "dale" quedaba en manos de un
     * modelo que podía decir "quedó hecho" sin hacer nada, que es el bug original. Ahora confirma por
     * el mismo camino que el botón Confirmar, con las mismas guardas (una tarjeta, del último mensaje
     * del asistente, de menos de 30 minutos).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_el_canal_del_sistema_un_dale_tipeado_confirma_como_el_boton()
    {
        $this->proveedor_que_solo_escribe();

        list($conversation, $contestando, $tarjeta_id, $articulo) = $this->escena_con_tarjeta('Dale');

        $contestando->canal = AiMessage::CANAL_SISTEMA;
        $contestando->save();

        (new AsistenteIaService())->responder($conversation, $contestando);

        $this->anotar_archivos($articulo);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertStringContainsString('[El sistema ya confirmó la tarjeta #' . $tarjeta_id, $this->ultimo_user_enviado());
    }

    /**
     * Una tarjeta de más de 30 minutos no se confirma sola: el "dale" puede estar contestando otra
     * cosa. Decide el modelo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_tarjeta_de_mas_de_media_hora_no_se_confirma_sola()
    {
        list($conversation, $contestando, $tarjeta_id) = $this->escena_con_tarjeta('Dale');

        AiMessageAction::where('id', $tarjeta_id)->update(['created_at' => Carbon::now()->subMinutes(31)]);

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Con signo de pregunta no es un sí: "¿ok?" confirmaba porque normalizar saca los signos.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_pregunta_no_es_un_si()
    {
        foreach (['¿ok?', 'dale?', '¿Listo?', 'ok??'] as $pregunta) {
            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_afirmacion($pregunta), '"' . $pregunta . '" pregunta, no confirma.');
        }

        list($conversation, $contestando, $tarjeta_id) = $this->escena_con_tarjeta('¿ok?');

        $this->assertNull(ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $contestando));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Si la confirmación por texto la rechazaría (acá: la pregunta todavía se estaba escribiendo
     * cuando llegó el "sí", el hallazgo C de la misión asistente-por-whatsapp), no se intenta y NO
     * viaja ninguna nota: antes la nota le hacía contarle al dueño "No podés confirmar una carga que
     * la persona todavía no vio".
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_las_guardas_la_rechazan_decide_el_modelo_sin_nota()
    {
        $this->proveedor_que_solo_escribe();

        list($conversation, $contestando, $tarjeta_id) = $this->escena_con_tarjeta('Dale');

        AiMessage::where('id', AiMessageAction::find($tarjeta_id)->ai_message_id)->update(['estado' => 'pendiente']);

        (new AsistenteIaService())->responder($conversation, $contestando);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertStringNotContainsString('[El sistema', $this->ultimo_user_enviado());
    }

    /**
     * 🔴 Si el modelo falla DESPUÉS de una confirmación hecha por el sistema, al dueño le llega el
     * resultado de la carga y no "se me cortó la conexión" (que lo llevaría a pedirla de nuevo y
     * duplicarla).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_el_modelo_falla_despues_de_confirmar_se_contesta_con_el_resultado()
    {
        Http::fake([
            '*' => Http::response(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
        ]);

        list($conversation, $contestando, $tarjeta_id, $articulo) = $this->escena_con_tarjeta('Dale');

        $texto = (new AsistenteIaService())->responder($conversation, $contestando);

        $this->anotar_archivos($articulo);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertStringContainsString('Foto agregada al artículo', $texto);
        $this->assertStringStartsWith('Listo', $texto);
    }

    // ------------------------------------------------------------------------------------ A2

    /**
     * 🔴 La foto que el dueño mandó diez mensajes atrás se encuentra: no se le vuelve a pedir.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_diez_mensajes_atras_se_encuentra()
    {
        $articulo = $this->articulo('zz-a2 Linterna');

        $conversation = $this->conversacion_whatsapp();

        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá']);
        $foto = $this->guardar_foto($con_foto);

        for ($i = 0; $i < 5; $i++) {
            $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Respuesta ' . $i]);
            $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Pregunta ' . $i]);
        }

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer($contexto, $propone, ['articulo_id' => $articulo->id]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $foto->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['imagen_id']);

        // La foto de una sucursal usa la misma ventana.
        $sucursal = Address::create(['user_id' => $this->comercio->id, 'street' => 'zz-a2 Belgrano']);

        $de_sucursal = PropuestaFotoSucursalIaHelper::proponer($contexto, $propone, ['sucursal' => 'zz-a2 Belgrano']);

        $this->assertTrue($de_sucursal['ok'], json_encode($de_sucursal));
        $this->assertSame((int) $sucursal->id, (int) AiMessageAction::find($de_sucursal['tarjeta_id'])->datos['address_id']);
    }

    /**
     * 🔴 La foto que cuelga de un mensaje del ASISTENTE (la que trae de internet la búsqueda por
     * código de barras) no es una foto del dueño y ninguna herramienta la toma sola.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_un_mensaje_del_asistente_no_se_toma()
    {
        $articulo = $this->articulo('zz-a2 Cafetera');

        $conversation = $this->conversacion_whatsapp();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Ponele la foto de internet']);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        // La foto de internet, colgada del assistant en curso como la guarda el constructor B.
        $this->guardar_foto($propone);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['articulo_id' => $articulo->id]
        );

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No tengo ninguna foto', $respuesta['error']);
    }

    /**
     * Más allá de las 24 horas ya no es "la foto que te acabo de mandar".
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_de_mas_de_24_horas_no_se_toma()
    {
        $articulo = $this->articulo('zz-a2 Pava');

        $conversation = $this->conversacion_whatsapp();

        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá']);
        $foto = $this->guardar_foto($con_foto);

        AiMessageImagen::where('id', $foto->id)->update(['created_at' => Carbon::now()->subHours(25)]);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['articulo_id' => $articulo->id]
        );

        $this->assertFalse($respuesta['ok']);
    }

    // ------------------------------------------------ correcciones del 24/9/2026 (e, f)

    /**
     * La foto de una SUCURSAL se asigna sola en "resuelto", así que sale de la ÚLTIMA TANDA del
     * dueño: si esa tanda ya se usó, no se lleva una foto suelta de otro momento de la charla. La
     * de un artículo (que siempre deja tarjeta) sí la sigue encontrando.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_sucursal_sale_de_la_ultima_tanda_y_no_de_una_foto_suelta()
    {
        $conversation = $this->conversacion_whatsapp();

        $de_la_manana = $this->mensaje($conversation, 'user', 'listo', ['contenido' => '¿A cuánto está esto?']);
        $suelta = $this->guardar_foto($de_la_manana);

        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Está a 1500.']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Gracias']);

        $de_la_factura = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'La factura']);
        $usada = $this->guardar_foto($de_la_factura);
        AiMessageImagen::where('id', $usada->id)->update(['gestionada_at' => Carbon::now()]);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Ponele una foto a la sucursal Belgrano']);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        Address::create(['user_id' => $this->comercio->id, 'street' => 'zz-e Belgrano']);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $de_sucursal = PropuestaFotoSucursalIaHelper::proponer($contexto, $propone, ['sucursal' => 'zz-e Belgrano']);

        $this->assertFalse($de_sucursal['ok'], 'La foto de la mañana no es de la última tanda: la sucursal no se la lleva.');

        $de_articulo = PropuestaFotoArticuloIaHelper::proponer($contexto, $propone, ['articulo_id' => $this->articulo('zz-e Mate')->id]);

        $this->assertTrue($de_articulo['ok'], json_encode($de_articulo));
        $this->assertSame((int) $suelta->id, (int) AiMessageAction::find($de_articulo['tarjeta_id'])->datos['imagen_id']);
    }

    /**
     * Si el artículo no existe, la herramienta de la foto le dice al modelo que un artículo NUEVO
     * va con proponer_alta y la foto en la misma tarjeta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_foto_de_un_articulo_que_no_existe_manda_al_alta_con_la_foto()
    {
        $conversation = $this->conversacion_whatsapp();

        $con_foto = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Cargá este termo nuevo con la foto']);
        $this->guardar_foto($con_foto);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaFotoArticuloIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['articulo' => 'zz-f Termo que no existe']
        );

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('proponer_alta', $respuesta['error']);
        $this->assertStringContainsString('con_foto_de_la_conversacion', $respuesta['error']);
    }
}
