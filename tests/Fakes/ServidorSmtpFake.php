<?php

namespace Tests\Fakes;

use Illuminate\Support\Facades\Mail;

/**
 * Un servidor SMTP de verdad, en un proceso aparte, para probar lo que SwiftMailer hace con las
 * respuestas reales de un servidor de correo.
 *
 * Existe por un defecto que ningún `Mail::fake()` ni el transporte `array` pueden mostrar: cuando el
 * servidor RECHAZA la casilla (550 en el RCPT TO), SwiftMailer no tira excepción. `send()` vuelve
 * normal y las casillas rechazadas quedan en `Mail::mailer(...)->failures()`. Quien solo mira si
 * `send()` tiró da por enviado un mail que nunca salió. Para ver eso hace falta un servidor que
 * conteste 550 de verdad; el script que lo hace es `servidor_smtp_fake.php`, en esta misma carpeta.
 *
 * Se lanza con el PHP del propio test (`PHP_BINARY`), sin Python ni nada instalado aparte, en un
 * puerto libre que elige el sistema, y escucha SOLO en 127.0.0.1: no sale a internet.
 *
 * 🔴 EN `empresa-api` HAY UN PASO MÁS que en `admin-api`: el freno de internet de los tests reemplaza el
 * transporte `smtp` por uno en memoria en cada `setUp` (ver `MailManagerSoloLoopback`). Antes de usar este
 * servidor hay que llamar a `restaurar_el_transporte_smtp()` (lo hace `apuntar_el_mailer()`, y los tests que
 * dejan que la aplicación arme su propio mailer —el botón de probar SMTP, que lee el host de
 * `online_configurations`— lo llaman directo).
 *
 * Si en el entorno no se puede lanzar un proceso, `levantar()` devuelve null; el test que lo pidió usa
 * `FallaSiElServidorSmtpNoArranca` para saltearse SOLO en ese caso y fallar en cualquier otro.
 *
 * Uso:
 *
 *   $smtp = ServidorSmtpFake::levantar(ServidorSmtpFake::MODO_RECHAZA);
 *   $smtp->apuntar_el_mailer('smtp');
 *   ... el código que manda el mail por ese mailer ...
 *   $smtp->bajar();
 */
class ServidorSmtpFake
{
    /**
     * Contesta 550 a cada RCPT TO: la casilla "no existe".
     */
    const MODO_RECHAZA = 'rechaza';

    /**
     * Acepta todo: el mail "sale" y el servidor lo descarta.
     */
    const MODO_ACEPTA = 'acepta';

    /**
     * Contesta 451 a cada RCPT TO: un rechazo TEMPORAL (greylisting, buzón lleno por ahora). SwiftMailer lo anota en `failures()` igual que un 550.
     */
    const MODO_TEMPORAL = 'temporal';

    /**
     * Rechaza (550) solo a los destinatarios cuya casilla contiene la palabra `rechazada` y acepta a los demás: un mail con un destinatario aceptado y
     * otro rechazado.
     */
    const MODO_PARCIAL = 'parcial';

    /**
     * Segundos de vida del proceso. Es un techo para que, si el test que lo lanzó muere sin poder
     * bajarlo, no quede un proceso colgado.
     */
    const SEGUNDOS_DE_VIDA = 90;

    /**
     * Segundos que se espera a que el hijo anuncie el puerto en el que quedó escuchando. Pasado ese tope, `levantar()` lo baja y devuelve null (y el test FALLA
     * con el mensaje de `FallaSiElServidorSmtpNoArranca`, en vez de quedarse esperando para siempre).
     */
    const SEGUNDOS_PARA_ARRANCAR = 10.0;

    /**
     * Puerto en el que escucha.
     *
     * @var int
     */
    public $puerto = 0;

    /**
     * El proceso del servidor.
     *
     * @var resource|null
     */
    private $proceso;

    /**
     * Las puntas de las tuberías del proceso (la entrada y el error; la salida va a un archivo, ver `$archivo`).
     *
     * @var array<int, resource>
     */
    private $tuberias = [];

    /**
     * El archivo donde el hijo escribe su salida estándar (la línea `PUERTO <n>`). Se borra al bajar el servidor.
     *
     * @var string|null
     */
    private $archivo;

