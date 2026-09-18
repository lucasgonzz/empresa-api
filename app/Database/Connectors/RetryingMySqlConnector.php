<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\MySqlConnector;
use PDOException;

/**
 * Reintenta la conexión a MySQL, con espera creciente, cuando falla por saturación TRANSITORIA de
 * la cuenta compartida de Hostinger — nunca por un error real (credenciales, host inexistente,
 * base que no existe, etc).
 *
 * Por qué existe (15/9/2026, investigando el incidente recurrente de saturación de conexiones,
 * ver incidente-too-many-connections-de-toda-la-cuenta-shared en el repo de conocimiento): en el
 * shared hosting, un pico de `[1040] Too many connections` o `[2002] Operation not permitted`
 * suele durar una fracción de segundo — el mismo request, medio segundo después, conecta sin
 * problema. Hoy ese primer fallo se le muestra al usuario tal cual (una pantalla de "no pudimos
 * conectarnos"), cuando reintentar solo, sin que el usuario se entere, alcanza la mayoría de las
 * veces.
 *
 * Se engancha reemplazando el conector de MySQL que usa Laravel para TODA la aplicación (bind de
 * `db.connector.mysql` en AppServiceProvider::register()) — es la única forma de cubrir cualquier
 * conexión, sin tocar cada controlador o job uno por uno.
 *
 * 🔴 Relación con el reintento que YA trae Laravel (Illuminate\Database\Connectors\Connector::
 * createConnection(), vía el trait DetectsLostConnections): esa capa YA reintenta UNA vez, sin
 * ninguna espera, para una lista fija de mensajes que incluye textual
 * `SQLSTATE[HY000] [2002] Connection refused` — pero NO `Too many connections` ni
 * `Operation not permitted`. Esta clase queda POR ARRIBA de esa capa (envuelve
 * MySqlConnector::connect(), que es quien llama a createConnection() puertas adentro): si el
 * reintento inmediato de Laravel también falla, o si el error es uno de los dos que Laravel no
 * reconoce, acá se agregan más intentos CON espera creciente. No es una redundancia: es una red más
 * ancha sobre la misma idea.
 *
 * No hereda nada del limite de conexiones por segundo del shared en sí — sólo decide qué hacer
 * cuando la conexión YA falló por ese motivo. La mitigación de fondo (bajar cuántas conexiones
 * nuevas se generan por segundo) es el orquestador de crons y, a más largo plazo, migrar clientes
 * del shared al VPS.
 */
class RetryingMySqlConnector extends MySqlConnector
{
    /**
     * Intentos totales (el primero + los reintentos). 3 de sobra para una colisión puntual de una
     * fracción de segundo; una saturación sostenida de varios minutos no la arregla ningún número
     * razonable de reintentos acá, y no vale la pena hacer esperar al usuario más que esto.
     *
     * @var int
     */
    protected $max_intentos;

    /**
     * Base de la espera entre intentos, en microsegundos. Crece con el número de intento
     * (espera_base * intento) más un jitter aleatorio de hasta 100ms, para que si varios requests
     * chocan a la vez no reintenten todos exactamente al mismo instante y se choquen de nuevo.
     *
     * @var int
     */
    protected $espera_base_microsegundos;

    /**
     * Fragmentos de mensaje que identifican una saturación TRANSITORIA de la cuenta compartida —
     * nunca un error real. Los tres, medidos en los incidentes de saturación documentados en el
     * repo de conocimiento (incidente-too-many-connections-de-toda-la-cuenta-shared,
     * limite-de-conexiones-por-segundo-del-shared):
     *   - "Too many connections": el pool de conexiones del servidor MySQL compartido con otros
     *     inquilinos de Hostinger se llenó del todo.
     *   - "Operation not permitted": el throttle propio de Hostinger (~18-20 conexiones nuevas por
     *     segundo de TODA la cuenta) rechazó esta conexión puntual.
     *   - "Connection refused": mismo mecanismo que el anterior, mensaje distinto. Laravel YA
     *     reintenta este UNA vez sin espera (ver el comentario de la clase); acá se le suma espera
     *     y más intentos.
     *
     * @var string[]
     */
    const FRAGMENTOS_TRANSITORIOS = [
        'Too many connections',
        'Operation not permitted',
        'Connection refused',
    ];

    /**
     * @param  int  $max_intentos               Default 3: ver la propiedad de arriba.
     * @param  int  $espera_base_microsegundos  Default 200000 (200ms): ver la propiedad de arriba.
     */
    public function __construct($max_intentos = 3, $espera_base_microsegundos = 200000)
    {
        $this->max_intentos = max(1, (int) $max_intentos);
        $this->espera_base_microsegundos = max(0, (int) $espera_base_microsegundos);
    }

    /**
     * {@inheritdoc}
     *
     * Envuelve MySqlConnector::connect() (DSN + PDO + set names/timezone/isolation/modes, todo
     * junto) en un reintento: si algo de esa secuencia falla por saturación transitoria, se
     * descarta la conexión a medio configurar y se arranca de cero, no se intenta "continuar" desde
     * donde falló.
     */
    public function connect(array $config)
    {
        $intento = 1;

        while (true) {
            try {
                return $this->intentar_conexion($config);
            } catch (PDOException $e) {
                if ($intento >= $this->max_intentos || !static::es_saturacion_transitoria($e)) {
                    throw $e;
                }

                $this->dormir($intento);
                $intento++;
            }
        }
    }

    /**
     * El intento de conexión real. Separado de connect() para que los tests puedan reemplazarlo
     * por un doble que simula fallos y éxitos sin necesitar una base de MySQL de verdad — es el
     * único método que un test double tiene que sobrescribir.
     *
     * @param  array  $config
     * @return \PDO
     */
    protected function intentar_conexion(array $config)
    {
        return parent::connect($config);
    }

    /**
     * Espera antes del próximo intento. Separado en su propio método por el mismo motivo que
     * intentar_conexion(): un test no puede (ni debe) dormir de verdad para probar la lógica de
     * reintento.
     *
     * @param  int  $intento  El intento que acaba de fallar (1-based).
     * @return void
     */
    protected function dormir($intento)
    {
        usleep($this->espera_base_microsegundos * $intento + random_int(0, 100000));
    }

    /**
     * ¿La excepción es una de las tres formas conocidas de saturación transitoria de la cuenta
     * compartida? static y no de instancia: no depende de configuración, es una clasificación pura
     * del mensaje de MySQL/PDO.
     *
     * @param  \PDOException  $e
     * @return bool
     */
    public static function es_saturacion_transitoria(PDOException $e)
    {
        $mensaje = $e->getMessage();

        foreach (self::FRAGMENTOS_TRANSITORIOS as $fragmento) {
            if (strpos($mensaje, $fragmento) !== false) {
                return true;
            }
        }

        return false;
    }
}
