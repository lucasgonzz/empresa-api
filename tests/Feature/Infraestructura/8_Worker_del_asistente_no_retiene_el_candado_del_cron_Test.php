<?php

namespace Tests\Feature\Infraestructura;

use App\Console\Kernel;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Process\Process;
use Tests\Feature\AsistenteWhatsapp\AsistenteWhatsappTestCase;

/**
 * Misión asistente-whatsapp-velocidad, ETAPA 2 (empresa-api), ronda 2 — CA16 (Enmienda 7 del plan).
 *
 * El problema: en el hosting compartido `schedule:run` corre bajo `flock -n <slug>.schedule.lock`
 * (cc-cron.sh) y bajo el envoltorio de Hostinger (`flock -w 1 /tmp/cron_lock_<uid> …`). Los procesos
 * que `runInBackground()` lanza heredan esos descriptores, y como el worker vivo del asistente dura
 * ~50 s, el `schedule:run` del minuto siguiente se saltearía entero. El comando del evento tiene que
 * cerrar los descriptores heredados (3 a 9) en el subshell de fondo antes del worker.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, argumentos nombrados, union types ni enum.
 *
 * @group asistente-whatsapp
 */
class Worker_del_asistente_no_retiene_el_candado_del_cron_Test extends AsistenteWhatsappTestCase
{
    /** Comando del worker del asistente que los demás tests también buscan. */
    const COMANDO_DEL_WORKER = 'queue:work --queue=default_asistente --sleep=1 --max-time=50';

    /** @var string[] Archivos temporales de candado que hay que borrar al terminar. */
    protected $archivos_de_candado = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.VPS'                          => false,
            'queue.scheduler_worker'           => true,
            'queue.asistente_worker'           => true,
            'queue.connections.database.queue' => 'default',
            'app.USER_ID'                      => $this->comercio->id,
        ]);
        $this->dar_extension();
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_de_candado as $archivo) {
            if (file_exists($archivo)) {
                unlink($archivo);
            }
        }

        parent::tearDown();
    }

    /**
     * El evento del worker del asistente que arma el schedule() del Kernel.
     *
     * @return \Illuminate\Console\Scheduling\Event
     */
    protected function evento_del_worker_del_asistente()
    {
        $schedule = new Schedule();

        $kernel = $this->app->make(Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'schedule');
        $metodo->setAccessible(true);
        $metodo->invoke($kernel, $schedule);

        foreach ($schedule->events() as $evento) {
            $comando = (string) $evento->command;
            if (strpos($comando, 'queue:work') !== false && strpos($comando, '_asistente') !== false) {
                return $evento;
            }
        }

        $this->fail('El schedule no programa el worker del asistente.');
    }

    /**
     * Crea un archivo temporal de candado y lo registra para borrarlo al final.
     *
     * @return string
     */
    protected function archivo_de_candado()
    {
        $archivo = tempnam(sys_get_temp_dir(), 'cc_candado_');
        $this->archivos_de_candado[] = $archivo;

        return $archivo;
    }

    /**
     * Toma el candado con `flock -n` y, bajo él, lanza un proceso de fondo con el MISMO armado que
     * Laravel (`( … ; … "$?") > /dev/null 2>&1 &`) que vive unos segundos. Después, mientras ese
     * proceso sigue vivo, intenta una segunda toma `flock -n` del mismo archivo.
     *
     * @param  string  $prefijo  Lo que va delante del proceso de fondo ('' = sin cierre de descriptores).
     * @return int Código de salida de la segunda toma (0 = pudo tomarlo).
     */
    protected function codigo_de_la_segunda_toma($prefijo)
    {
        $archivo = $this->archivo_de_candado();

        $fondo = '(' . $prefijo . 'sleep 3 ; true "$?") > /dev/null 2>&1 &';
        $primera = Process::fromShellCommandline(
            'flock -n ' . escapeshellarg($archivo) . ' sh -c ' . escapeshellarg($fondo)
        );
        $primera->run();
        $this->assertSame(0, $primera->getExitCode(), 'La primera toma del candado tiene que funcionar: ' . $primera->getErrorOutput());

        // El proceso de fondo sigue vivo (duerme 3 s): la segunda toma se hace ahora.
        $segunda = Process::fromShellCommandline('flock -n ' . escapeshellarg($archivo) . ' true');
        $segunda->run();
        $codigo = $segunda->getExitCode();

        // Se espera a que muera el proceso de fondo para no dejar nada vivo detrás.
        sleep(4);

        return $codigo;
    }

    /**
     * CA16 a): el comando del evento empieza cerrando los descriptores heredados y conserva el
     * `queue:work` de siempre y su configuración de schedule.
     */
    public function test_el_comando_del_worker_cierra_los_descriptores_heredados()
    {
        $evento = $this->evento_del_worker_del_asistente();
        $comando = (string) $evento->command;
        $prefijo = AsistenteCanalHelper::PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS;

        $this->assertSame(0, strpos($comando, $prefijo), 'El comando tiene que empezar cerrando los descriptores heredados.');
        $this->assertStringContainsString('exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-', $prefijo);
        $this->assertStringContainsString(self::COMANDO_DEL_WORKER, $comando);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(10, $evento->expiresAt);
        $this->assertTrue($evento->runInBackground);
        $this->assertSame('* * * * *', $evento->expression);

        // Determinístico: schedule:finish rearma el schedule y tiene que obtener el mismo mutex.
        $this->assertSame($evento->mutexName(), $this->evento_del_worker_del_asistente()->mutexName());
    }

    /**
     * CA16 b): con procesos reales, el proceso de fondo con el prefijo del Kernel no retiene el
     * candado; el mismo armado sin prefijo SÍ lo retiene (control).
     */
    public function test_el_proceso_de_fondo_no_retiene_el_candado_pero_sin_el_cierre_si()
    {
        if (trim((string) shell_exec('command -v flock')) === '') {
            $this->markTestSkipped('No hay binario flock en este entorno.');
        }

        $prefijo = AsistenteCanalHelper::PREFIJO_CIERRE_DE_CANDADOS_HEREDADOS;

        // Control: sin el cierre, el proceso de fondo hereda el candado y la segunda toma falla.
        $this->assertNotSame(0, $this->codigo_de_la_segunda_toma(''), 'Control: sin el cierre el candado queda retenido.');

        // Con el prefijo real del Kernel, la segunda toma tiene éxito mientras el proceso vive.
        $this->assertSame(0, $this->codigo_de_la_segunda_toma($prefijo), 'Con el cierre el candado queda libre.');
    }
}