    /**
     * Lanza el servidor y espera —con un tope— a que diga en qué puerto quedó escuchando.
     *
     * 🔴 El hijo anuncia el puerto en un ARCHIVO y el padre lo sondea (`SEGUNDOS_PARA_ARRANCAR`), en vez de leerlo con un `fgets` de una tubería: en Windows una
     * tubería de `proc_open` no admite lectura con timeout (`stream_set_timeout` se ignora y `stream_set_blocking` devuelve false; medido el 7/10/2026 con
     * PHP 7.4.33), así que un hijo que no llegara a imprimir —un cuelgue de arranque antes de que corra el PHP— dejaba a todo el proceso de PHPUnit esperando
     * para siempre. Con el archivo, un hijo mudo se baja a los 10 s y un hijo que murió se detecta al instante (`proc_get_status`).
     *
     * @param string      $modo   MODO_RECHAZA | MODO_ACEPTA | MODO_TEMPORAL | MODO_PARCIAL.
     * @param string|null $script Ruta del script del servidor (solo para los tests de este mismo servidor; por defecto, `servidor_smtp_fake.php`).
     * @param float|null  $espera Segundos de espera al anuncio del puerto (por defecto, `SEGUNDOS_PARA_ARRANCAR`).
     *
     * @return self|null El servidor listo, o null si en este entorno no se puede lanzar un proceso o el hijo no anunció su puerto a tiempo.
     */
    public static function levantar(string $modo, ?string $script = null, ?float $espera = null): ?self
    {
        if (! function_exists('proc_open') || ! is_string(PHP_BINARY) || PHP_BINARY === '') {
            return null;
        }

        $script = $script !== null ? $script : __DIR__ . DIRECTORY_SEPARATOR . 'servidor_smtp_fake.php';
        $espera = $espera !== null ? $espera : self::SEGUNDOS_PARA_ARRANCAR;

        // Donde el hijo escribe lo que anuncia. En Windows no se puede borrar mientras el hijo lo tiene abierto: se borra en `bajar()`.
        $archivo = tempnam(sys_get_temp_dir(), 'smtp_fake_');

        if ($archivo === false) {
            return null;
        }

        $comando = [
            PHP_BINARY,
            $script,
            $modo,
            (string) self::SEGUNDOS_DE_VIDA,
        ];

        $tuberias = [];
        $proceso  = @proc_open($comando, [0 => ['pipe', 'r'], 1 => ['file', $archivo, 'w'], 2 => ['pipe', 'w']], $tuberias);

        if (! is_resource($proceso)) {
            @unlink($archivo);

            return null;
        }

        $servidor           = new self();
        $servidor->proceso  = $proceso;
        $servidor->tuberias = $tuberias;
        $servidor->archivo  = $archivo;

        // Lo primero que hace el servidor es decir el puerto. Se sondea el archivo hasta el tope; si el proceso murió antes, no se espera el resto del plazo.
        $limite = microtime(true) + $espera;

        while (microtime(true) < $limite) {
            // Se exige el salto de línea del final: así nunca se lee un puerto a medio escribir (el hijo manda la línea entera de una sola vez).
            if (preg_match('/^PUERTO (\d+)\R/m', (string) @file_get_contents($archivo), $coincidencia) === 1) {
                $servidor->puerto = (int) $coincidencia[1];

                return $servidor;
            }

            $estado = proc_get_status($proceso);

            if (! $estado['running']) {
                break;
            }

            usleep(50000);
        }

        $servidor->bajar();

        return null;
    }

    /**
     * Le devuelve al contenedor el transporte `smtp` DE VERDAD, pero solo hacia esta máquina.
     *
     * `Tests\TestCase` lo reemplaza por uno en memoria en cada test (capa 4 del freno de internet). Con ese reemplazo
     * un servidor nunca rechaza nada. Acá se instala un `MailManagerSoloLoopback`: crea el transporte real con todo
     * lo que lee del config, y se niega a conectarse a cualquier host que no sea 127.0.0.1/localhost/::1.
     *
     * Hay que llamarlo ANTES de que el test use un mailer por primera vez (el administrador guarda cada mailer ya armado),
     * y no hace falta deshacerlo: cada test arma su propia aplicación.
     *
     * @return void
     */
    public static function restaurar_el_transporte_smtp(): void
    {
        app()->instance('mail.manager', new MailManagerSoloLoopback(app()));

        // La fachada guarda la instancia que resolvió la primera vez: sin esto, `Mail::to()` seguiría usando el
        // administrador viejo (el del transporte en memoria).
        Mail::clearResolvedInstance('mail.manager');
    }

    /**
     * Apunta un mailer de Laravel a este servidor: SMTP a 127.0.0.1, sin cifrado y con usuario y clave
     * (los que un mailer real exige para intentar un envío). Incluye `restaurar_el_transporte_smtp()`.
     *
     * Hay que llamarlo ANTES de que el test use el mailer por primera vez: el administrador de mails
     * guarda cada mailer ya armado.
     *
     * @param string $nombre Nombre del mailer en `config/mail.php`.
     *
     * @return void
     */
    public function apuntar_el_mailer(string $nombre): void
    {
        self::restaurar_el_transporte_smtp();

        config(['mail.mailers.' . $nombre => [
            'transport'  => 'smtp',
            'host'       => '127.0.0.1',
            'port'       => $this->puerto,
            'encryption' => null,
            'username'   => 'casilla@ejemplo.test',
            'password'   => 'clave-de-prueba',
            'timeout'    => 10,
            'auth_mode'  => null,
        ]]);
    }

    /**
     * Apaga el servidor. Se puede llamar más de una vez.
     *
     * @return void
     */
    public function bajar(): void
    {
        foreach ($this->tuberias as $tuberia) {
            if (is_resource($tuberia)) {
                fclose($tuberia);
            }
        }

        $this->tuberias = [];

        if (is_resource($this->proceso)) {
            proc_terminate($this->proceso);
            proc_close($this->proceso);
        }

        $this->proceso = null;

        // Recién ahora, con el hijo muerto, se puede borrar el archivo donde anunciaba el puerto.
        if ($this->archivo !== null) {
            @unlink($this->archivo);

            $this->archivo = null;
        }
    }

    /**
     * Si el test se olvidó de bajarlo, se baja solo al soltarlo.
     */
    public function __destruct()
    {
        $this->bajar();
    }
}
