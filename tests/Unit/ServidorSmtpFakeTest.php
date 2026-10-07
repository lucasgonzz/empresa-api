<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Fakes\FallaSiElServidorSmtpNoArranca;
use Tests\Fakes\ServidorSmtpFake;

/**
 * El servidor SMTP de prueba mismo (`ServidorSmtpFake`): arranca, anuncia su puerto y NO deja colgada la corrida si el hijo no arranca.
 *
 * Es la única prueba de que los tests de mails rechazados no pueden quedarse esperando para siempre: en Windows una tubería de `proc_open` no admite lectura con
 * timeout, así que `levantar()` lee el puerto de un archivo con sondeo y tope (ver su docblock). Sin esto, un hijo colgado dejaba a todo PHPUnit esperando.
 *
 * Extiende el `TestCase` de PHPUnit y no el de la aplicación: no necesita Laravel ni la base.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ServidorSmtpFakeTest extends TestCase
{
    // Si el servidor no arranca y el entorno podía lanzarlo, el test FALLA (no se saltea en silencio).
    use FallaSiElServidorSmtpNoArranca;

    /**
     * El servidor arranca, anuncia un puerto y habla SMTP en 127.0.0.1: contesta el saludo 220.
     *
     * @return void
     */
    public function test_arranca_anuncia_su_puerto_y_saluda_en_loopback(): void
    {
        $servidor = ServidorSmtpFake::levantar(ServidorSmtpFake::MODO_ACEPTA);

        if ($servidor === null) {
            $this->el_servidor_smtp_no_arranco();
        }

        try {
            $this->assertGreaterThan(0, $servidor->puerto);

            $conexion = @stream_socket_client('tcp://127.0.0.1:' . $servidor->puerto, $codigo, $texto, 5);

            $this->assertIsResource($conexion, 'No se pudo conectar al servidor de prueba en 127.0.0.1:' . $servidor->puerto);

            stream_set_timeout($conexion, 5);

            $this->assertStringStartsWith('220 ', (string) fgets($conexion));

            fclose($conexion);
        } finally {
            $servidor->bajar();
        }
    }

    /**
     * 🔴 Un hijo COLGADO (arranca pero no llega a anunciar su puerto) no deja la corrida esperando: `levantar()` lo baja al vencer la espera y devuelve null.
     *
     * Con la lectura bloqueante de una tubería (la versión anterior) este test no terminaba nunca en Windows.
     *
     * @return void
     */
    public function test_un_hijo_que_no_anuncia_su_puerto_no_cuelga_la_corrida(): void
    {
        $this->saltear_si_no_se_pueden_lanzar_procesos();

        $inicio   = microtime(true);
        $servidor = ServidorSmtpFake::levantar(
            ServidorSmtpFake::MODO_ACEPTA,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fakes' . DIRECTORY_SEPARATOR . 'hijo_que_no_anuncia_puerto.php',
            1.0
        );
        $duracion = microtime(true) - $inicio;

        $this->assertNull($servidor, 'El hijo no anunció ningún puerto: levantar() tiene que devolver null.');
        $this->assertGreaterThanOrEqual(0.9, $duracion, 'Esperó menos que el plazo que se le dio: no le dio tiempo al hijo.');
        $this->assertLessThan(8.0, $duracion, 'No respetó el tope de espera: se quedó esperando al hijo colgado.');
    }

    /**
     * Un hijo que MUERE antes de anunciar el puerto se detecta al instante: no se espera el resto del plazo.
     *
     * @return void
     */
    public function test_un_hijo_que_muere_antes_de_anunciar_devuelve_null_enseguida(): void
    {
        $this->saltear_si_no_se_pueden_lanzar_procesos();

        $inicio   = microtime(true);
        $servidor = ServidorSmtpFake::levantar(ServidorSmtpFake::MODO_ACEPTA, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fakes' . DIRECTORY_SEPARATOR . 'no_existe.php', 30.0);
        $duracion = microtime(true) - $inicio;

        $this->assertNull($servidor);
        $this->assertLessThan(10.0, $duracion, 'Un hijo muerto no puede hacer esperar el plazo entero (30 s): hay que verlo con proc_get_status().');
    }

    /**
     * Salta el test si este entorno no puede lanzar procesos (la misma precondición que mira `levantar()`).
     *
     * @return void
     */
    private function saltear_si_no_se_pueden_lanzar_procesos(): void
    {
        if (! function_exists('proc_open') || ! is_string(PHP_BINARY) || PHP_BINARY === '') {
            $this->markTestSkipped('Este entorno no puede lanzar procesos (sin proc_open o sin PHP_BINARY).');
        }
    }
}
