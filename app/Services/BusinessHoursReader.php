<?php

namespace App\Services;

use App\Models\BusinessHoursConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lectura de los horarios del negocio que empuja `admin-api`
 * (`PUT /api/admin-sync/business-hours`, emisor: `ClientScheduleSyncService`).
 *
 * Contesta las dos preguntas que el agente necesita —"¿hasta qué hora abren hoy?" y
 * "¿abren el día X?"— sin que el consumidor toque JSON crudo.
 *
 * 🔴 CUATRO REGLAS QUE GOBIERNAN ESTE LECTOR:
 *
 *  1. **No reimplementa la resolución.** `semana` llega YA RESUELTA del admin, con la
 *     precedencia de "Todos los días" y el override del día puntual aplicadas allá. Acá se
 *     lee tal cual. Si las dos puntas resolvieran el mismo invariante por su cuenta, el día
 *     que se agregue un caso (feriados, medio día) quedarían dos criterios y uno se olvidaría.
 *
 *  2. **`dias_crudos` NUNCA gobierna una respuesta.** Viaja solo como comodidad de lectura
 *     ("los sábados hasta las 13"). La fuente de verdad es `semana`. `dias_crudos()` lo
 *     devuelve verbatim y ningún otro método de esta clase lo mira.
 *
 *  3. **"No hay dato" NO es "cerrado", y viaja en dos niveles.** Un comercio sin nada cargado
 *     llega con `configurado: false` y `semana: []`; y adentro de la semana, un día sin
 *     configurar llega con `abierto: null` (no `false`). En los dos casos el agente NO puede
 *     afirmar que el negocio está cerrado: no lo sabe. Confundirlos le diría a un comprador
 *     que el comercio está cerrado un martes a las 10 de la mañana. Por eso `abierto_hoy()`
 *     devuelve `?bool` y nunca colapsa el "no se sabe" a `false`.
 *
 *  4. **El campo que gobierna es `abierto`, no `estado`.** El emisor lo declara como "el campo
 *     más obvio de consumir del otro lado" y lo hace honesto por sí solo. Consecuencias:
 *     (a) si mañana el admin agrega un `estado` nuevo (`'feriado'`, `'medio_dia'`), este lector
 *     no rompe ni lo trata como cerrado; (b) si `abierto` viene ausente o con cualquier valor
 *     que no sea `true` ni `false`, se lee como `null`, JAMÁS como `false`.
 *
 * Compatibilidad hacia atrás: las claves que no se conocen se ignoran, no rompen nada, y las
 * subclaves nuevas de un día sobreviven porque `semana` se guarda verbatim.
 */
final class BusinessHoursReader
{
    /** El día tiene horario cargado y el comercio abre en algún momento. */
    const ESTADO_CON_HORARIO = 'con_horario';

    /** El día está cargado explícitamente como cerrado (día propio, cero rangos). */
    const ESTADO_CERRADO = 'cerrado';

    /** El día no tiene horario cargado. 🔴 No es "cerrado": es "no se sabe". */
    const ESTADO_SIN_CONFIGURAR = 'sin_configurar';

    /** Motivo de `cierre_de_hoy_detallado()`: no hay dato del día de hoy. */
    const MOTIVO_SIN_DATO = 'sin_dato';

    /** Motivo de `cierre_de_hoy_detallado()`: hoy el comercio está cerrado todo el día. */
    const MOTIVO_CERRADO_HOY = 'cerrado_hoy';

    /** Motivo de `cierre_de_hoy_detallado()`: hoy abre, pero no llegó la hora de cierre. */
    const MOTIVO_SIN_HORA_DE_CIERRE = 'sin_hora_de_cierre';

    /**
     * Claves de día por índice de `Carbon::dayOfWeek` (0 = domingo).
     *
     * Copia literal de `ClientScheduleDay::DAY_KEYS_BY_DOW` del admin. Se usa SOLO como
     * respaldo cuando el día que llegó no trae `dia`; nunca para decidir nada.
     */
    const DIAS_POR_DOW = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    /**
     * Etiquetas de presentación por índice de `Carbon::dayOfWeek` (0 = domingo).
     *
     * Respaldo de `dia_label` cuando no vino. Van con tilde: el archivo es UTF-8 sin BOM y el
     * texto termina en el system prompt de un agente que le escribe a un comprador.
     */
    const LABELS_POR_DOW = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    /** Cantidad de días que devuelve `semana()`. Siempre siete: es una semana. */
    const DIAS_DE_LA_SEMANA = 7;

    /**
     * @var BusinessHoursConfig|null Fila espejo del owner, o null si no hay dato.
     */
    private $config;

