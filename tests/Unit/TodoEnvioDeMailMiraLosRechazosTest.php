<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cierra la CLASE del defecto, no solo sus ejemplos: ningún envío de mail de `app/` puede quedar sin mirar si el servidor de correo
 * rechazó la casilla, ni sin una decisión escrita de por qué no.
 *
 * El defecto (misión mails-rechazados-por-smtp-empresa, 7/10/2026; ya resuelto en admin-api el 6/10 con el mismo detector): con un 550 en el
 * RCPT TO, SwiftMailer (Laravel 8) NO tira excepción; `send()` vuelve normal y la casilla queda en `failures()` del mailer. Quien solo atrapa
 * la excepción da por enviado un mail que nunca salió. Se arregló en los puntos que afirmaban un éxito (`RechazosDeCorreoHelper`), pero sin
 * esto el próximo punto de envío nace con el mismo hueco, y nadie se entera hasta que un usuario ve "enviado" sobre un mail que nunca llegó.
 *
 * Qué hace: recorre `app/**\/*.php`, saca los comentarios (con `token_get_all`: que un docblock diga `Mail::to()->send()` no cuenta como un
 * envío, ni como un chequeo), y para cada archivo cuenta
 *   - las SENTENCIAS que mandan un mail EN EL ACTO por la fachada `Mail` (`Mail::...->send(`, `Mail::raw(`...),
 *   - las que lo ENCOLAN (`Mail::...->queue(`, `->later(`), y
 *   - las llamadas a `RechazosDeCorreoHelper::del_ultimo_envio()` o `::fallar_si_hubo_rechazos()` ("chequeos").
 * Falla, listando el archivo y las cuentas, si hay más envíos que chequeos MÁS las decisiones escritas de este archivo (ver `ENVIOS_SIN_CHEQUEO`), o
 * más encolados que los decididos (`ENCOLADOS`).
 *
 * 🔴 Por qué hay LISTAS DE DECISIONES y no un "alcanza con anotar": hay envíos que no pueden mirar el rechazo en el punto de llamada (un mail
 * encolado se manda en un worker) o que no afirman nada (un aviso interno a Lucas sin marca de "enviado", un comando cuya única llamada está
 * comentada). Para esos la decisión es "no se agrega un chequeo; el rechazo queda anotado por el listener de `MessageSent`". Esa decisión
 * se escribe ACÁ, con su motivo, y un envío o un `->queue()` nuevo que no la tenga FALLA: obliga a decidir en vez de dejarlo pasar.
 * Y una decisión que ya no corresponde a ningún envío también FALLA (`test_las_decisiones_escritas_siguen_vigentes`): una excepción sin dueño
 * es un hueco que el próximo envío del archivo hereda sin que nadie lo decida.
 *
 * 🔴 **Límites, dichos a propósito.** Es una heurística: CUENTA, no EMPAREJA cada envío con su chequeo (un archivo con un envío sin chequeo y
 * otro chequeo de más pasaría), y no ve un mail armado por fuera de la fachada `Mail`. Alcanza para el modo de falla real, que es agregar un envío
 * nuevo y olvidarse del chequeo. Y justamente porque una heurística ciega pasa en verde para siempre, hay un test que exige que el detector VEA los
 * envíos que ya existen: ver `test_el_detector_ve_los_envios_que_ya_existen_en_app()`.
 *
 * Lo que este detector cierra y el de admin-api no (allá contaba CERO en estas formas): el envío PARTIDO en dos sentencias
 * (`$p = Mail::to($a); $p->send($b);` o `$m = Mail::mailer('x'); $m->to($a)->send($b);`) se DENUNCIA (`test_ningun_envio_de_mail_de_app_esta_partido_en_dos_sentencias`),
 * `Mail::onQueue()`, `queueOn()` y `laterOn()` cuentan como encolados, `mail::`/`MAIL::` se ven (los nombres de clase de PHP no distinguen mayúsculas) y un test
 * fija que ningún Mailable implemente `ShouldQueue` (si lo hiciera, `->send()` encolaría y el chequeo leería una lista vacía).
 *
 * 🔴 Puntos ciegos que QUEDAN (el detector cuenta CERO en estas formas; hoy no hay ninguna en `app/`): un alias de la fachada (`use ...\Mail as Correo`),
 * `$this->mailer->send(...)` o `app('mailer')->send(...)` (fuera de la fachada), llamar a `Mailable::send($mailer)` / `->queue()` directo sin pasar por la fachada, y un
 * `PendingMail` que sale de la sentencia sin enviarse (`return Mail::to($a);`, `yield`, un array, `enviar(Mail::to($a), $b);`, `fn($a) => Mail::to($a)`, `??=`, un ternario):
 * su `->send()` queda en OTRA sentencia, que este detector no empareja (solo denuncia el guardado en una variable con `=`, `$p = Mail::to($a);`). Tampoco ve `Mail::driver()` ni
 * `Mail::getSwiftMailer()` (otros nombres para mandar), y un `->failures()` DENTRO de una closure del encadenado cuenta como "lee los rechazos".
 * Al revés, hay falsos positivos que el mensaje del test resuelve con "usá la forma encadenada": guardar un mailer para leer `failures()` después
 * (`$m = Mail::mailer($n); $m->failures();`) se denuncia como envío partido aunque no mande nada.
 * Quien escriba un envío así tiene que mirar los rechazos igual, aunque este test no se lo exija. Y un envío por un mailer CON NOMBRE (`Mail::mailer('x')->send()`) lo
 * cuenta como uno más pero el listener `AnotarMailRechazadoPorElServidor` solo mira el mailer por defecto: ese envío tiene que llamar a
 * `RechazosDeCorreoHelper::del_ultimo_envio('x')` con SU nombre.
 *
 * Las `Notification` con canal `mail` tampoco pasan por la fachada `Mail` (`MailChannel` manda por su cuenta): las cubre un segundo test
 * (`test_las_notificaciones_con_canal_mail_estan_decididas`) que fija las que existen hoy (2) y falla ante una tercera.
 */
