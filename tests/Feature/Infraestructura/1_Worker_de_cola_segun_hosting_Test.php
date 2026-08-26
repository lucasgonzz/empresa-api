<?php

namespace Tests\Feature\Infraestructura;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Misión queue-work-solo-en-shared — el scheduler no programa el worker de cola en el VPS.
 *
 * En el shared hosting hay UN solo cron por instancia, apuntando a schedule:run, y el
 * queue:work --stop-when-empty de adentro del schedule es lo unico que procesa la cola.
 * En el VPS, en cambio, la cola la maneja supervisor con un worker de larga vida: si el
 * scheduler ademas programara el suyo, quedarian dos compitiendo por los mismos jobs.
 *
 * La instancia se identifica con VPS=true en su .env (config app.VPS), la misma variable
 * que config/filesystems.php ya usa para el prefijo /public de los archivos.
 *
 * Lo que protege este test es justamente lo peligroso de los dos lados:
 *   - que sacar el worker del VPS no se lleve puesto al shared (donde nada mas lo procesa),
 *   - y que el default de la variable deje intacta a toda instancia que no la declare.
 */
class Worker_de_cola_segun_hosting_Test extends TestCase
{
    /** Fragmento que identifica al comando de cola dentro del comando compilado. */
    const COMANDO_DE_COLA = 'queue:work';

    /**
     * Corre el schedule() del Kernel contra un Schedule limpio y devuelve los comandos
     * que quedaron programados.
     *
     * Se invoca por reflexion porque schedule() es protected: es la unica forma de
     * ejercitar la decision real del Kernel en vez de reimplementarla en el test.
     *
     * @return array
     */
    protected function comandos_programados()
    {
        $schedule = new Schedule();

        $kernel = $this->app->make(Kernel::class);
        $metodo = new \ReflectionMethod($kernel, 'schedule');
        $metodo->setAccessible(true);
        $metodo->invoke($kernel, $schedule);

        $comandos = [];
        foreach ($schedule->events() as $evento) {
            $comandos[] = (string) $evento->command;
        }

        return $comandos;
    }

    /**
     * ¿Quedó programado el worker de cola?
     *
     * @param  array  $comandos
     * @return bool
     */
    protected function programa_el_worker(array $comandos)
    {
        foreach ($comandos as $comando) {
            if (strpos($comando, self::COMANDO_DE_COLA) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * En shared hosting el scheduler tiene que seguir programando el worker: es lo unico
     * que procesa la cola ahi.
     *
     * @return void
     */
    public function test_en_shared_hosting_el_scheduler_programa_el_worker_de_cola()
    {
        config(['app.VPS' => false]);

        $this->assertTrue(
            $this->programa_el_worker($this->comandos_programados()),
            'En shared hosting el scheduler DEBE programar queue:work: es lo unico que procesa la cola.'
        );
    }

    /**
     * En el VPS no, porque ahi lo corre supervisor.
     *
     * @return void
     */
    public function test_en_vps_el_scheduler_no_programa_el_worker_de_cola()
    {
        config(['app.VPS' => true]);

        $this->assertFalse(
            $this->programa_el_worker($this->comandos_programados()),
            'En el VPS el scheduler NO debe programar queue:work: lo maneja supervisor y quedarian dos workers.'
        );
    }

    /**
     * Una instancia que nunca declaro la variable se comporta como shared hosting.
     *
     * Es la compatibilidad hacia atras, y es la que cubre a las 97 instancias del shared que
     * hoy no tienen VPS en su .env: no depende de que nadie se acuerde de agregarla.
     *
     * @return void
     */
    public function test_sin_la_variable_declarada_se_comporta_como_shared_hosting()
    {
        config(['app.VPS' => null]);

        $this->assertTrue(
            $this->programa_el_worker($this->comandos_programados()),
            'Sin VPS declarada el default tiene que ser shared hosting, o una instancia vieja se queda sin cola.'
        );
    }

    /**
     * El cambio no puede haberse llevado puesto ningun otro comando del schedule.
     *
     * Sin esto, un if mal cerrado que dejara la mitad del schedule adentro pasaria los tres
     * tests de arriba sin que nadie se entere.
     *
     * @return void
     */
    public function test_el_resto_del_schedule_no_depende_del_hosting()
    {
        config(['app.VPS' => false]);
        $en_shared = $this->comandos_programados();

        config(['app.VPS' => true]);
        $en_vps = $this->comandos_programados();

        $solo_en_shared = array_values(array_diff($en_shared, $en_vps));

        $this->assertCount(
            1,
            $solo_en_shared,
            'Lo unico que puede cambiar entre shared y VPS es el worker de cola. Cambio de mas: '
                . implode(' | ', $solo_en_shared)
        );

        $this->assertStringContainsString(
            self::COMANDO_DE_COLA,
            $solo_en_shared[0],
            'La unica diferencia entre los dos hostings tiene que ser el comando de cola.'
        );

        $this->assertEmpty(
            array_diff($en_vps, $en_shared),
            'El VPS no puede programar ningun comando que el shared no programe.'
        );
    }
}
