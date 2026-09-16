<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\AdminSync\AsistenteController;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\MostradorAccesoHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderScanAltaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\MostradorReporte;
use App\Models\Pending;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderStatus;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Misión asistente-por-whatsapp — lo que devolvieron los dos chequeos independientes del 16/9/2026.
 *
 * Un test por hallazgo, y cada uno se pone ROJO sin su arreglo. Van juntos en un archivo aparte a
 * propósito: así se lee de un saque qué se rompía antes y por qué, que es la parte que un archivo
 * temático diluye.
 */
class Correcciones_del_chequeo_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Queue::fake();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Una foto de verdad, con el nombre que se le pida (o sin ninguno).
     *
     * @param  string  $nombre
     * @return \Illuminate\Http\UploadedFile
     */
    protected function foto($nombre = 'factura.jpg')
    {
        return UploadedFile::fake()->image($nombre, 400, 500);
    }

    /**
     * POST multipart con fotos, como lo manda el admin.
     *
     * @param  array  $cuerpo
     * @param  array  $archivos
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mandar_con_fotos(array $cuerpo, array $archivos)
    {
        return $this->call(
            'POST',
            'api/admin-sync/asistente/mensajes',
            $cuerpo,
            [],
            ['imagenes' => $archivos],
            $this->transformHeadersToServerVars($this->headers())
        );
    }

    /* ------------------------------------------------------------------ 1 */

    /**
     * 🔴 HALLAZGO 1. La foto sin epígrafe es el caso normal: el dueño saca la foto de la factura,
     * la manda sin escribir nada, y recién en el mensaje siguiente dice de qué proveedor es. Antes
     * eso daba 422 y el dueño recibía el texto de disculpa por un mensaje perfecto.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_sin_epigrafe_entra_igual_y_llega_al_modelo()
    {
        $respuesta = $this->mandar_con_fotos(['texto' => '', 'tipo' => 'imagen'], [$this->foto()]);

        $respuesta->assertStatus(202);

        $conversation = AiConversation::find($respuesta->json('ai_conversation_id'));

        $user = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->first();

        $this->assertEquals('', (string) $user->contenido, 'No se inventa un texto que el dueño no escribió.');

        $this->assertEquals(
            1,
            AiMessageImagen::where('ai_message_id', $user->id)->count(),
            'La foto tiene que haber quedado guardada.'
        );

        /*
         * Y lo que de verdad importa: ese mensaje TIENE que viajar en el historial. Con el filtro
         * viejo (`contenido != ''`) quedaba afuera y el modelo nunca veía la foto.
         */
        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertCount(1, $payload, 'El mensaje con la foto tiene que estar en el payload.');
        $this->assertTrue(is_array($payload[0]['content']));
        $this->assertEquals('image', $payload[0]['content'][0]['type']);
        $this->assertCount(1, $payload[0]['content'], 'Sin epígrafe no va bloque de texto: uno vacío rebota el request.');
    }

    /**
     * Sin fotos y sin texto sigue siendo 422: no hay nada que contestar.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_texto_y_sin_fotos_sigue_siendo_422()
    {
        $this->mandar(['texto' => '   '])->assertStatus(422);
    }

    /* ------------------------------------------------------------------ 2 */

    /**
     * 🔴 HALLAZGO 2. El job del escaneo se despacha DESPUÉS del commit. Sin `afterCommit()`, en una
     * conexión sin `after_commit` en config —`redis`, que es la de los clientes del VPS— un worker
     * libre podía tomarlo antes del commit, no encontrar el scan, loguear un warning y volver: la
     * compra queda creada, el dueño lee "estoy leyendo la factura" y la factura no se lee nunca.
     *
     * El test corre con la conexión que HOY no tiene `after_commit`, que es donde el bug existe.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_job_del_escaneo_se_despacha_despues_del_commit()
    {
        $this->assertFalse(
            (bool) config('queue.connections.redis.after_commit'),
            'Si algún día redis pasa a after_commit=true, este test deja de probar el caso; hay que elegir otra conexión sin el flag.'
        );

        config(['queue.default' => 'redis']);

        $proveedor = $this->crear_proveedor('Distribuidora del commit');

        $orden = ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $proveedor->id,
            'num'         => 4001,
        ]);

        DB::transaction(function () use ($orden) {

            ProviderOrderScanAltaHelper::crear($orden, [[
                'binario'         => $this->binario_png(),
                'nombre_original' => 'factura.webp',
            ]], $this->comercio);
        });

        Queue::assertPushed(RunProviderOrderScanJob::class, function ($job) {

            return $job->afterCommit === true;
        });
    }

    /* ------------------------------------------------------------------ 3 */

    /**
     * 🟠 HALLAZGO 3. Un informe que no se pudo avisar ayer —el caso ESPERADO al arrancar, porque
     * sin la plantilla de Meta aprobada a las 8:30 no sale nada— tiene que seguir saliendo hoy.
     * Con la ventana en "hoy" se perdía para siempre.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_informe_de_ayer_sin_avisar_sigue_saliendo_hoy()
    {
        config(['services.mostrador.spa_url' => 'https://elcliente.comerciocity.com']);

        $de_ayer = $this->informe(['tipo' => 'dia', 'generado_at' => Carbon::now()->subDay()]);
        $de_hoy  = $this->informe(['tipo' => 'caja', 'generado_at' => Carbon::now()]);

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->assertStatus(200)
            ->json('informes');

        $this->assertCount(2, $informes, 'El de ayer sin avisar no se puede haber perdido.');

        $this->assertEquals(
            (int) $de_ayer->id,
            (int) $informes[0]['id'],
            'Del más viejo al más nuevo: el que más esperó sale primero.'
        );

        $this->assertEquals((int) $de_hoy->id, (int) $informes[1]['id']);
    }

    /**
     * Pero la ventana tiene fin: un informe de la semana pasada ya no le sirve a nadie.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_informe_mas_viejo_que_la_ventana_ya_no_sale()
    {
        $this->informe([
            'generado_at' => Carbon::now()->startOfDay()->subDays(AsistenteController::DIAS_DE_INFORMES_PENDIENTES),
        ]);

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->json('informes');

        $this->assertCount(0, $informes);
    }

    /* ------------------------------------------------------------------ 4 */

    /**
     * 🟠 HALLAZGO 4. El admin reintenta el mismo POST ante un timeout con el mismo wamid. Antes eso
     * dejaba dos mensajes del dueño, dos jobs y DOS WhatsApps con la misma respuesta.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_mismo_wamid_dos_veces_no_duplica_el_turno()
    {
        $wamid = 'wamid.HBgNNTQ5MTEyMzQ1Njc4';

        $primera = $this->mandar(['texto' => '¿Cuánto vendí ayer?', 'whatsapp_message_id' => $wamid])
            ->assertStatus(202);

        $segunda = $this->mandar(['texto' => '¿Cuánto vendí ayer?', 'whatsapp_message_id' => $wamid])
            ->assertStatus(202);

        $this->assertEquals(
            (int) $primera->json('ai_message_id'),
            (int) $segunda->json('ai_message_id'),
            'El reintento tiene que devolver el turno de la primera vez.'
        );

        $this->assertEquals(
            (int) $primera->json('ai_conversation_id'),
            (int) $segunda->json('ai_conversation_id')
        );

        $this->assertEquals(
            1,
            AiMessage::where('ai_conversation_id', $primera->json('ai_conversation_id'))->where('rol', 'user')->count(),
            'Un solo mensaje del dueño.'
        );

        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);
    }

    /**
     * Dos mensajes DISTINTOS con wamid distinto no se colapsan.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function dos_wamid_distintos_son_dos_turnos()
    {
        $this->mandar(['texto' => 'Primera', 'whatsapp_message_id' => 'wamid.uno'])->assertStatus(202);
        $segunda = $this->mandar(['texto' => 'Segunda', 'whatsapp_message_id' => 'wamid.dos'])->assertStatus(202);

        $this->assertEquals(
            2,
            AiMessage::where('ai_conversation_id', $segunda->json('ai_conversation_id'))->where('rol', 'user')->count()
        );
    }

    /* ------------------------------------------------------------------ 5 */

    /**
     * 🟠 HALLAZGO 5. Dos tarjetas de proveedores distintos (clave distinta, ninguna reemplaza a la
     * otra) no pueden llevarse las mismas fotos. La primera que confirma las sella; la segunda
     * tiene que cortar con el motivo, no crear una segunda compra con la misma factura.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function dos_tarjetas_no_se_llevan_la_misma_factura()
    {
        $this->dar_extension(self::SLUG_ESCANEO);

        $sur   = $this->crear_proveedor('Distribuidora Sur');
        $norte = $this->crear_proveedor('Mayorista Norte');

        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá esta factura']);
        $this->guardar_foto($del_dueno);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Es de Sur... no, de Norte']);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $tarjeta_sur = PropuestaCompraConFacturaIaHelper::proponer($contexto, $propone, ['proveedor' => 'Distribuidora Sur']);
        $tarjeta_norte = PropuestaCompraConFacturaIaHelper::proponer($contexto, $propone, ['proveedor' => 'Mayorista Norte']);

        $this->assertTrue($tarjeta_sur['ok'], 'Motivo: ' . json_encode($tarjeta_sur));
        $this->assertTrue($tarjeta_norte['ok'], 'Motivo: ' . json_encode($tarjeta_norte));

        $this->assertNotEquals(
            $tarjeta_sur['tarjeta_id'],
            $tarjeta_norte['tarjeta_id'],
            'Son dos claves distintas: ninguna reemplaza a la otra, que es lo que hace posible la carrera.'
        );

        $contestando = $this->cerrar_turno($conversation, $propone);

        $primera = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta_sur['tarjeta_id']);
        $segunda = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta_norte['tarjeta_id']);

        $this->assertTrue($primera['ok'], 'Motivo: ' . json_encode($primera));

        $this->assertFalse($segunda['ok'], 'La segunda no se puede llevar la misma factura.');
        $this->assertStringContainsString('ya se usaron', $segunda['error']);

        $this->assertEquals(
            1,
            ProviderOrderScan::where('user_id', $this->comercio->id)->count(),
            'Un solo escaneo: la factura es una sola.'
        );

        $this->assertEquals(
            0,
            ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $norte->id)->count(),
            'Y ninguna compra para el proveedor equivocado.'
        );
    }

    /**
     * 🔴 La otra mitad del hallazgo 5, y la que de verdad lo fija.
     *
     * El test de arriba corre las dos confirmaciones EN SERIE, así que pasa aunque no haya candado:
     * la segunda lee después del commit de la primera y ya ve las fotos selladas. Lo que un test de
     * un solo hilo NO puede reproducir es la carrera — dos workers adentro de sus transacciones al
     * mismo tiempo—, y ahí lo único que las serializa es el `SELECT ... FOR UPDATE`.
     *
     * Así que se verifica el candado mismo, leyendo el SQL que sale: la consulta que decide qué
     * fotos se llevan tiene que ir bloqueante. Sin `lockForUpdate()` este test se pone rojo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_lectura_de_las_fotos_al_confirmar_va_con_candado()
    {
        $this->dar_extension(self::SLUG_ESCANEO);
        $this->crear_proveedor('Distribuidora Sur');

        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'La factura de Sur']);
        $this->guardar_foto($del_dueno);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $tarjeta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['proveedor' => 'Distribuidora Sur']
        );

        $contestando = $this->cerrar_turno($conversation, $propone);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta['tarjeta_id']);

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $bloqueante = false;

        foreach ($consultas as $consulta) {

            $sql = strtolower((string) $consulta['query']);

            if (strpos($sql, 'ai_message_imagenes') !== false && strpos($sql, 'for update') !== false) {

                $bloqueante = true;
            }
        }

        $this->assertTrue(
            $bloqueante,
            'La lectura de las fotos al confirmar tiene que ir con lockForUpdate: es lo único que serializa '
            . 'dos confirmaciones simultáneas de tarjetas distintas sobre la misma factura.'
        );
    }

    /* ------------------------------------------------------------------ 6 */

    /**
     * 🟠 HALLAZGO 6. El admin reenvía lo que bajó de Kapso, y una descarga de media de WhatsApp
     * normalmente llega SIN extensión en el nombre. Validando por `getClientOriginalExtension()`,
     * un JPEG perfecto daba 422.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_sin_extension_en_el_nombre_se_acepta()
    {
        $respuesta = $this->mandar_con_fotos(
            ['texto' => 'Esto es la compra', 'tipo' => 'imagen'],
            [$this->foto('7d41b2c0e9')]
        );

        $respuesta->assertStatus(202);

        $user = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))
            ->where('rol', 'user')
            ->first();

        $this->assertEquals(1, AiMessageImagen::where('ai_message_id', $user->id)->count());
    }

    /**
     * Y lo que NO es una imagen se sigue rechazando, aunque el nombre diga que sí.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_archivo_que_miente_la_extension_se_rechaza_igual()
    {
        $respuesta = $this->mandar_con_fotos(
            ['texto' => 'Mirá', 'tipo' => 'imagen'],
            [UploadedFile::fake()->create('factura.jpg', 30)]
        );

        $respuesta->assertStatus(422);
    }

    /* ------------------------------------------------------------------ A */

    /**
     * 🔴 HALLAZGO A. Pedido textual de Lucas: que pueda hacerle preguntas a los informes. El admin
     * necesita el `ai_conversation_id` del informe para citarlo, y este canal tiene que aceptar esa
     * conversación aunque su origen sea 'mostrador_reporte'.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function preguntar_sobre_el_informe_cae_en_la_conversacion_de_ese_informe()
    {
        config(['services.mostrador.spa_url' => 'https://elcliente.comerciocity.com']);

        $informe = $this->informe();

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->assertStatus(200)
            ->json('informes');

        $this->assertArrayHasKey('ai_conversation_id', $informes[0], 'El admin necesita el id para citar.');

        $conversacion_id = (int) $informes[0]['ai_conversation_id'];

        $conversacion = AiConversation::find($conversacion_id);

        $this->assertEquals(MostradorReporte::ORIGEN_CONVERSACION, $conversacion->origen);
        $this->assertEquals((int) $informe->id, (int) $conversacion->referencia_id);
        $this->assertNotEmpty($conversacion->contexto, 'Sin el contexto del informe, el asistente contesta a ciegas.');

        // El dueño contesta citando el informe.
        $respuesta = $this->mandar([
            'texto'              => '¿Por qué bajó la caja?',
            'ai_conversation_id' => $conversacion_id,
        ])->assertStatus(202);

        $this->assertEquals(
            $conversacion_id,
            (int) $respuesta->json('ai_conversation_id'),
            'La pregunta tiene que caer en la conversación DEL INFORME.'
        );
    }

    /**
     * Idempotente: pedir los informes dos veces no abre dos conversaciones.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function pedir_los_informes_dos_veces_no_abre_dos_conversaciones()
    {
        config(['services.mostrador.spa_url' => 'https://elcliente.comerciocity.com']);

        $informe = $this->informe();

        $primera = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())->json('informes.0.ai_conversation_id');
        $segunda = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())->json('informes.0.ai_conversation_id');

        $this->assertEquals($primera, $segunda);

        $this->assertEquals(
            1,
            AiConversation::where('origen', MostradorReporte::ORIGEN_CONVERSACION)
                ->where('referencia_id', $informe->id)
                ->count()
        );
    }

    /**
     * Pero el canal NO continúa una conversación del panel: ese origen sigue afuera.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_canal_sigue_sin_continuar_una_conversacion_del_panel()
    {
        $del_panel = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->comercio->id,
            'last_message_at' => Carbon::now()->subMinute(),
        ]);

        $respuesta = $this->mandar(['ai_conversation_id' => $del_panel->id])->assertStatus(202);

        $this->assertNotEquals((int) $del_panel->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /* ------------------------------------------------------------------ B */

    /**
     * 🟠 HALLAZGO B. Una foto vieja de otra cosa —la góndola que el dueño mandó a la mañana
     * preguntando un precio— no puede entrar como página de la factura que manda a la tarde.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_de_muchos_mensajes_atras_no_entra_a_la_factura()
    {
        $this->dar_extension(self::SLUG_ESCANEO);
        $this->crear_proveedor('Distribuidora Sur');

        $conversation = $this->conversacion_whatsapp();

        // La góndola, al principio de la charla.
        $gondola = $this->mensaje($conversation, 'user', 'listo', ['contenido' => '¿A cuánto está esto?']);
        $foto_gondola = $this->guardar_foto($gondola);

        // Un rato de conversación en el medio, más largo que la ventana.
        for ($i = 0; $i < PropuestaCompraConFacturaIaHelper::MENSAJES_PARA_LAS_FOTOS; $i++) {
            $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => 'Respuesta ' . $i]);
            $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Pregunta ' . $i]);
        }

        // Y recién ahora la factura.
        $factura = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Esto es la compra de Distribuidora Sur']);
        $foto_factura = $this->guardar_foto($factura);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['proveedor' => 'Distribuidora Sur']
        );

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));

        $datos = AiMessageAction::find($respuesta['tarjeta_id'])->datos;

        $this->assertContains((int) $foto_factura->id, $datos['imagen_ids']);

        $this->assertNotContains(
            (int) $foto_gondola->id,
            $datos['imagen_ids'],
            'La góndola no es una página de la factura.'
        );

        $this->assertNotContains(
            (int) $foto_gondola->id,
            $datos['imagen_ids_miradas'],
            'Y ni siquiera se la mira: está fuera de la ventana de mensajes.'
        );
    }

    /**
     * Y las que se miraron pero no entraron al escaneo se sellan igual: si quedaran libres, se
     * colarían solas en la próxima compra, que puede ser de otro proveedor.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function las_fotos_que_sobran_se_sellan_y_no_quedan_para_la_proxima_compra()
    {
        $this->dar_extension(self::SLUG_ESCANEO);
        $this->crear_proveedor('Distribuidora Sur');

        $max = (int) config('services.escaneo_factura_compra.max_imagenes', 6);

        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Te mando la factura']);

        $fotos = [];

        for ($i = 1; $i <= $max + 2; $i++) {
            $fotos[] = $this->guardar_foto($del_dueno, $i);
        }

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['proveedor' => 'Distribuidora Sur']
        );

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));

        $datos = AiMessageAction::find($respuesta['tarjeta_id'])->datos;

        $this->assertCount($max, $datos['imagen_ids'], 'Al escaneo van las que acepta.');
        $this->assertCount($max + 2, $datos['imagen_ids_miradas'], 'Pero se miraron todas.');

        $contestando = $this->cerrar_turno($conversation, $propone);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $this->assertEquals(
            0,
            AiMessageImagen::where('user_id', $this->comercio->id)->whereNull('gestionada_at')->count(),
            'No puede quedar ninguna suelta esperando a la próxima compra.'
        );
    }

    /* ------------------------------------------------------------------ C */

    /**
     * 🟠 HALLAZGO C. Sin 409 `respuesta_en_curso` y con el admin despachando un job por mensaje,
     * dos mensajes seguidos del dueño generan dos turnos en paralelo. El segundo ve la tarjeta en
     * el historial y tiene otro `ai_message_id`: hasta el arreglo, podía confirmarla ANTES de que
     * la pregunta le hubiera llegado al dueño.
     *
     * Repro literal: "anotá un gasto de nafta de 5000" y, un segundo después, "gracias!".
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_turno_en_paralelo_no_puede_confirmar_lo_que_el_dueno_todavia_no_vio()
    {
        $conversation = $this->conversacion_whatsapp();

        // Mensaje 1 del dueño y su turno, que todavía se está generando.
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'anotá un gasto de nafta de 5000']);
        $turno_uno = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        // El turno 1 propone la tarjeta, pero su texto todavía no salió por WhatsApp.
        $tarjeta = $this->tarjeta($conversation, $turno_uno);

        // Mensaje 2, un segundo después, y su turno en paralelo.
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'gracias!']);
        $turno_dos = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $antes = Pending::where('user_id', $this->comercio->id)->count();

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $turno_dos, $tarjeta->id);

        $this->assertFalse($resultado['ok'], 'El turno paralelo NO puede confirmar.');
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_MISMO_TURNO, $resultado['error']);

        $this->assertEquals(
            $antes,
            Pending::where('user_id', $this->comercio->id)->count(),
            'Y sobre todo: no se registró nada.'
        );

        $this->assertEquals(
            AiMessageAction::ESTADO_PROPUESTA,
            AiMessageAction::find($tarjeta->id)->estado_guardado()
        );
    }

    /**
     * La otra mitad de C: el "sí" tiene que ser POSTERIOR a la propuesta. Un turno cuyo mensaje
     * disparador es anterior a la tarjeta arrancó antes de que la tarjeta existiera.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_si_anterior_a_la_propuesta_no_confirma()
    {
        $conversation = $this->conversacion_whatsapp();

        // El "sí" llega primero (un turno que arrancó antes).
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'sí, dale']);
        $turno = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        // Y recién después queda la tarjeta, de un mensaje ya cerrado pero POSTERIOR al "sí".
        $propuso = $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿Lo registro?']);
        $tarjeta = $this->tarjeta($conversation, $propuso);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $turno, $tarjeta->id);

        $this->assertFalse($resultado['ok']);
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_MISMO_TURNO, $resultado['error']);
    }

    /**
     * Y una tarjeta propuesta EN LA PANTALLA no se confirma por texto: la persona la miró y
     * deliberadamente no la tocó.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_tarjeta_propuesta_en_la_pantalla_no_se_confirma_por_whatsapp()
    {
        $conversation = $this->conversacion_whatsapp();

        $del_sistema = $this->mensaje($conversation, 'assistant', 'listo', [
            'canal'     => AiMessage::CANAL_SISTEMA,
            'contenido' => 'Te dejé la tarjeta para confirmar.',
        ]);

        $tarjeta = $this->tarjeta($conversation, $del_sistema);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'sí']);
        $por_whatsapp = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $por_whatsapp, $tarjeta->id);

        $this->assertFalse($resultado['ok']);
        $this->assertEquals(ConfirmacionPorTextoIaHelper::MENSAJE_OTRO_CANAL, $resultado['error']);

        $this->assertEquals(
            AiMessageAction::ESTADO_PROPUESTA,
            AiMessageAction::find($tarjeta->id)->estado_guardado()
        );
    }

    /**
     * El camino feliz sigue andando: propuesta cerrada, el "sí" después, y se registra.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_camino_feliz_de_la_confirmacion_sigue_andando()
    {
        $conversation = $this->conversacion_whatsapp();

        $propuso = $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿Lo registro?']);
        $tarjeta = $this->tarjeta($conversation, $propuso);

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'sí, dale']);
        $contestando = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $antes = Pending::where('user_id', $this->comercio->id)->count();

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta->id);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $this->assertEquals($antes + 1, Pending::where('user_id', $this->comercio->id)->count());
    }

    /* ------------------------------------------------------------------ D */

    /**
     * 🟠 HALLAZGO D. Un audio que Kapso no pudo transcribir se contesta de forma determinista, sin
     * salir a la IA y sin depender de que el modelo reconozca un literal en el historial.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_audio_sin_transcribir_se_contesta_sin_llamar_a_la_ia()
    {
        $respuesta = $this->mandar([
            'tipo'  => 'audio',
            'texto' => AsistenteController::AUDIO_SIN_TRANSCRIPCION,
        ])->assertStatus(202);

        $assistant = AiMessage::find($respuesta->json('ai_message_id'));

        $this->assertEquals('listo', $assistant->estado);
        $this->assertEquals(AsistenteController::RESPUESTA_AUDIO_SIN_TRANSCRIPCION, $assistant->contenido);

        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);

        $this->assertEquals('listo', $respuesta->json('estado'), 'El admin se entera de que ya está resuelto.');
    }

    /**
     * Y el `tipo` queda guardado: viajaba en el contrato y se tiraba.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_tipo_del_mensaje_queda_guardado()
    {
        $respuesta = $this->mandar(['tipo' => 'audio', 'texto' => 'Che, ¿cuánto vendí ayer?'])->assertStatus(202);

        $user = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))
            ->where('rol', 'user')
            ->first();

        $this->assertEquals(AiMessage::TIPO_AUDIO, $user->tipo);

        /* Un audio transcripto sí sale a la IA: lo determinista es solo el que llegó sin texto. */
        Queue::assertPushed(ResponderMensajeChatIaJob::class);
    }

    /* ------------------------------------------------------------------ E */

    /**
     * 🔵 HALLAZGO E. El estado de la compra se resuelve por NOMBRE: el id 1 dependía del orden en
     * que corrió un seeder, y un "1" que fuera "Recibido" marcaría como recibida una compra que
     * todavía no llegó.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_estado_de_la_compra_se_resuelve_por_nombre_y_no_por_el_id_1()
    {
        $this->dar_extension(self::SLUG_ESCANEO);
        $proveedor = $this->crear_proveedor('Distribuidora Sur');

        /*
         * 🔴 Acá está lo que hace sensible al test. En el fixture "En proceso" ES el id 1, así que
         * con el `1` hardcodeado el test pasaba igual y no probaba nada. Se arma a propósito el
         * caso que rompía: el listado de estados reordenado, con el id 1 ocupado por otro nombre.
         * Todo esto se revierte con la transacción del test.
         */
        ProviderOrderStatus::where('name', PropuestaCompraConFacturaIaHelper::ESTADO_EN_PROCESO)
            ->update(['name' => 'Pedido al proveedor']);

        $en_proceso = ProviderOrderStatus::create([
            'name' => PropuestaCompraConFacturaIaHelper::ESTADO_EN_PROCESO,
        ]);

        $this->assertGreaterThan(
            1,
            (int) $en_proceso->id,
            'El estado "En proceso" tiene que quedar en un id distinto de 1 para que el test sirva.'
        );

        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'La factura de Sur']);
        $this->guardar_foto($del_dueno);

        $propone = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $tarjeta = PropuestaCompraConFacturaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $propone,
            ['proveedor' => 'Distribuidora Sur']
        );

        $contestando = $this->cerrar_turno($conversation, $propone);

        ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $tarjeta['tarjeta_id']);

        $compra = ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $proveedor->id)->first();

        $this->assertNotNull($compra);

        $this->assertEquals(
            (int) $en_proceso->id,
            (int) $compra->provider_order_status_id,
            'La compra nace "En proceso", resuelto por nombre.'
        );
    }

    /* --------------------------------------------------------- utilidades */

    /**
     * Un proveedor con sus cuentas corrientes, como lo deja la pantalla.
     *
     * @param  string  $nombre
     * @return \App\Models\Provider
     */
    protected function crear_proveedor($nombre)
    {
        $proveedor = Provider::create(['user_id' => $this->comercio->id, 'name' => $nombre]);

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->comercio->id);

        return $proveedor;
    }

    /**
     * Un informe del mostrador ya depositado por la skill.
     *
     * @param  array  $overrides
     * @return \App\Models\MostradorReporte
     */
    protected function informe(array $overrides = [])
    {
        return MostradorReporte::create(array_merge([
            'user_id'     => $this->comercio->id,
            'tipo'        => 'dia',
            'fecha'       => Carbon::yesterday()->format('Y-m-d'),
            'titulo'      => 'Ayer vendiste bien',
            'resumen'     => 'Cerraste 38 ventas por $ 420.000.',
            'contenido'   => ['bloques' => [['tipo' => 'parrafo', 'texto' => 'Ayer fue un buen día.']]],
            'hechos'      => ['ventas' => 38],
            'estado'      => MostradorReporte::ESTADO_LISTO,
            'generado_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * @return string  Un PNG chiquito de verdad.
     */
    protected function binario_png()
    {
        $recurso = imagecreatetruecolor(30, 30);

        ob_start();
        imagepng($recurso);

        return ob_get_clean();
    }

    /**
     * Guarda una foto en el disco fake y deja su fila sin gestionar.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $orden = 1)
    {
        $binario = $this->binario_png();

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
     * Una tarjeta de tarea nueva, colgada de un mensaje del assistant.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $mensaje
     * @return \App\Models\AiMessageAction
     */
    protected function tarjeta($conversation, AiMessage $mensaje)
    {
        return AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $mensaje->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $conversation->auth_user_id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva:chequeo:' . uniqid(),
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [
                'detalle'               => 'Cargar nafta ' . uniqid(),
                'fecha_realizacion'     => Carbon::today()->addDays(2)->format('Y-m-d'),
                'es_recurrente'         => false,
                'unidad_frecuencia_id'  => null,
                'cantidad_frecuencia'   => null,
                'fecha_fin_recurrencia' => null,
                'expense_concept_id'    => null,
                'expense_amount'        => null,
                'notas'                 => null,
            ],
            'presentacion'       => [
                'titulo'    => 'Tarea en la agenda',
                'renglones' => [['etiqueta' => 'Qué', 'valor' => 'Cargar nafta']],
                'aviso'     => null,
            ],
        ]);
    }

    /**
     * Cierra el turno que propuso, como hace el job al terminar, y devuelve el assistant del turno
     * siguiente (el que contesta el "sí").
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $propuso
     * @return \App\Models\AiMessage
     */
    protected function cerrar_turno($conversation, AiMessage $propuso)
    {
        $propuso->estado = 'listo';
        $propuso->contenido = '¿Lo registro?';
        $propuso->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí, dale']);

        return $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
    }
}
