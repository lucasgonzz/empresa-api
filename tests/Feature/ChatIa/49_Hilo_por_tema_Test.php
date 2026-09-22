<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\HiloPorTemaIaHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\AiTokenUsage;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AsistenteWhatsapp\AsistenteWhatsappTestCase;

/**
 * Misión asistente-capacidades-y-hilos — P4: un hilo por tarea, en vez de una conversación eterna.
 *
 * El pedido de Lucas: "quiero que con cada nuevo mensaje la IA detecte si el usuario ya está
 * hablando de otra tarea para crear una nueva conversación". El caso real es la conversación 7 de
 * demo3: 76 mensajes y ocho tareas sin relación, con el título de lo primero que se pidió.
 *
 * 🔴 LO QUE MÁS IMPORTA DE ESTE ARCHIVO NO ES QUE CORTE: es CUÁNDO NO CORTA. Un corte de más
 * rompe la confirmación por texto —el "dale" cae en un hilo nuevo y la tarjeta queda huérfana—, y
 * en la conversación real de Lucas hay varios "Si" sueltos que confirman una carga propuesta
 * cuatro mensajes antes. Por eso hay un test por guarda, y todos miden además que no se haya
 * gastado una llamada a la API.
 *
 * Hereda el fixture del canal de WhatsApp (AsistenteWhatsappTestCase) porque este corte vive ahí y
 * en ningún otro lado: en el panel del sistema el dueño abre conversaciones con un botón. El
 * archivo igual vive en ChatIa, que es la suite del módulo.
 *
 * 🔴 Ningún test sale a la red: Http::fake() está puesto en el setUp, antes que cualquier cosa.
 */
class Hilo_por_tema_Test extends AsistenteWhatsappTestCase
{
    /** Una clave cualquiera: lo único que hace falta es que `hay_credenciales()` diga que sí. */
    const CLAVE_FALSA = 'clave-de-anthropic-para-los-tests';

    /** @var string Lo que contesta el modelo en este test. */
    protected $texto_del_modelo = 'sigue';

    /** @var int Status con el que contesta la API en este test. */
    protected $status_del_modelo = 200;

    /** @var bool true para que el cliente HTTP explote (un timeout es esto). */
    protected $el_cliente_explota = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();

        Queue::fake();

        /*
         * El padre deja la clave en null para que nada salga a la red. Acá hace falta que el
         * llamado se INTENTE, así que se pone una falsa y el que atiende es Http::fake().
         */
        config(['services.anthropic.api_key' => self::CLAVE_FALSA]);

        /*
         * 🔴 UN SOLO Http::fake(), Y ACÁ. Los stubs de Laravel se APILAN: el primero que matchea
         * la URL es el que contesta, así que un segundo `Http::fake(['api.anthropic.com/*' => ...])`
         * adentro de un test NO pisa a este — queda detrás y no lo ve nadie. Con ese patrón, todos
         * los tests de "sí corta" fallaban en silencio contestando siempre lo del setUp. Lo que
         * cambia por test son las propiedades que lee esta closure.
         */
        $test = $this;

        Http::fake([
            'api.anthropic.com/*' => function () use ($test) {
                return $test->respuesta_del_modelo();
            },
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Lo que devuelve el stub, armado con lo que el test haya pedido. Público porque lo llama la
     * closure del fake.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function respuesta_del_modelo()
    {
        if ($this->el_cliente_explota) {

            throw new \RuntimeException('cURL error 28: Operation timed out');
        }

        if ($this->status_del_modelo !== 200) {

            return Http::response(['error' => ['type' => 'api_error']], $this->status_del_modelo);
        }

        return Http::response([
            'model'       => 'claude-modelo-fake',
            'stop_reason' => 'end_turn',
            'content'     => [
                ['type' => 'text', 'text' => $this->texto_del_modelo],
            ],
            'usage'       => ['input_tokens' => 220, 'output_tokens' => 2],
        ], 200);
    }

    /**
     * Lo que contesta el modelo cuando se le pregunta si el mensaje es otro tema.
     *
     * @param  string  $texto
     * @return void
     */
    protected function responde($texto)
    {
        $this->texto_del_modelo   = $texto;
        $this->status_del_modelo  = 200;
        $this->el_cliente_explota = false;
    }

    /**
     * Una conversación de WhatsApp vigente CON historia: título puesto y un par de mensajes. Sin
     * eso no hay contra qué comparar y el helper ni pregunta (lo mide su propio test).
     *
     * @param  string  $titulo
     * @return \App\Models\AiConversation
     */
    protected function conversacion_con_historia($titulo = 'Mover stock Pastina Perla entre sucursales')
    {
        $conversacion = $this->conversacion_whatsapp(Carbon::now()->subMinutes(4));

        $conversacion->titulo = $titulo;
        $conversacion->save();

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Mové 2 pastinas de Centro a Florida']);
        $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => 'Listo, quedaron movidas las 2 unidades.']);

        return $conversacion;
    }