class TodoEnvioDeMailMiraLosRechazosTest extends TestCase
{
    /**
     * Los métodos de la fachada `Mail` (o de lo que devuelve `Mail::to()`) que mandan un mail EN EL ACTO.
     * En minúsculas: PHP no distingue mayúsculas en los nombres de método.
     */
    const METODOS_DE_ENVIO = ['send', 'sendnow', 'raw', 'html', 'plain'];

    /**
     * Los que lo ENCOLAN: el mail se manda en un worker y el rechazo no se puede leer en el punto de llamada.
     */
    const METODOS_DE_ENCOLADO = ['queue', 'later', 'onqueue', 'queueon', 'lateron'];

    /**
     * Las llamadas a `RechazosDeCorreoHelper` que cuentan como "mirar los rechazos".
     */
    const METODOS_DEL_HELPER = ['del_ultimo_envio', 'fallar_si_hubo_rechazos'];

    /**
     * ENVÍOS INMEDIATOS que NO miran los rechazos, por decisión escrita (criterio: un punto se arregla si AFIRMA un éxito que el rechazo desmiente;
     * si no afirma nada, alcanza con que el rechazo quede anotado por el listener de `MessageSent`).
     *
     * Ruta relativa dentro de `app/` => cuántos envíos del archivo están decididos.
     *
     * @var array<string, int>
     */
    const ENVIOS_SIN_CHEQUEO = [
        // El aviso a Lucas de un error del SPA: un mail interno sin ninguna marca de "enviado" ni respuesta que desmentir. El error ya se guardó en
        // la base antes de avisar; con un 550 solo se pierde el aviso, y el listener de `MessageSent` lo deja en el log.
        'Http/Controllers/CommonLaravel/ErrorController.php' => 1,

        // `enviar_notificaciones()` es CÓDIGO MUERTO: su única llamada está comentada (`// $this->enviar_notificaciones();`). No afirma nada
        // porque nunca corre. Si alguien descomenta la llamada, el rechazo igual queda en el log.
        'Console/Commands/check_stock_movements.php' => 1,
    ];

    /**
     * MAILS ENCOLADOS (`->queue()`), por decisión escrita: el rechazo ocurre en el worker y NO se puede leer en el punto de llamada.
     * No se vuelven sincrónicos (bloquearían el request al SMTP del comercio) ni tiran en el worker: con `QUEUE_CONNECTION=sync` la excepción volvería
     * al request de una venta que ya hizo commit (el motivo que decide) y, además, un job que tira pasa por el reporte de errores a GitHub. El listener de
     * `MessageSent` deja el rechazo en el log.
     *
     * Ruta relativa dentro de `app/` => cuántos `->queue()` del archivo están decididos.
     *
     * @var array<string, int>
     */
    const ENCOLADOS = [
        'Http/Controllers/Helpers/ComercioCityMailHelper.php' => 2,
    ];

    /**
     * Las `Notification` con canal `mail` que existen hoy y NO miran los rechazos: cambiar `MailChannel` para que tire rompería los flujos
     * de pedidos que las llaman sin `try/catch`. El listener de `MessageSent` deja el rechazo en el log. Una notificación NUEVA con canal `mail` no
     * está en esta lista y hace fallar el test: hay que decidir qué hacer con su rechazo.
     *
     * Nombre del archivo dentro de `app/Notifications/`.
     *
     * @var array<int, string>
     */
    const NOTIFICACIONES_CON_CANAL_MAIL = ['MessageSend.php', 'RespuestaDelComercioPorMail.php'];

    /**
     * Cuántos envíos, encolados y chequeos hay en un código PHP.
     *
     * Un "envío" es una SENTENCIA que arranca con `Mail::` y, antes de terminar (el `;` a la profundidad original), llama a un método de envío
     * (`->send(`, `::raw(`...); un "encolado", una que llama a `->queue(` o `->later(`. Se cuenta una vez por sentencia aunque encadene varias cosas, y
     * una sentencia con una closure adentro (`Mail::raw('x', function () {...});`) sigue siendo una sola. Un "chequeo" es una llamada
     * `RechazosDeCorreoHelper::del_ultimo_envio(` o `::fallar_si_hubo_rechazos(`, con o sin namespace delante.
     *
     * 🔴 Los comentarios y los espacios se descartan ANTES de contar: un docblock que explica el defecto con un ejemplo `Mail::to($x)->send($y)`
     * no es un envío, y uno que dice "llamá a RechazosDeCorreoHelper::fallar_si_hubo_rechazos()" no es un chequeo.
     *
     * @param string $codigo Contenido de un archivo PHP.
     *
     * Y un cuarto contador, los "partidos": una sentencia `$x = Mail::to|cc|bcc|mailer(...)` que guarda en una variable lo que va a mandar el mail, sin
     * terminar en un envío ni en un encolado (ni en una lectura de `failures()`). El `->send()` de esa variable queda en OTRA sentencia, que este detector no ve:
     * el rechazo pasaría sin chequeo. Se denuncia en `test_ningun_envio_de_mail_de_app_esta_partido_en_dos_sentencias`.
     *
     * @return array{envios: int, encolados: int, chequeos: int, partidos: int}
     */
    private function analizar(string $codigo): array
    {
        // Los tokens "de verdad": sin comentarios, sin docblocks y sin espacios.
        $tokens = [];

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        $envios    = 0;
        $encolados = 0;
        $chequeos  = 0;
        $partidos  = 0;
        $total     = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            // `Mail::` (con o sin el namespace de la fachada delante): arranca una sentencia candidata.
            if ($this->es_el_nombre($this->texto($tokens, $i), 'Mail') && $this->texto($tokens, $i + 1) === '::') {
                $tipo = $this->tipo_de_la_sentencia($tokens, $i);

                if ($tipo === 'envio') {
                    $envios++;
                } elseif ($tipo === 'encolado') {
                    $encolados++;
                } elseif ($this->es_un_envio_partido($tokens, $i)) {
                    $partidos++;
                }

                continue;
            }

            // `RechazosDeCorreoHelper::del_ultimo_envio(` o `::fallar_si_hubo_rechazos(`.
            if ($this->es_el_nombre($this->texto($tokens, $i), 'RechazosDeCorreoHelper')
                && $this->texto($tokens, $i + 1) === '::'
                && in_array($this->texto($tokens, $i + 2), self::METODOS_DEL_HELPER, true)
                && $this->texto($tokens, $i + 3) === '(') {
                $chequeos++;
            }
        }

