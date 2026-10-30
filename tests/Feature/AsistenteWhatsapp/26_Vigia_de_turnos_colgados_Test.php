<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Http\Controllers\Helpers\asistente_ia\VigiaDeTurnosIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Misión agente-mejoras-uso-real, ronda de arreglos de la revisión independiente (30/10/2026), arreglo
 * 2 — EL VIGÍA DE LOS TURNOS COLGADOS.
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 6 del 28/10/2026, 18:28): «Hola. Cargame un gasto de 207.500 con la
 * referencia cuota red signar» quedó con el mensaje del asistente en `pendiente` para siempre. Lo que
 * fija este archivo:
 *   - el comando `asistente:vigilar-turnos` cierra los `pendiente` de más de 10 minutos con el texto de
 *     siempre, descarta sus tarjetas y, si eran de WhatsApp, deja el aviso citando el pedido;
 *   - no toca los recientes ni los que ya terminaron;
 *   - un job revivido tarde (la cola que volvió) no corre el turno viejo: no llama al proveedor ni
 *     carga nada.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Vigia_de_turnos_colgados_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Event::fake([ChatIaMensajeActualizado::class]);

        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', 'https://ferretotal.comerciocity.com');
    }

    /**
     * El pedido de CONV 6 y su assistant `pendiente`, creados hace `$minutos`.
     *
     * @param  int  $minutos
     * @param  string  $canal
     * @return array{0: \App\Models\AiConversation, 1: AiMessage, 2: AiMessage}
     */
    protected function colgado($minutos = 15, $canal = AiMessage::CANAL_WHATSAPP)
    {
        $conversation = $this->conversacion_whatsapp();

        $pedido = $this->mensaje($conversation, 'user', 'listo', [
            'contenido' => 'Hola. Cargame un gasto de 207.500 con la referencia cuota red signar',
            'canal'     => $canal,
        ]);

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true, 'canal' => $canal]);

        $hace = Carbon::now()->subMinutes($minutos);

        DB::table('ai_messages')->whereIn('id', [$pedido->id, $assistant->id])->update(['created_at' => $hace]);

        return [$conversation, $pedido->fresh(), $assistant->fresh()];
    }

    /**
     * 🔴 El turno colgado de WhatsApp: queda en error con el texto de siempre, su tarjeta propuesta se
     * descarta, el dueño recibe el aviso citando su pedido, y la pantalla se entera.
     *
     * @test
     */
    public function el_vigia_cierra_el_turno_colgado_y_deja_el_aviso_citando_el_pedido()
    {
        list($conversation, $pedido, $assistant) = $this->colgado(15);

        $tarjeta = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_GASTO,
            'clave'              => 'gasto:e1r-vigia',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Gasto', 'renglones' => [], 'aviso' => null],
        ]);

        $this->artisan('asistente:vigilar-turnos')->assertExitCode(0);

        $assistant->refresh();
        $this->assertSame('error', $assistant->estado);
        $this->assertSame(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, (string) $assistant->contenido);
        $this->assertStringContainsString('vigía', (string) $assistant->error_mensaje);
        $this->assertSame(AiMessageAction::ESTADO_DESCARTADA, $tarjeta->fresh()->estado_guardado());

        $aviso = AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->first();
        $this->assertNotNull($aviso);
        $this->assertSame(
            'No te pude contestar "Hola. Cargame un gasto de 207.500 con la referencia cuota…"'
            . ' (' . Carbon::parse($pedido->created_at)->format('d/m H:i') . '). ¿Lo retomo?',
            $aviso->texto
        );

        $avisos = $this->getJson('api/admin-sync/asistente/avisos-pendientes', $this->headers())->json('avisos');
        $this->assertCount(1, $avisos);
        $this->assertSame($aviso->texto, $avisos[0]['texto']);

        Event::assertDispatched(ChatIaMensajeActualizado::class, function ($evento) use ($assistant) {
            return $evento->ai_message_id === $assistant->id && $evento->estado === 'error';
        });

        /* Una segunda corrida no hace nada más: ni otro cierre ni otro aviso. */
        $this->assertSame(0, VigiaDeTurnosIaHelper::cerrar_colgados());
        $this->assertSame(1, AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Un pedido largo se cita cortado, y uno de solo fotos dice cuántas.
     *
     * @test
     */
    public function la_cita_se_corta_y_un_pedido_de_solo_fotos_lo_dice()
    {
        list(, $pedido, $assistant) = $this->colgado(20);

        $pedido->contenido = str_repeat('cargame este gasto largo ', 10);
        $pedido->save();

        VigiaDeTurnosIaHelper::cerrar_colgados();

        $texto = AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->value('texto');
        $this->assertStringContainsString('…"', $texto, 'La cita va cortada con puntos suspensivos.');
        $this->assertLessThan(140, mb_strlen($texto));
    }

    /**
     * No toca lo que no está colgado: un `pendiente` reciente (el job puede estar corriendo) ni uno
     * que ya terminó. Y por el canal del sistema cierra pero no deja aviso de WhatsApp.
     *
     * @test
     */
    public function no_toca_los_recientes_ni_los_terminados_y_fuera_de_whatsapp_no_avisa()
    {
        list(, , $reciente) = $this->colgado(3);
        list(, , $terminado) = $this->colgado(30);
        $terminado->estado = 'listo';
        $terminado->contenido = 'Listo, cargué el gasto.';
        $terminado->save();
        list(, , $del_sistema) = $this->colgado(30, AiMessage::CANAL_SISTEMA);

        VigiaDeTurnosIaHelper::cerrar_colgados();

        $this->assertSame('pendiente', $reciente->fresh()->estado);
        $this->assertSame('listo', $terminado->fresh()->estado);
        $this->assertSame('Listo, cargué el gasto.', (string) $terminado->fresh()->contenido);
        $this->assertSame('error', $del_sistema->fresh()->estado);
        $this->assertSame(0, AiAvisoDelAsistente::whereIn('ai_message_id', [$reciente->id, $terminado->id, $del_sistema->id])->count());
    }

    /**
     * 🔴 Un turno revivido horas tarde (la cola que vuelve) no corre: el job ve que su mensaje ya no está
     * `pendiente` y vuelve sin llamar al proveedor de IA ni cargar nada. Ya era así (handle() lo mira
     * primero); este test lo fija junto con el vigía, que es quien lo deja en `error`.
     *
     * @test
     */
    public function un_job_revivido_despues_del_vigia_no_corre_el_turno_viejo()
    {
        config(['services.anthropic.api_key' => 'clave-anthropic-de-prueba-e1r']);
        Http::fake();

        list(, , $assistant) = $this->colgado(240);

        VigiaDeTurnosIaHelper::cerrar_colgados();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('error', $assistant->fresh()->estado);
        $this->assertSame(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, (string) $assistant->fresh()->contenido);
    }

    /**
     * El comando está programado cada 5 minutos.
     *
     * @test
     */
    public function el_comando_esta_programado_cada_cinco_minutos()
    {
        /* Mismo mecanismo que Infraestructura/5_Gates_del_schedule_Test: schedule() es protected. */
        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        $kernel = $this->app->make(\App\Console\Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'schedule');
        $metodo->setAccessible(true);
        $metodo->invoke($kernel, $schedule);

        $evento = null;

        foreach ($schedule->events() as $uno) {
            if (strpos((string) $uno->command, 'asistente:vigilar-turnos') !== false) {
                $evento = $uno;
            }
        }

        $this->assertNotNull($evento, 'El vigía no está en el schedule.');
        $this->assertSame('*/5 * * * *', $evento->expression);

        /* El gate: sin ningún turno colgado no se arranca ningún artisan; con uno, sí. */
        AiMessage::where('rol', 'assistant')->where('estado', 'pendiente')->update(['estado' => 'listo']);
        $this->assertFalse($evento->filtersPass($this->app));
        $this->colgado(15);
        $this->assertTrue($evento->filtersPass($this->app));
    }
}