    /**
     * Una tarjeta de carga esperando confirmación en esa conversación.
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @param  mixed  $created_at
     * @return \App\Models\AiMessageAction
     */
    protected function tarjeta_propuesta($conversacion, $created_at = null)
    {
        $propuso = $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => '¿Te registro el pago de $100.000?']);

        $accion = AiMessageAction::create([
            'ai_conversation_id' => $conversacion->id,
            'ai_message_id'      => $propuso->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_PAGO,
            'clave'              => 'pago:100000',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['monto' => 100000],
            'presentacion'       => ['titulo' => 'Pago de $100.000'],
        ]);

        if (!is_null($created_at)) {

            /* forceFill + save para poder envejecer la tarjeta sin que timestamps la pise. */
            AiMessageAction::where('id', $accion->id)->update(['created_at' => $created_at]);

            $accion->refresh();
        }

        return $accion;
    }

    /**
     * El umbral de largo es una decisión con motivo escrito (ver el comentario de la constante):
     * sale de los mensajes reales de demo3 que cortar habría roto. Si alguien lo baja, que sea a
     * propósito.
     *
     * @group asistente-hilos
     * @test
     */
    public function el_umbral_de_largo_esta_escrito_como_constante()
    {
        $this->assertEquals(
            15,
            HiloPorTemaIaHelper::LARGO_MINIMO_TEXTO,
            'El umbral cubre "Ya terminó" y "De florida" (10), los más largos de la familia de mensajes que no dicen el tema.'
        );
    }

    /**
     * El caso que motiva toda la tarea: el dueño estaba hablando de stock y arranca con otra cosa.
     *
     * @group asistente-hilos
     * @test
     */
    public function un_tema_nuevo_abre_conversacion_y_le_pone_titulo()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $nueva_id = (int) $respuesta->json('ai_conversation_id');

        $this->assertNotEquals((int) $vigente->id, $nueva_id, 'Otra tarea es otro hilo.');

        $nueva = AiConversation::find($nueva_id);

        $this->assertEquals(AiConversation::ORIGEN_WHATSAPP, $nueva->origen);
        $this->assertEquals($this->comercio->id, (int) $nueva->user_id);
        $this->assertNull($nueva->titulo, 'Nace sin título: el título sale por el camino de siempre.');

        Queue::assertPushed(InferirTituloConversacionIaJob::class);
    }

    /**
     * @group asistente-hilos
     * @test
     */
    public function si_el_mensaje_sigue_el_tema_no_se_corta()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('sigue');

        $respuesta = $this->mandar(['texto' => 'Y las otras tres pastinas que quedaron en Centro?'])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * 🔴 LA GUARDA QUE MÁS IMPORTA. Con una tarjeta esperando el "dale", cortar dejaría la carga
     * huérfana: confirmar_carga_pendiente exige que la tarjeta sea de la MISMA conversación.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_tarjeta_en_propuesta_impide_el_corte_y_ni_pregunta()
    {
        $vigente = $this->conversacion_con_historia();

        $this->tarjeta_propuesta($vigente);

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Aprovechá y aumentá los costos de la lista de Pablo'])->assertStatus(202);

        $this->assertEquals(
            (int) $vigente->id,
            (int) $respuesta->json('ai_conversation_id'),
            'Con una carga esperando confirmación no se parte la conversación, diga lo que diga la IA.'
        );

        Http::assertNothingSent();
    }

    /**
     * La otra mitad de la guarda anterior: una tarjeta que ya venció (más de HORAS_VENCIMIENTO) no
     * espera ninguna confirmación, así que no tiene por qué frenar el corte. Es lo que impide que
     * una tarjeta olvidada deje la conversación sin poder cortarse nunca más.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_tarjeta_vencida_no_frena_el_corte()
    {
        $vigente = $this->conversacion_con_historia();

        $this->tarjeta_propuesta($vigente, Carbon::now()->subHours(AiMessageAction::HORAS_VENCIMIENTO + 1));

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertNotEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * "Dale", "Si", "Y?", "Ya terminó": los mensajes que de verdad aparecen en la conversación de
     * Lucas y que no dicen nada del tema.
     *
     * @group asistente-hilos
     * @test
     */
    public function un_mensaje_corto_no_corta_ni_gasta_una_llamada()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        foreach (['Dale', 'Si', 'Y?', 'Ya terminó', 'De florida'] as $corto) {

            $respuesta = $this->mandar(['texto' => $corto])->assertStatus(202);

            $this->assertEquals(
                (int) $vigente->id,
                (int) $respuesta->json('ai_conversation_id'),
                'El mensaje "' . $corto . '" no alcanza para decidir un tema.'
            );
        }

        Http::assertNothingSent();
    }

    /**
     * El borde exacto del umbral, en los dos sentidos: 14 caracteres no pregunta, 15 sí.
     *
     * @group asistente-hilos
     * @test
     */
    public function el_borde_del_umbral_se_respeta_en_los_dos_sentidos()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $catorce = 'Dale, confirmá';

        $this->assertEquals(14, mb_strlen($catorce));

        $respuesta = $this->mandar(['texto' => $catorce])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();

        $quince = 'Hacé una compra';

        $this->assertEquals(15, mb_strlen($quince));

        $respuesta = $this->mandar(['texto' => $quince])->assertStatus(202);

        $this->assertNotEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * Una foto sin epígrafe es un mensaje válido y frecuente (el #97 de demo3 vino así): no hay
     * texto del que leer un tema.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_foto_sola_no_corta()
    {
        Storage::fake('local');

        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->call(
            'POST',
            'api/admin-sync/asistente/mensajes',
            ['texto' => '', 'tipo' => 'imagen'],
            [],
            ['imagenes' => [UploadedFile::fake()->image('factura.jpg', 800, 1000)]],
            $this->transformHeadersToServerVars($this->headers())
        );

        $respuesta->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 La guarda que sale de medir demo3: la foto llega en un mensaje y el "cargá la compra" en
     * el siguiente. Si ese segundo abriera un hilo nuevo, la foto quedaría en el anterior y el
     * asistente volvería a decir "no puedo leer la imagen" — el defecto que esta misión arregla.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_foto_sin_gestionar_del_mensaje_anterior_impide_el_corte()
    {
        $vigente = $this->conversacion_con_historia();

        $con_foto = $this->mensaje($vigente, 'user', 'listo', ['contenido' => '']);

        AiMessageImagen::create([
            'ai_message_id' => $con_foto->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => 'asistente_imagenes/' . $this->comercio->id . '/' . $con_foto->id . '/1.webp',
            'mime'          => 'image/webp',
            'bytes'         => 1234,
        ]);

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Cargá la compra de Distribuidora Sur con esa factura'])->assertStatus(202);

        $this->assertEquals(
            (int) $vigente->id,
            (int) $respuesta->json('ai_conversation_id'),
            'La foto del turno anterior y el pedido de este turno tienen que quedar en el mismo hilo.'
        );

        Http::assertNothingSent();
    }

    /**
     * La misma foto, una vez que alguna carga ya la usó, deja de frenar el corte.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_foto_ya_gestionada_no_frena_el_corte()
    {
        $vigente = $this->conversacion_con_historia();

        $con_foto = $this->mensaje($vigente, 'user', 'listo', ['contenido' => '']);

        AiMessageImagen::create([
            'ai_message_id' => $con_foto->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => 'asistente_imagenes/' . $this->comercio->id . '/' . $con_foto->id . '/1.webp',
            'mime'          => 'image/webp',
            'bytes'         => 1234,
            'gestionada_at' => Carbon::now()->subMinutes(2),
        ]);

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertNotEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * La cita del admin es intención explícita del dueño y manda sobre cualquier inferencia.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_cita_del_admin_manda_sobre_la_inferencia()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->mandar([
            'texto'              => 'Sacale a Brisa el permiso de ver las ventas',
            'ai_conversation_id' => $vigente->id,
        ])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * Y un `ai_conversation_id` que no se pudo honrar (borrado, de otro dueño) tampoco corta: el
     * dueño citó igual. Abrirle un hilo nuevo por un id que no se pudo resolver sería lo peor de
     * los dos mundos.
     *
     * @group asistente-hilos
     * @test
     */
    public function un_ai_conversation_id_que_no_se_pudo_honrar_tampoco_corta()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->mandar([
            'texto'              => 'Sacale a Brisa el permiso de ver las ventas',
            'ai_conversation_id' => 99999999,
        ])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 La conversación nunca se pierde por un problema de IA: un error de la API deja todo en la
     * conversación vigente, que es el comportamiento de hoy.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_falla_de_la_api_deja_todo_en_la_conversacion_vigente()
    {
        $vigente = $this->conversacion_con_historia();

        $this->status_del_modelo = 500;

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * Lo mismo con una excepción del cliente HTTP (un timeout es esto).
     *
     * @group asistente-hilos
     * @test
     */
    public function una_excepcion_del_cliente_http_deja_todo_en_la_conversacion_vigente()
    {
        $vigente = $this->conversacion_con_historia();

        $this->el_cliente_explota = true;

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * Una respuesta que no es la palabra `nueva` pelada se comporta igual que una falla. Se mide
     * con "nuevamente seguimos" a propósito: con un `strpos(...) === 0` eso cortaría.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_respuesta_ilegible_no_corta()
    {
        $ilegibles = ['nuevamente seguimos', 'no sé, tal vez sea nueva', '{"decision":"nueva"}', ''];

        foreach ($ilegibles as $ilegible) {

            $vigente = $this->conversacion_con_historia('Hilo de ' . md5($ilegible));

            $this->responde($ilegible);

            $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

            $this->assertEquals(
                (int) $vigente->id,
                (int) $respuesta->json('ai_conversation_id'),
                'La respuesta "' . $ilegible . '" no es un corte.'
            );

            /* La conversación recién creada pasa a ser la vigente del próximo giro del bucle. */
            AiConversation::where('id', $vigente->id)->update(['last_message_at' => Carbon::now()->subDays(3)]);
        }
    }

    /**
     * Las formas en que un modelo dice la misma palabra: con comillas, con guion, con salto de
     * línea. Todas cortan.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_palabra_nueva_se_reconoce_aunque_venga_adornada()
    {
        foreach (["`nueva`", " \"Nueva\"\n", "- nueva"] as $adornada) {

            $vigente = $this->conversacion_con_historia('Hilo de ' . md5($adornada));

            $this->responde($adornada);

            $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

            $this->assertNotEquals(
                (int) $vigente->id,
                (int) $respuesta->json('ai_conversation_id'),
                'La respuesta "' . trim($adornada) . '" es un corte.'
            );

            AiConversation::where('user_id', $this->comercio->id)->update(['last_message_at' => Carbon::now()->subDays(3)]);
        }
    }

    /**
     * Sin clave del proveedor no se sale a la red y no se corta nada: la conversación sigue igual
     * que antes de esta misión.
     *
     * @group asistente-hilos
     * @test
     */
    public function sin_credenciales_no_sale_a_la_red_ni_corta()
    {
        config(['services.anthropic.api_key' => null]);

        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * Una conversación sin título y sin un solo mensaje con texto no tiene tema contra el cual
     * comparar: preguntarle a la IA sería pagar una llamada para que adivine.
     *
     * @group asistente-hilos
     * @test
     */
    public function una_conversacion_sin_tema_no_gasta_una_llamada()
    {
        $vigente = $this->conversacion_whatsapp(Carbon::now()->subMinutes(3));

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * Un audio que Kapso no pudo transcribir llega con un literal de 23 caracteres que pasaría el
     * umbral de largo, y no dice absolutamente nada del tema.
     *
     * @group asistente-hilos
     * @test
     */
    public function un_audio_sin_transcribir_no_corta()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->mandar([
            'texto' => '[Audio sin transcripción]',
            'tipo'  => 'audio',
        ])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * El corte por tiempo de 6 horas sigue mandando por encima de todo: pasado el corte se abre
     * una nueva sin preguntarle nada a nadie.
     *
     * @group asistente-hilos
     * @test
     */
    public function el_corte_por_tiempo_no_necesita_preguntarle_a_la_ia()
    {
        $vieja = $this->conversacion_con_historia();

        AiConversation::where('id', $vieja->id)->update(['last_message_at' => Carbon::now()->subHours(7)]);

        $this->responde('sigue');

        $respuesta = $this->mandar(['texto' => 'Seguimos con lo de las pastinas de ayer'])->assertStatus(202);

        $this->assertNotEquals((int) $vieja->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 Un negocio que se pasó del tope de su plan se contesta con el texto de límite SIN gastar
     * una llamada a la API — y la decisión de hilo ES una llamada a la API. Sin esta guarda, el
     * corte por tope dejaba de ser un corte: seguía pagándose una llamada por turno.
     *
     * @group asistente-hilos
     * @test
     */
    public function con_el_tope_del_plan_superado_no_se_gasta_la_llamada()
    {
        $vigente = $this->conversacion_con_historia();

        $this->comercio->plan_ia_tope_interacciones_diarias = 2;
        $this->comercio->save();

        for ($i = 0; $i < 2; $i++) {

            AiTokenUsage::create([
                'user_id'                     => $this->comercio->id,
                'auth_user_id'                => $this->comercio->id,
                'proceso'                     => 'chat_mensaje',
                'proveedor'                   => 'anthropic',
                'modelo'                      => 'claude-modelo-fake',
                'input_tokens'                => 10,
                'output_tokens'               => 10,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ]);
        }

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertEquals((int) $vigente->id, (int) $respuesta->json('ai_conversation_id'));

        Http::assertNothingSent();
    }

    /**
     * El gasto de la decisión se registra, igual que el del título: es una llamada que el cliente
     * paga y el admin la recolecta de ai_token_usages.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_decision_deja_su_fila_de_consumo()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertDatabaseHas('ai_token_usages', [
            'user_id'            => $this->comercio->id,
            'proceso'            => HiloPorTemaIaHelper::PROCESO,
            'ai_conversation_id' => $vigente->id,
        ]);
    }

    /**
     * El mensaje del dueño y el globo del asistente van los DOS al hilo nuevo: partir el turno
     * dejaría la pregunta en un hilo y la respuesta en otro.
     *
     * @group asistente-hilos
     * @test
     */
    public function el_turno_entero_queda_en_el_hilo_nuevo()
    {
        $vigente = $this->conversacion_con_historia();

        $this->responde('nueva');

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $nueva_id = (int) $respuesta->json('ai_conversation_id');

        $this->assertEquals(
            2,
            AiMessage::where('ai_conversation_id', $nueva_id)->count(),
            'El user y el assistant del turno van juntos.'
        );

        $this->assertEquals(
            $nueva_id,
            (int) AiMessage::find($respuesta->json('ai_message_id'))->ai_conversation_id
        );

        $this->assertEquals(
            2,
            AiMessage::where('ai_conversation_id', $vigente->id)->count(),
            'La conversación vieja queda con los suyos y nada más.'
        );
    }
}