        return ['envios' => $envios, 'encolados' => $encolados, 'chequeos' => $chequeos, 'partidos' => $partidos];
    }

    /**
     * Si la sentencia que arranca en `Mail::` es una ASIGNACIÓN que arma lo que va a mandar el mail (`$p = Mail::to($a);`, `$m = Mail::mailer('x');`) y no termina en
     * un envío ni en un encolado (eso ya lo habría dicho `tipo_de_la_sentencia()`).
     *
     * No cuenta la lectura de los rechazos (`$r = Mail::mailer($m)->failures();`: es lo que hace `RechazosDeCorreoHelper`), ni `$x = Mail::fake();`.
     *
     * @param array<int, array|string> $tokens
     * @param int                      $desde  Posición del token `Mail`.
     *
     * @return bool
     */
    private function es_un_envio_partido(array $tokens, int $desde): bool
    {
        // Lo que viene antes de `Mail`: en PHP 7.4 un nombre con namespace son varios tokens (`\` `Illuminate` `\` … `Mail`), se retrocede por ellos hasta el `=`.
        $anterior = $desde - 1;

        while ($anterior >= 0
            && ($this->texto($tokens, $anterior) === '\\'
                || (is_array($tokens[$anterior]) && $tokens[$anterior][0] === T_STRING && $this->texto($tokens, $anterior + 1) === '\\'))) {
            $anterior--;
        }

        if ($anterior < 0 || $this->texto($tokens, $anterior) !== '=') {
            return false;
        }

        // El primer método que se llama sobre la fachada: los que arman un `PendingMail` o un mailer.
        if (! in_array(strtolower($this->texto($tokens, $desde + 2)), ['to', 'cc', 'bcc', 'mailer'], true) || $this->texto($tokens, $desde + 3) !== '(') {
            return false;
        }

        // Y que la sentencia no termine leyendo los rechazos (`->failures(`): eso no manda nada.
        $profundidad = 0;
        $total       = count($tokens);

        for ($j = $desde; $j < $total; $j++) {
            $texto = $this->texto($tokens, $j);

            if ($texto === '(' || $texto === '[' || $texto === '{') {
                $profundidad++;
            } elseif ($texto === ')' || $texto === ']' || $texto === '}') {
                $profundidad--;

                if ($profundidad < 0) {
                    break;
                }
            } elseif ($texto === ';' && $profundidad === 0) {
                break;
            }

            if ($texto === '->' && strtolower($this->texto($tokens, $j + 1)) === 'failures') {
                return false;
            }
        }

        return true;
    }

    /**
     * El texto de un token (los tokens sueltos como `(` o `;` son strings; el resto, arrays).
     *
     * @param array<int, array|string> $tokens
     * @param int                      $posicion
     *
     * @return string Vacío si la posición no existe.
     */
    private function texto(array $tokens, int $posicion): string
    {
        if (! isset($tokens[$posicion])) {
            return '';
        }

        return is_array($tokens[$posicion]) ? (string) $tokens[$posicion][1] : (string) $tokens[$posicion];
    }

    /**
     * Si un nombre de clase es `$nombre`, ya venga solo (`Mail`) o con namespace (`\Illuminate\Support\Facades\Mail`).
     *
     * Se mira el TEXTO y no el tipo de token porque PHP 7.4 parte un nombre con namespace en varios tokens y PHP 8 lo entrega en uno solo; en los
     * dos casos el último tramo es un token con ese texto o uno que termina en `\Mail`. `Mailer` o `MailFake` no coinciden.
     *
     * @param string $texto
     * @param string $nombre
     *
     * @return bool
     */
    private function es_el_nombre(string $texto, string $nombre): bool
    {
        // Sin distinguir mayúsculas: PHP no las distingue en un nombre de clase (`mail::to()->send()` y `MAIL::to()->send()` mandan el mismo mail).
        if (strcasecmp($texto, $nombre) === 0) {
            return true;
        }

        $sufijo = '\\' . $nombre;

        return strlen($texto) > strlen($sufijo) && strcasecmp(substr($texto, -strlen($sufijo)), $sufijo) === 0;
    }

    /**
     * Qué hace la sentencia que arranca en `Mail::`: `'envio'` si manda en el acto, `'encolado'` si encola, `null` si no hace ninguna de las dos.
     *
     * Avanza hasta el `;` que cierra la sentencia (a la misma profundidad de paréntesis, corchetes y llaves con la que arrancó: una closure con `;`
     * adentro no la corta) o hasta salir del bloque que la contiene, y devuelve lo PRIMERO que encuentra.
     *
     * @param array<int, array|string> $tokens
     * @param int                      $desde  Posición del token `Mail`.
     *
     * @return string|null
     */
    private function tipo_de_la_sentencia(array $tokens, int $desde): ?string
    {
        $profundidad = 0;
        $total       = count($tokens);

        for ($j = $desde; $j < $total; $j++) {
            $texto = $this->texto($tokens, $j);

            if ($texto === '(' || $texto === '[' || $texto === '{' || $texto === '${') {
                $profundidad++;
            } elseif ($texto === ')' || $texto === ']' || $texto === '}') {
                $profundidad--;

                // Se salió del bloque que contenía la sentencia sin haber visto un envío.
                if ($profundidad < 0) {
                    return null;
                }
            } elseif ($texto === ';' && $profundidad === 0) {
                return null;
            }

            // `->send(`, `::raw(`, `->queue(`...: el método de envío o de encolado.
            if ($texto === '->' || $texto === '::') {
                $metodo = strtolower($this->texto($tokens, $j + 1));

                if ($this->texto($tokens, $j + 2) === '(') {
                    if (in_array($metodo, self::METODOS_DE_ENVIO, true)) {
                        return 'envio';
                    }

                    if (in_array($metodo, self::METODOS_DE_ENCOLADO, true)) {
                        return 'encolado';
                    }
                }
            }
        }

        return null;
    }

    /**
     * Todos los `.php` de `app/`, con la ruta absoluta.
     *
     * @return array<int, string>
     */
    private function archivos_de_app(): array
    {
        $raiz     = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $archivos = [];

        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && substr($archivo->getFilename(), -4) === '.php') {
                $archivos[] = $archivo->getPathname();
            }
        }

        sort($archivos);

        return $archivos;
    }

    /**
     * La ruta de un archivo de `app/` relativa a `app/` y con `/`.
     *
     * @param string $ruta Ruta absoluta.
     *
     * @return string
     */
    private function relativa(string $ruta): string
    {
        $raiz = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', substr($ruta, strlen($raiz)));
    }

    /**
     * Cuenta envíos, encolados y chequeos de un archivo de `app/` por su ruta relativa (con `/`).
     *
     * @param string $relativa Por ejemplo `Http/Controllers/CommonLaravel/PasswordResetController.php`.
     *
     * @return array{envios: int, encolados: int, chequeos: int}
     */
    private function analizar_archivo_de_app(string $relativa): array
    {
        $ruta = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);

        $this->assertFileExists($ruta);

        return $this->analizar((string) file_get_contents($ruta));
    }

    /**
     * Las cuentas de TODOS los archivos de `app/` que mencionan `Mail::` (los demás no pueden mandar nada por la fachada).
     *
     * @return array<string, array{envios: int, encolados: int, chequeos: int}> Ruta relativa => cuentas.
     */
    private function cuentas_de_app(): array
    {
        $cuentas = [];

        foreach ($this->archivos_de_app() as $ruta) {
            $codigo = (string) file_get_contents($ruta);

            // Un archivo sin ningún `Mail::` no puede mandar nada por la fachada (ni con namespace delante: el `Mail::` sigue estando): se ahorra el
            // `token_get_all()`, que en los archivos grandes es lo que más tarda.
            if (! $this->menciona_la_fachada_mail($codigo)) {
                continue;
            }

            $cuentas[$this->relativa($ruta)] = $this->analizar($codigo);
        }

        return $cuentas;
    }

    /**
     * ¿El código menciona la fachada `Mail::`, con o sin namespace delante y escrita como sea?
     *
     * 🔴 Sin distinguir mayúsculas: los nombres de clase de PHP no las distinguen, así que `\MAIL::to($a)->send($b)` es el mismo envío. Este filtro corre ANTES de
     * `analizar()` (que sí ve esa forma) y un archivo que no lo pasa se saltea entero: con `/Mail\s*::/` a secas, un envío escrito `\mail::` quedaba invisible
     * para el escaneo real de `app/` aunque el detector lo contara bien en la unidad.
     *
     * @param string $codigo Contenido de un archivo PHP.
     *
     * @return bool
     */
    private function menciona_la_fachada_mail(string $codigo): bool
    {
        return preg_match('/Mail\s*::/i', $codigo) === 1;
    }

    /**
     * El filtro de archivos del escaneo real de `app/`: ve la fachada escrita de cualquier forma y no se confunde con nombres que solo se le parecen.
     *
     * @dataProvider casos_del_filtro_de_archivos
     *
     * @param string $codigo   Un trozo de PHP.
     * @param bool   $esperado Si el archivo tiene que pasar al análisis.
     *
     * @return void
     */
    public function test_el_filtro_de_archivos_ve_la_fachada_escrita_de_cualquier_forma(string $codigo, bool $esperado): void
    {
        $this->assertSame($esperado, $this->menciona_la_fachada_mail('<?php ' . $codigo));
    }

    /**
     * Código de ejemplo y si el filtro tiene que dejarlo pasar al análisis (el filtro solo ahorra trabajo: dejar pasar de más es inocuo, perder un envío no).
     *
     * @return array<string, array<int, string|bool>>
     */
    public static function casos_del_filtro_de_archivos(): array
    {
        return [
            'la fachada de siempre'                       => ['Mail::to($a)->send($b);', true],
            'con el namespace completo'                   => ['\\Illuminate\\Support\\Facades\\Mail::to($a)->send($b);', true],
            'en mayúsculas'                               => ['\\MAIL::to($a)->send($b);', true],
            'en minúsculas'                               => ['\\mail::to($a)->send($b);', true],
            'con un espacio antes de los dos puntos'      => ['Mail ::to($a)->send($b);', true],
            'un archivo que no la menciona'               => ['$x = 1; Cache::get("k");', false],
            'Mailer:: no es la fachada'                   => ['Mailer::to($a)->send($b);', false],
            'Mailable::class tampoco'                     => ['$c = Mailable::class;', false],
            'el use de la fachada sin llamarla'           => ['use Illuminate\\Support\\Facades\\Mail;', false],
        ];
    }

    /**
     * 🔴 El test que importa: ningún archivo de `app/` manda más mails de los que mira rechazos (más las decisiones escritas).
     *
     * Si falla, un envío nuevo (o uno que se le borró el chequeo) puede estar dando por enviado un mail que el servidor rechazó. La salida lista
     * cada archivo con sus cuentas.
     *
     * @return void
     */
    public function test_todo_envio_de_mail_de_app_mira_los_rechazos_del_servidor(): void
    {
        $sin_chequeo = [];

        foreach ($this->cuentas_de_app() as $relativa => $cuenta) {
            $decididos = isset(self::ENVIOS_SIN_CHEQUEO[$relativa]) ? self::ENVIOS_SIN_CHEQUEO[$relativa] : 0;

            if ($cuenta['envios'] > $cuenta['chequeos'] + $decididos) {
                $sin_chequeo[] = $relativa . ': ' . $cuenta['envios'] . ' envío(s) de mail, ' . $cuenta['chequeos'] . ' chequeo(s) de rechazos'
                    . ($decididos > 0 ? ' y ' . $decididos . ' decidido(s) sin chequeo' : '');
            }
        }

        $this->assertSame(
            [],
            $sin_chequeo,
            'Hay envíos de mail que no miran si el servidor SMTP rechazó la casilla. Con un 550 en el RCPT TO, SwiftMailer NO tira '
            . 'excepción: send() vuelve normal y el mail no salió. Justo después de cada Mail::...->send() llamá a '
            . 'RechazosDeCorreoHelper::fallar_si_hubo_rechazos() (dentro de un try/catch que responda o anote el fallo) o a '
            . 'RechazosDeCorreoHelper::del_ultimo_envio() (si armás tu propia respuesta con la lista), y no afirmes que se mandó hasta pasarlo. Si el punto no '
            . 'afirma nada y de verdad no corresponde un chequeo, agregalo a ENVIOS_SIN_CHEQUEO de este test con el motivo escrito. Archivos: ' . implode(' | ', $sin_chequeo)
        );
    }

    /**
     * 🔴 Ningún `->queue()` de `app/` queda sin una decisión escrita: un mail encolado se manda en un worker y su rechazo no se puede leer en el punto
     * de llamada, así que el chequeo común no sirve; hay que decidir qué hacer (y dejarlo en `ENCOLADOS`).
     *
     * @return void
     */
    public function test_todo_mail_encolado_de_app_tiene_una_decision_escrita(): void
    {
        $sin_decision = [];

        foreach ($this->cuentas_de_app() as $relativa => $cuenta) {
            $decididos = isset(self::ENCOLADOS[$relativa]) ? self::ENCOLADOS[$relativa] : 0;

            if ($cuenta['encolados'] > $decididos) {
                $sin_decision[] = $relativa . ': ' . $cuenta['encolados'] . ' mail(s) encolado(s), ' . $decididos . ' decidido(s)';
            }
        }

        $this->assertSame(
            [],
            $sin_decision,
            'Hay mails encolados (Mail::...->queue()) sin una decisión escrita. El rechazo del servidor ocurre en el worker y no se puede leer acá: '
            . 'decidí qué hacer (el listener de MessageSent lo deja en el log) y agregá el archivo a ENCOLADOS de este test con el motivo. Archivos: '
            . implode(' | ', $sin_decision)
        );
    }

    /**
     * 🔴 Las decisiones escritas SIGUEN VIGENTES: cada una corresponde a un envío (o a un `->queue()`) que existe. Una excepción que ya no tiene dueño
     * es un hueco: el próximo envío que alguien agregue en ese archivo la heredaría sin que nadie lo decida.
     *
     * @return void
     */
    public function test_las_decisiones_escritas_siguen_vigentes(): void
    {
        $vencidas = [];

        foreach (self::ENVIOS_SIN_CHEQUEO as $relativa => $decididos) {
            $cuenta = $this->analizar_archivo_de_app($relativa);

            if ($cuenta['envios'] < $decididos) {
                $vencidas[] = 'ENVIOS_SIN_CHEQUEO ' . $relativa . ': ' . $decididos . ' decidido(s), ' . $cuenta['envios'] . ' envío(s) en el archivo';
            }
        }

        foreach (self::ENCOLADOS as $relativa => $decididos) {
            $cuenta = $this->analizar_archivo_de_app($relativa);

            if ($cuenta['encolados'] < $decididos) {
                $vencidas[] = 'ENCOLADOS ' . $relativa . ': ' . $decididos . ' decidido(s), ' . $cuenta['encolados'] . ' encolado(s) en el archivo';
            }
        }

        $this->assertSame([], $vencidas, 'Hay decisiones escritas que ya no corresponden a ningún envío: sacalas de la lista. ' . implode(' | ', $vencidas));
    }

    /**
     * 🔴 El control del detector: VE los envíos que ya existen. Sin esto, un detector roto (que contara cero envíos en todos lados) haría pasar los
     * tests de arriba en verde para siempre, y la clase volvería a abrirse sin que nada lo avise.
     *
     * Son pisos, no cuentas exactas: agregar un envío nuevo CON su chequeo no tiene que romper este test. Cada archivo con la cantidad de envíos
     * inmediatos y de encolados que tiene HOY.
     *
     * @dataProvider envios_que_ya_existen
     *
     * @param string $relativa  Ruta relativa dentro de `app/`.
     * @param int    $envios    Cuántos envíos inmediatos tiene que ver como mínimo.
     * @param int    $encolados Cuántos encolados tiene que ver como mínimo.
     *
     * @return void
     */
    public function test_el_detector_ve_los_envios_que_ya_existen_en_app(string $relativa, int $envios, int $encolados): void
    {
        $cuenta = $this->analizar_archivo_de_app($relativa);

        $this->assertGreaterThanOrEqual($envios, $cuenta['envios'], 'El detector no ve los envíos de ' . $relativa . ': estaría ciego.');
        $this->assertGreaterThanOrEqual($encolados, $cuenta['encolados'], 'El detector no ve los encolados de ' . $relativa . ': estaría ciego.');
    }

    /**
     * Los archivos de `app/` que mandan mails hoy, con cuántos envíos inmediatos y cuántos encolados.
     *
     * @return array<string, array<int, string|int>>
     */
    public static function envios_que_ya_existen(): array
    {
        return [
            'PasswordResetController (el código de recuperación)'   => ['Http/Controllers/CommonLaravel/PasswordResetController.php', 1, 0],
            'OnlineConfigurationController (probar el SMTP)'        => ['Http/Controllers/OnlineConfigurationController.php', 1, 0],
            'ProcessSendAdviseMail (el aviso de ingreso de stock)'  => ['Jobs/ProcessSendAdviseMail.php', 1, 0],
            'ClientePotencialController (el mail a un prospecto)'   => ['Http/Controllers/ClientePotencialController.php', 1, 0],
            'ErrorController (el aviso de un error del SPA)'        => ['Http/Controllers/CommonLaravel/ErrorController.php', 1, 0],
            'CheckCurrentAcountsIntegrity (la auditoría)'           => ['Console/Commands/CheckCurrentAcountsIntegrity.php', 1, 0],
            'check_stocks (el aviso de stocks mal)'                 => ['Console/Commands/check_stocks.php', 1, 0],
            'check_stock_movements (el envío muerto)'               => ['Console/Commands/check_stock_movements.php', 1, 0],
            'ComercioCityMailHelper (los dos mails encolados)'      => ['Http/Controllers/Helpers/ComercioCityMailHelper.php', 0, 2],
        ];
    }

    /**
     * El detector sobre código de ejemplo: cuenta lo que tiene que contar y NADA de lo que no.
     *
     * @dataProvider casos_del_detector
     *
     * @param string $codigo             Un trozo de PHP.
     * @param int    $envios_esperados
     * @param int    $encolados_esperados
     * @param int    $chequeos_esperados
     * @param int    $partidos_esperados  Los envíos partidos en dos sentencias (0 si el caso no los menciona).
     *
     * @return void
     */
    public function test_el_detector_cuenta_bien_sobre_codigo_de_ejemplo(string $codigo, int $envios_esperados, int $encolados_esperados, int $chequeos_esperados, int $partidos_esperados = 0): void
    {
        $this->assertSame(
            ['envios' => $envios_esperados, 'encolados' => $encolados_esperados, 'chequeos' => $chequeos_esperados, 'partidos' => $partidos_esperados],
            $this->analizar('<?php ' . $codigo)
        );
    }

    /**
     * Casos del detector: lo que cuenta como envío, encolado o chequeo, y lo que se parece y no lo es. Cada caso es
     * `[código, envíos, encolados, chequeos]`.
     *
     * @return array<string, array<int, string|int>>
     */
    public static function casos_del_detector(): array
    {
        return [
            'un envío sin chequeo'                                  => ['Mail::to($a)->send($b);', 1, 0, 0],
            'un envío con su chequeo'                               => ['Mail::to($a)->send($b); RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 1, 0, 1],
            'el chequeo de lectura (del_ultimo_envio)'              => ['Mail::mailer("x")->to($a)->send($b); $r = RechazosDeCorreoHelper::del_ultimo_envio("x");', 1, 0, 1],
            'con el namespace completo de la fachada y del helper'  => ['\\Illuminate\\Support\\Facades\\Mail::to($a)->send($b); \\App\\Mail\\Helpers\\RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 1, 0, 1],
            'una sentencia en varias líneas cuenta una vez'         => ["Mail::mailer('x')\n    ->to(\$a)\n    ->send(Foo::armar(\n        \$x,\n        \$y\n    ));", 1, 0, 0],
            'Mail::raw con una closure adentro'                     => ['Mail::raw("hola", function ($m) { $m->to($a); $m->subject("x"); });', 1, 0, 0],
            'Mail::send directo'                                    => ['Mail::send("vista", [], function ($m) {});', 1, 0, 0],
            'Mail::html y Mail::plain mandan en el acto'            => ['Mail::html("x", $f); Mail::plain("vista", [], $f);', 2, 0, 0],
            'sendNow manda en el acto'                              => ['Mail::to($a)->sendNow($b);', 1, 0, 0],
            'dos envíos, un chequeo'                                => ['Mail::to($a)->send($b); Mail::to($c)->send($d); RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 2, 0, 1],
            'un queue() es un encolado y NO un envío'               => ['Mail::to($a)->queue($b);', 0, 1, 0],
            'un later() es un encolado'                             => ['Mail::to($a)->later(60, $b);', 0, 1, 0],
            'un envío y un encolado, cada uno a su cuenta'          => ['Mail::to($a)->send($b); Mail::to($c)->queue($d);', 1, 1, 0],
            'un envío en un comentario no cuenta'                   => ['// Mail::to($a)->send($b);', 0, 0, 0],
            'un queue en un comentario no cuenta'                   => ['/* Mail::to($a)->queue($b); */', 0, 0, 0],
            'un envío en un docblock no cuenta'                     => ["/**\n * Mail::to(\$a)->send(\$b);\n * RechazosDeCorreoHelper::fallar_si_hubo_rechazos();\n */\nfunction f() {}", 0, 0, 0],
            'un envío en un string no cuenta'                       => ['$t = "Mail::to(\$a)->send(\$b)";', 0, 0, 0],
            'Mail::fake no es un envío'                             => ['Mail::fake(); Mail::assertSent(Foo::class);', 0, 0, 0],
            'Mail::failures no es un envío'                         => ['$f = Mail::failures();', 0, 0, 0],
            'Mail::mailer(...)->failures no es un envío'            => ['$f = Mail::mailer(null)->failures();', 0, 0, 0],
            'otra clase que se llama parecido no cuenta'            => ['Mailer::to($a)->send($b); ElMail::to($a)->send($b);', 0, 0, 0],
            'el use de la fachada no es un envío'                   => ['use Illuminate\\Support\\Facades\\Mail;', 0, 0, 0],
            'el chequeo nombrado en un comentario no cuenta'        => ['Mail::to($a)->send($b); // RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 1, 0, 0],
            'otro método del helper no cuenta como chequeo'         => ['Mail::to($a)->send($b); RechazosDeCorreoHelper::otra_cosa();', 1, 0, 0],
            'ProcessSendAdviseMail::dispatch no es un envío'        => ['ProcessSendAdviseMail::dispatch($a, $b);', 0, 0, 0],
            'onQueue es un encolado'                                => ['Mail::onQueue("q", $b);', 0, 1, 0],
            'queueOn es un encolado'                                => ['Mail::queueOn("q", $b);', 0, 1, 0],
            'laterOn es un encolado'                                => ['Mail::laterOn("q", 10, $b);', 0, 1, 0],
            'el nombre de la fachada no distingue mayúsculas'       => ['mail::to($a)->send($b); MAIL::to($a)->send($b);', 2, 0, 0],
            'un envío PARTIDO en dos sentencias se detecta'         => ['$p = Mail::to($a); $p->send($b);', 0, 0, 0, 1],
            'un mailer guardado en una variable se detecta'         => ['$m = Mail::mailer("x"); $m->to($a)->send($b);', 0, 0, 0, 1],
            'con cc y con el namespace de la fachada también'       => ['$p = \\Illuminate\\Support\\Facades\\Mail::to($a)->cc($b); $p->send($c);', 0, 0, 0, 1],
            'leer los rechazos de un mailer NO es un envío partido' => ['$r = Mail::mailer($m)->failures();', 0, 0, 0, 0],
            'Mail::fake guardado en una variable tampoco'           => ['$f = Mail::fake();', 0, 0, 0, 0],
            'una asignación que ya termina en send no es partida'   => ['$ok = Mail::to($a)->send($b);', 1, 0, 0, 0],
        ];
    }

    /**
     * Y la lógica de la comparación, probada sobre un trozo que no depende de lo que haya en el repo: más envíos que chequeos se denuncia; con su
     * chequeo no.
     *
     * @return void
     */
    public function test_un_codigo_con_mas_envios_que_chequeos_se_denuncia(): void
    {
        $sin_chequeo = $this->analizar('<?php Mail::to($a)->send($b);');
        $con_chequeo = $this->analizar('<?php Mail::to($a)->send($b); RechazosDeCorreoHelper::fallar_si_hubo_rechazos();');

        $this->assertGreaterThan($sin_chequeo['chequeos'], $sin_chequeo['envios'], 'Un envío sin chequeo es lo que se denuncia.');
        $this->assertLessThanOrEqual($con_chequeo['chequeos'], $con_chequeo['envios'], 'Un envío con su chequeo no se denuncia.');
    }

    /**
     * 🔴 Las `Notification` con canal `mail` no pasan por la fachada `Mail` (`MailChannel` manda por su cuenta) y el detector de arriba no las ve.
     * Hoy hay 2 y ninguna mira los rechazos. Una tercera hace fallar este test: hay que decidir qué hacer con su rechazo.
     *
     * Se detecta por `toMail(`: una notificación que puede mandar un mail define ese método. Los comentarios se descartan antes.
     *
     * @return void
     */
    public function test_las_notificaciones_con_canal_mail_estan_decididas(): void
    {
        $raiz         = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Notifications';
        $con_to_mail  = [];

        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterador as $archivo) {
            if (! $archivo->isFile() || substr($archivo->getFilename(), -4) !== '.php') {
                continue;
            }

            if ($this->define_to_mail((string) file_get_contents($archivo->getPathname()))) {
                $con_to_mail[] = $archivo->getFilename();
            }
        }

        sort($con_to_mail);

        $esperadas = self::NOTIFICACIONES_CON_CANAL_MAIL;
        sort($esperadas);

        $this->assertSame(
            $esperadas,
            $con_to_mail,
            'Cambió el conjunto de notificaciones que pueden mandar un mail (toMail). Una notificación por el canal mail no pasa por la fachada Mail y su '
            . 'rechazo no se mira: decidí qué hacer (el listener de MessageSent lo deja en el log) y actualizá NOTIFICACIONES_CON_CANAL_MAIL de este test.'
        );
    }

    /**
     * Si un código PHP define un método `toMail` (sin contar comentarios).
     *
     * @param string $codigo
     *
     * @return bool
     */
    private function define_to_mail(string $codigo): bool
    {
        $tokens = [];

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION && strtolower($this->texto($tokens, $i + 1)) === 'tomail') {
                return true;
            }
        }

        return false;
    }

    /**
     * 🔴 Ningún envío de `app/` está PARTIDO en dos sentencias (`$p = Mail::to($a); $p->send($b);`): el `send` de la variable queda en otra sentencia que el detector
     * no ve, y el rechazo pasaría sin chequeo. Es la forma más natural de escribirlo cuando alguien agrega un `cc`. Usá la forma encadenada
     * (`Mail::to($a)->cc($b)->send($c);`) y mirá los rechazos justo después.
     *
     * @return void
     */
    public function test_ningun_envio_de_mail_de_app_esta_partido_en_dos_sentencias(): void
    {
        $partidos = [];

        foreach ($this->cuentas_de_app() as $relativa => $cuenta) {
            if ($cuenta['partidos'] > 0) {
                $partidos[] = $relativa . ': ' . $cuenta['partidos'] . ' sentencia(s) que guardan en una variable lo que va a mandar el mail';
            }
        }

        $this->assertSame(
            [],
            $partidos,
            'Hay envíos de mail partidos en dos sentencias ($p = Mail::to($a); $p->send($b);): este detector no ve el send y el rechazo del servidor SMTP pasaría sin chequeo. '
            . 'Usá la forma encadenada y llamá a RechazosDeCorreoHelper justo después. Archivos: ' . implode(' | ', $partidos)
        );
    }

    /**
     * 🔴 Ningún Mailable de `app/Mail` implementa `ShouldQueue`. Es la premisa que sostiene todo este arreglo: `Illuminate\Mail\Mailer::sendMailable()` hace
     * `$mailable instanceof ShouldQueue ? ->queue() : ->send()`, así que si alguien le agrega `implements ShouldQueue` a un Mailable, el `->send()` pasa a ENCOLAR,
     * `fallar_si_hubo_rechazos()` lee una lista vacía y el guardián sigue contando "1 envío, 1 chequeo". Con `QUEUE_CONNECTION=sync` (los tests) el envío además
     * sale en línea y ni los tests contra el servidor real lo notan: solo en producción, con cola `database`, el chequeo se vuelve un no-op silencioso.
     *
     * Hoy 6 de los 7 Mailables IMPORTAN `ShouldQueue` (un `use` muerto) y ninguno lo implementa. Si alguno tiene que encolarse, el archivo que lo manda tiene que quedar en `ENCOLADOS`.
     *
     * @return void
     */
    public function test_ningun_mailable_de_app_se_encola_solo(): void
    {
        $raiz        = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Mail';
        $se_encolan  = [];
        $mailables   = 0;

        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterador as $archivo) {
            if (! $archivo->isFile() || substr($archivo->getFilename(), -4) !== '.php') {
                continue;
            }

            // El código sin comentarios: un docblock que nombre `implements ShouldQueue` no cuenta.
            $sin_comentarios = '';

            foreach (token_get_all((string) file_get_contents($archivo->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $sin_comentarios .= is_array($token) ? $token[1] : $token;
            }

            $clase = $this->clasificar_una_clase_de_mail($sin_comentarios);

            if ($clase['mailable']) {
                $mailables++;
            }

            if ($clase['se_encola']) {
                $se_encolan[] = $archivo->getFilename();
            }
        }

        $this->assertGreaterThanOrEqual(6, $mailables, 'El detector no ve los Mailables de app/Mail: estaría ciego.');
        $this->assertSame(
            [],
            $se_encolan,
            'Un Mailable que implementa ShouldQueue encola en cada ->send(): fallar_si_hubo_rechazos() leería una lista vacía y el chequeo no serviría. '
            . 'Si tiene que encolarse, sacale el implements y mandalo con ->queue() explícito desde un archivo que esté en ENCOLADOS. Mailables: ' . implode(' | ', $se_encolan)
        );
    }

    /**
     * Qué es una clase de `app/Mail` según su código SIN comentarios: si es un Mailable (`extends Mailable`) y si se encola sola (`implements ShouldQueue`).
     *
     * 🔴 Sin distinguir mayúsculas: las palabras clave de PHP y los nombres de clase no las distinguen, así que `CLASS A EXTENDS Mailable IMPLEMENTS shouldqueue`
     * es el mismo defecto que la forma de siempre (sin `/i` pasaba sin ser visto).
     *
     * Límites dichos: no ve un alias del `use` (`use ...\ShouldQueue as Q; ... implements Q`), una interfaz propia que extienda `ShouldQueue`, una clase base que lo
     * implemente (`extends ColaMailable`), ni un Mailable que viva fuera de `app/Mail` (hoy no hay ninguno). Quien lo haga tiene que decidir el archivo que lo manda en
     * `ENCOLADOS`, aunque este test no se lo exija.
     *
     * @param string $sin_comentarios El código de la clase, sin comentarios.
     *
     * @return array{mailable: bool, se_encola: bool}
     */
    private function clasificar_una_clase_de_mail(string $sin_comentarios): array
    {
        return [
            // `extends Mailable` o `extends \Illuminate\Mail\Mailable` (en un string entre comillas simples, `\\\\` es UNA barra invertida literal para la expresión regular).
            'mailable'  => preg_match('/\bclass\s+\w+[^{;]*\bextends\s+\\\\?(?:\w+\\\\)*Mailable\b/i', $sin_comentarios) === 1,
            'se_encola' => preg_match('/\bclass\s+\w+[^{;]*\bimplements\b[^{;]*\bShouldQueue\b/i', $sin_comentarios) === 1,
        ];
    }

    /**
     * El clasificador de clases de `app/Mail` sobre código de ejemplo: ve las formas que PHP acepta y no se confunde con las que solo se le parecen.
     *
     * @dataProvider casos_del_clasificador_de_mailables
     *
     * @param string $codigo     Un trozo de PHP.
     * @param bool   $mailable   Si tiene que verla como un Mailable.
     * @param bool   $se_encola  Si tiene que verla como una clase que se encola sola.
     *
     * @return void
     */
    public function test_el_clasificador_de_mailables_ve_las_formas_de_php(string $codigo, bool $mailable, bool $se_encola): void
    {
        $this->assertSame(['mailable' => $mailable, 'se_encola' => $se_encola], $this->clasificar_una_clase_de_mail('<?php ' . $codigo));
    }

    /**
     * Casos del clasificador: `[código, es un Mailable, se encola sola]`.
     *
     * @return array<string, array<int, string|bool>>
     */
    public static function casos_del_clasificador_de_mailables(): array
    {
        return [
            'un Mailable común'                              => ['class A extends Mailable {}', true, false],
            'el use muerto de ShouldQueue no encola'         => ["use Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass A extends Mailable {}", true, false],
            'implementa ShouldQueue'                         => ['class A extends Mailable implements ShouldQueue {}', true, true],
            'final class'                                    => ['final class A extends Mailable implements ShouldQueue {}', true, true],
            'con los namespaces completos'                   => ['abstract class A extends \\Illuminate\\Mail\\Mailable implements \\Illuminate\\Contracts\\Queue\\ShouldQueue {}', true, true],
            'implements en varias líneas'                    => ["class A extends Mailable implements Foo,\n    ShouldQueue {}", true, true],
            'palabras clave en mayúsculas'                   => ['CLASS A EXTENDS Mailable IMPLEMENTS ShouldQueue {}', true, true],
            'la interfaz en minúsculas'                      => ['class A extends Mailable implements shouldqueue {}', true, true],
            'el texto "implements ShouldQueue" en un string' => ['class A extends Mailable { function f() { return "implements ShouldQueue"; } }', true, false],
            'una clase que no es Mailable pero se encola'    => ['class Payload implements ShouldQueue {}', false, true],
            'una clase cualquiera'                           => ['class Foo { function f() {} }', false, false],
        ];
    }

    /**
     * 🔴 El chequeo que parece correcto y no se ejecuta nunca: `method_exists(Mail::getFacadeRoot(), 'failures')` da SIEMPRE `false`. La raíz de la
     * fachada es el `MailManager`, que no tiene `failures()` (le llega por `__call`, que `method_exists` no ve): lo tenía un envío de admin-api y el
     * rechazo pasaba como un envío exitoso. Hoy no hay ninguno en `app/`; este test avisa si reaparece.
     *
     * @return void
     */
    public function test_ningun_chequeo_de_rechazos_depende_de_method_exists_sobre_la_fachada(): void
    {
        $con_la_trampa = [];

        foreach ($this->archivos_de_app() as $ruta) {
            $codigo = (string) file_get_contents($ruta);

            if (stripos($codigo, 'getFacadeRoot') === false) {
                continue;
            }

            $sin_comentarios = '';

            foreach (token_get_all($codigo) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $sin_comentarios .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/method_exists\s*\([^;]*getFacadeRoot/i', $sin_comentarios) === 1) {
                $con_la_trampa[] = $this->relativa($ruta);
            }
        }

        $this->assertSame(
            [],
            $con_la_trampa,
            'method_exists() sobre la raíz de la fachada Mail da siempre false (failures() llega por __call): ese chequeo no corre nunca. Usá '
            . 'RechazosDeCorreoHelper. Archivos: ' . implode(' | ', $con_la_trampa)
        );
    }
}