    /**
     * @var string|null Timezone ya resuelto y validado. Se calcula una sola vez.
     */
    private $timezone_resuelto = null;

    /**
     * @var array<int, array>|null Días de `semana` indexados por `dia_semana`. Memoizado.
     */
    private $indice_dias = null;

    /**
     * Privado a propósito: se entra por `for_user()` o `for_instance()`, que son las dos
     * formas válidas de resolver a quién pertenece el horario.
     *
     * @param BusinessHoursConfig|null $config Fila espejo, o null si no hay.
     */
    private function __construct(?BusinessHoursConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Lector del horario de un usuario puntual.
     *
     * 🔴 Devuelve SIEMPRE una instancia, nunca null: el consumidor no tiene que chequear null
     * y después chequear "hay dato". Sin fila, la instancia contesta `hay_dato() === false` y
     * todo lo demás en su forma "sin dato".
     *
     * @param int $user_id Dueño del horario.
     *
     * @return self
     */
    public static function for_user(int $user_id): self
    {
        if ($user_id <= 0) {
            return new self(null);
        }

        /* El try/catch NO está para tapar un problema: está porque este lector se llama desde
         * `WhatsappBotAiService::build_system_prompt()`, y ahí una excepción no se ve como un
         * error sino como "el agente dejó de contestar" (`generate_response()` se traga el
         * throwable y devuelve ''). Una instancia con la migración sin correr tiene que
         * degradar a "no hay horario" con un warning en el log, no dejar mudo al bot. */
        try {
            $config = BusinessHoursConfig::where('user_id', $user_id)->first();
        } catch (\Throwable $e) {
            Log::warning('BusinessHoursReader: no se pudo leer business_hours_configs.', [
                'user_id' => $user_id,
                'error'   => $e->getMessage(),
            ]);

            $config = null;
        }

        return new self($config);
    }

    /**
     * Lector del horario de la instancia (el owner, que es quien recibe el push del admin).
     *
     * @return self
     */
    public static function for_instance(): self
    {
        try {
            $owner = BusinessHoursConfig::owner_de_la_instancia();
        } catch (\Throwable $e) {
            Log::warning('BusinessHoursReader: no se pudo resolver el owner de la instancia.', [
                'error' => $e->getMessage(),
            ]);

            $owner = null;
        }

        if ($owner === null) {
            return new self(null);
        }

        return self::for_user((int) $owner->id);
    }

    /**
     * Lector para un consumidor que tiene un `user_id` propio (el bot de WhatsApp) pero cuyo
     * horario es, en realidad, el de la INSTANCIA: el push del admin le llega al owner.
     *
     * Primero prueba con el user pedido y, si ese no tiene horario, cae al owner.
     *
     * ⚠️ El chequeo de que el owner NO sea el mismo user no es una micro-optimizacion cosmetica:
     * esto corre en `build_system_prompt()`, o sea en CADA mensaje entrante de WhatsApp. Sin el
     * chequeo, el caso normal (el bot es del owner y todavia no hay horario cargado, que es el
     * de toda la flota hasta que salga esto) paga la MISMA query dos veces por mensaje, y si la
     * tabla todavia no existe escribe dos warnings identicos en vez de uno.
     *
     * @param int $user_id User del consumidor.
     *
     * @return self
     */
    public static function for_user_o_instancia(int $user_id): self
    {
        $lector = self::for_user($user_id);

        if ($lector->hay_dato()) {
            return $lector;
        }

        try {
            $owner = BusinessHoursConfig::owner_de_la_instancia();
        } catch (\Throwable $e) {
            Log::warning('BusinessHoursReader: no se pudo resolver el owner de la instancia.', [
                'error' => $e->getMessage(),
            ]);

            return $lector;
        }

        if ($owner === null || (int) $owner->id === $user_id) {
            return $lector;
        }

        return self::for_user((int) $owner->id);
    }

    /**
     * ¿Hay horario cargado para este owner?
     *
     * 🔴 `false` significa "no se sabe nada del horario", NO "el negocio está cerrado".
     *
     * @return bool
     */
    public function hay_dato(): bool
    {
        if ($this->config === null) {
            return false;
        }

        if (! (bool) $this->config->configurado) {
            return false;
        }

        $semana = $this->config->semana;

        return is_array($semana) && count($semana) > 0;
    }

    /**
     * Timezone del comercio, siempre válido y nunca vacío.
     *
     * El "hoy" se calcula SIEMPRE con el timezone del payload, no con el del servidor: un
     * "hoy" mal calculado devuelve el horario del día equivocado, que es el error más caro que
     * puede cometer este lector. Si el timezone que llegó está vacío o es inválido, se cae a
     * `config('app.timezone')` y, en última instancia, a `'UTC'`, dejando el aviso en el log.
     *
     * @return string
     */
    public function timezone(): string
    {
        if ($this->timezone_resuelto !== null) {
            return $this->timezone_resuelto;
        }

        $del_payload = $this->config === null ? '' : trim((string) $this->config->timezone);

        if ($del_payload !== '' && $this->timezone_valida($del_payload)) {
            $this->timezone_resuelto = $del_payload;

            return $this->timezone_resuelto;
        }

        if ($del_payload !== '') {
            Log::warning('BusinessHoursReader: timezone inválido en el horario del negocio.', [
                'timezone' => $del_payload,
            ]);
        }

        $fallback = trim((string) config('app.timezone'));

        if ($fallback === '' || ! $this->timezone_valida($fallback)) {
            $fallback = 'UTC';
        }

        $this->timezone_resuelto = $fallback;

        return $this->timezone_resuelto;
    }

    /**
     * Momento en que el admin armó este horario, tal como llegó (ISO8601 crudo).
     *
     * @return string|null Null si no hay dato.
     */
    public function actualizado_en(): ?string
    {
        if ($this->config === null) {
            return null;
        }

        $valor = trim((string) $this->config->actualizado_en);

        return $valor === '' ? null : $valor;
    }

    /**
     * El día de la semana pedido, en la forma normalizada del lector.
     *
     * Forma:
     *
     *   [
     *     'dia_semana' => 2,
     *     'dia'        => 'martes',
     *     'dia_label'  => 'Martes',
     *     'abierto'    => true|false|null,   // null = NO SE SABE. Nunca se colapsa a false.
     *     'estado'     => 'con_horario'|'cerrado'|'sin_configurar'|<lo que haya venido>,
     *     'origen'     => 'dia_propio'|'todos_los_dias'|'sin_configurar'|<lo que haya venido>,
     *     'rangos'     => [ ['desde' => '08:00', 'hasta' => '13:00'], ... ],
     *     'cierre'     => '21:00'|null,
     *     'hay_dato'   => true|false,
     *   ]
     *
     * @param int $dia_semana Índice de `Carbon::dayOfWeek`: 0 = domingo, 6 = sábado.
     *
     * @return array
     */
    public function dia(int $dia_semana): array
    {
        $dow = (int) $dia_semana;

        if ($dow < 0 || $dow > 6) {
            // Fuera de rango no es un día: se contesta "sin dato" y no se inventa una etiqueta.
            return $this->dia_sin_dato($dow, '', '');
        }

        $indice = $this->indice_de_dias();

        if (! isset($indice[$dow])) {
            return $this->dia_sin_dato($dow, self::DIAS_POR_DOW[$dow], self::LABELS_POR_DOW[$dow]);
        }

        $crudo = $indice[$dow];

        return [
            'dia_semana' => $dow,
            'dia'        => $this->texto_o($crudo, 'dia', self::DIAS_POR_DOW[$dow]),
            'dia_label'  => $this->texto_o($crudo, 'dia_label', self::LABELS_POR_DOW[$dow]),
            'abierto'    => $this->abierto_de($crudo),
            'estado'     => $this->texto_o($crudo, 'estado', self::ESTADO_SIN_CONFIGURAR),
            'origen'     => $this->texto_o($crudo, 'origen', self::ESTADO_SIN_CONFIGURAR),
            'rangos'     => isset($crudo['rangos']) && is_array($crudo['rangos'])
                ? array_values($crudo['rangos'])
                : [],
            'cierre'     => $this->cierre_de($crudo),
            'hay_dato'   => true,
        ];
    }

    /**
     * El día de la semana que le corresponde a una fecha, leída en el timezone del comercio.
     *
     * @param Carbon $fecha Fecha/hora a ubicar.
     *
     * @return array Misma forma que `dia()`.
     */
    public function dia_de_fecha(Carbon $fecha): array
    {
        try {
            $dow = (int) $fecha->copy()->setTimezone($this->timezone())->dayOfWeek;
        } catch (\Throwable $e) {
            /* `timezone()` ya devuelve un timezone validado, así que esto no debería pasar.
             * Está igual porque el precio de equivocarse acá es contestar el horario del día
             * equivocado, y prefiero el día del servidor con un warning antes que una
             * excepción que deje mudo al agente. */
            Log::warning('BusinessHoursReader: no se pudo ubicar la fecha en el timezone del negocio.', [
                'timezone' => $this->timezone(),
                'error'    => $e->getMessage(),
            ]);

            $dow = (int) $fecha->dayOfWeek;
        }

        return $this->dia($dow);
    }

    /**
     * El día de hoy, calculado en el timezone del comercio.
     *
     * @return array Misma forma que `dia()`.
     */
    public function hoy(): array
    {
        return $this->dia_de_fecha(Carbon::now($this->timezone()));
    }

    /**
     * ¿El negocio abre hoy en algún momento?
     *
     * 🔴 Devuelve `?bool` y no `bool` a propósito: `null` es "no se sabe" y es un desenlace
     * distinto de `false` ("hoy está cerrado"). Quien lo consuma tiene que distinguirlos; si
     * esto se "simplificara" a un booleano, un comercio que todavía no cargó el horario le
     * diría a un comprador que está cerrado un martes a las 10 de la mañana.
     *
     * ⚠️ Es a nivel DÍA ("abre en algún momento"), no a nivel instante: `true` no significa
     * que esté abierto ahora mismo.
     *
     * @return bool|null
     */
    public function abierto_hoy(): ?bool
    {
        $hoy = $this->hoy();

        return $hoy['abierto'];
    }

    /**
     * Hora de cierre de hoy en 'HH:MM', o null si hoy no cierra porque no abre, o si no se sabe.
     *
     * ⚠️ `null` es ambiguo por diseño (no hay hora que dar). Cuando hace falta saber POR QUÉ
     * no hay hora —para no decirle "cerrado" a alguien de quien no se sabe nada— se usa
     * `cierre_de_hoy_detallado()`.
     *
     * @return string|null
     */
    public function cierre_de_hoy(): ?string
    {
        $detalle = $this->cierre_de_hoy_detallado();

        return $detalle['cierre'];
    }

    /**
     * El cierre de hoy con su motivo. Cuatro desenlaces posibles:
     *
     *   | Situación                             | cierre  | abierto | motivo               |
     *   |---------------------------------------|---------|---------|----------------------|
     *   | Hoy abre y hay hora de cierre          | '21:00' | true    | null                 |
     *   | Hoy cerrado (día propio, cero rangos)  | null    | false   | 'cerrado_hoy'        |
     *   | No hay dato del día de hoy             | null    | null    | 'sin_dato'           |
     *   | Defensivo: abierto true, cierre null   | null    | true    | 'sin_hora_de_cierre' |
     *
     * @return array ['cierre' => ?string, 'abierto' => ?bool, 'estado' => string, 'motivo' => ?string]
     */
    public function cierre_de_hoy_detallado(): array
    {
        $hoy = $this->hoy();

        $cierre = $hoy['cierre'];
        $motivo = null;

        if (! $hoy['hay_dato'] || $hoy['abierto'] === null) {
            // 🔴 Sin dato NO es cerrado: son dos motivos distintos y el consumidor los usa distinto.
            $cierre = null;
            $motivo = self::MOTIVO_SIN_DATO;
        } elseif ($hoy['abierto'] === false) {
            $cierre = null;
            $motivo = self::MOTIVO_CERRADO_HOY;
        } elseif ($cierre === null) {
            // Abre pero no llegó la hora de cierre: ni "cierra a las " con un hueco, ni "cerrado".
            $motivo = self::MOTIVO_SIN_HORA_DE_CIERRE;
        }

        return [
            'cierre'  => $cierre,
            'abierto' => $hoy['abierto'],
            'estado'  => $hoy['estado'],
            'motivo'  => $motivo,
        ];
    }

    /**
     * Los siete días, de domingo (0) a sábado (6), siempre.
     *
     * Si el payload trajo menos de siete (contrato incompleto), los que faltan se completan
     * con la forma "sin dato": la semana que ve el consumidor siempre tiene siete renglones y
     * ninguno de los faltantes se presenta como cerrado.
     *
     * @return array<int, array> Misma forma que `dia()` en cada entrada.
     */
    public function semana(): array
    {
        $semana = [];

        for ($dow = 0; $dow < self::DIAS_DE_LA_SEMANA; $dow++) {
            $semana[$dow] = $this->dia($dow);
        }

        return $semana;
    }

    /**
     * Los días tal como están cargados en el admin, sin resolver precedencia.
     *
     * ⚠️ Comodidad de lectura, NO fuente de verdad: ningún otro método de esta clase lo mira
     * para decidir nada. Se devuelve verbatim.
     *
     * @return array
     */
    public function dias_crudos(): array
    {
        if ($this->config === null) {
            return [];
        }

        $crudos = $this->config->dias_crudos;

        return is_array($crudos) ? $crudos : [];
    }

    /**
     * Días de `semana` indexados por `dia_semana`, calculado una sola vez.
     *
     * Casos borde resueltos acá: `dia_semana` que llega como string se castea con `(int)`, un
     * `dia_semana` repetido lo gana el ÚLTIMO (determinista y avisado por log), y un ítem sin
     * `dia_semana` usable se descarta porque ubicarlo mal sería peor que no tenerlo.
     *
     * @return array<int, array>
     */
    private function indice_de_dias(): array
    {
        if ($this->indice_dias !== null) {
            return $this->indice_dias;
        }

        $indice = [];

        if (! $this->hay_dato()) {
            $this->indice_dias = $indice;

            return $this->indice_dias;
        }

        foreach ($this->config->semana as $item) {
            if (! is_array($item) || ! isset($item['dia_semana'])) {
                continue;
            }

            $dow = (int) $item['dia_semana'];

            if ($dow < 0 || $dow > 6) {
                continue;
            }

            if (isset($indice[$dow])) {
                Log::warning('BusinessHoursReader: dia_semana repetido en el horario del negocio; gana el último.', [
                    'dia_semana' => $dow,
                    'user_id'    => (int) $this->config->user_id,
                ]);
            }

            $indice[$dow] = $item;
        }

        $this->indice_dias = $indice;

        return $this->indice_dias;
    }

    /**
     * La forma "sin dato" de un día. Distinguible de "cerrado" en las dos claves que importan:
     * `abierto` es `null` (no `false`) y `hay_dato` es `false`.
     *
     * @param int    $dow   Índice del día.
     * @param string $dia   Clave del día, o '' si no se puede saber.
     * @param string $label Etiqueta del día, o '' si no se puede saber.
     *
     * @return array
     */
    private function dia_sin_dato(int $dow, string $dia, string $label): array
    {
        return [
            'dia_semana' => $dow,
            'dia'        => $dia,
            'dia_label'  => $label,
            'abierto'    => null,
            'estado'     => self::ESTADO_SIN_CONFIGURAR,
            'origen'     => self::ESTADO_SIN_CONFIGURAR,
            'rangos'     => [],
            'cierre'     => null,
            'hay_dato'   => false,
        ];
    }

    /**
     * Lee `abierto` del día crudo con los TRES valores del contrato.
     *
     * 🔴 ACÁ ES DONDE ALGUIEN LO "SIMPLIFICARÍA" A UN BOOLEANO. No se puede:
     * `(bool) $crudo['abierto']` convierte `null` (no se sabe) y ausente en `false` (cerrado),
     * que es exactamente el error que este contrato existe para evitar. Por eso la comparación
     * es estricta contra `true` y contra `false`, y cualquier otra cosa —ausente, `null`, un
     * string, un valor que el admin agregue mañana— se lee como `null`.
     *
     * El campo que gobierna es `abierto`, no `estado`: así un `estado` nuevo ('feriado',
     * 'medio_dia') no rompe este lector ni se trata como cerrado.
     *
     * @param array $crudo Día tal como vino en `semana`.
     *
     * @return bool|null
     */
    private function abierto_de(array $crudo)
    {
        if (! array_key_exists('abierto', $crudo)) {
            return null;
        }

        if ($crudo['abierto'] === true) {
            return true;
        }

        if ($crudo['abierto'] === false) {
            return false;
        }

        return null;
    }

    /**
     * Hora de cierre del día crudo, normalizada a string no vacío o null.
     *
     * @param array $crudo Día tal como vino en `semana`.
     *
     * @return string|null
     */
    private function cierre_de(array $crudo): ?string
    {
        if (! isset($crudo['cierre']) || ! is_scalar($crudo['cierre'])) {
            return null;
        }

        $cierre = trim((string) $crudo['cierre']);

        return $cierre === '' ? null : $cierre;
    }

    /**
     * Texto de una clave del día crudo, con respaldo cuando no vino o vino vacía.
     *
     * @param array  $crudo    Día tal como vino en `semana`.
     * @param string $clave    Clave a leer.
     * @param string $respaldo Valor a usar si no hay nada usable.
     *
     * @return string
     */
    private function texto_o(array $crudo, string $clave, string $respaldo): string
    {
        if (! isset($crudo[$clave]) || ! is_scalar($crudo[$clave])) {
            return $respaldo;
        }

        $valor = trim((string) $crudo[$clave]);

        return $valor === '' ? $respaldo : $valor;
    }

    /**
     * ¿El identificador de timezone es uno que PHP conoce?
     *
     * @param string $timezone Identificador a probar.
     *
     * @return bool
     */
    private function timezone_valida(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
