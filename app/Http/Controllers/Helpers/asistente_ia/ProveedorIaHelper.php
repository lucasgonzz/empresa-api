<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El único lugar del repo que sabe que el asistente tiene DOS proveedores de IA: Claude (Anthropic)
 * y DeepSeek (misión proveedores-ia-deepseek, 22/9/2026).
 *
 * Lo eligen por DUEÑO (`users.agente_proveedor`, mismo precedente que `agente_pensamiento` y
 * `agente_confianza`: la config es del comercio, no de la persona) y lo siguen los tres caminos del
 * asistente: el chat del dueño (AsistenteIaService, en el sistema y por WhatsApp), el bot de WhatsApp
 * a los clientes del negocio (WhatsappBotAiService) y el título de conversación
 * (InferirTituloConversacionIaJob). Ninguno de los tres sabe cuántos proveedores hay ni cómo se
 * llaman: le piden a este helper el modelo, el cliente HTTP, la URL y el payload, y registran el
 * gasto con el `proveedor` que este helper les devolvió, que es el que efectivamente contestó.
 *
 * 🔴 POR QUÉ NO HAY UN TRADUCTOR DE FORMATOS. DeepSeek publica un endpoint COMPATIBLE CON ANTHROPIC
 * (`https://api.deepseek.com/anthropic`, `POST /v1/messages`, header `x-api-key`; documentado en
 * api-docs.deepseek.com/guides/anthropic_api, leído el 22/9/2026) que acepta el mismo payload que
 * hoy se le manda a Anthropic —system, tools con input_schema, bloques tool_use / tool_result,
 * imágenes base64— y contesta con la misma forma (content[], stop_reason, usage). Ignora
 * `cache_control`, `anthropic-version` y `anthropic-beta` sin romper. Eso deja UN solo loop de tool
 * use para los dos: lo único que cambia por proveedor es la URL, la clave, el id del modelo y el
 * parámetro `thinking` (DeepSeek lo trae prendido por defecto y hay que decirle explícitamente si
 * se apaga; Anthropic no recibe ninguna clave `thinking`, exactamente como hasta hoy). Un traductor
 * OpenAI↔Anthropic sería código nuevo para un problema que el proveedor ya resolvió.
 *
 * 🔴 POR QUÉ HAY FALLBACK DE CREDENCIALES. La elección se guarda en la base del cliente y la clave
 * vive en el .env de su instalación: son dos cosas que pueden dejar de coincidir (un dueño eligió
 * DeepSeek y la clave se sacó después, o una base restaurada en una instalación sin esa clave). En
 * ese caso el asistente NO muere: cae al proveedor que sí tiene clave, con un warning en el log, y
 * el gasto se registra con el proveedor que efectivamente contestó. Si ninguno tiene clave, se
 * devuelve el elegido y el llamador falla por "sin credenciales", como hoy. El PUT de configuración
 * (AsistenteConfigController) es el que impide ELEGIR un proveedor sin clave; esto cubre lo que
 * pasa después.
 *
 * PHP 7.4: sin match, sin str_starts_with, sin argumentos nombrados, sin union types.
 */
class ProveedorIaHelper
{
    /** Claude, de Anthropic: el proveedor de siempre y el default del sistema. */
    const ANTHROPIC = 'anthropic';

    /** DeepSeek: la alternativa más económica, por su endpoint compatible con Anthropic. */
    const DEEPSEEK = 'deepseek';

    /** Los proveedores válidos, en el orden en que se ofrecen (y en el que se cae al buscar clave). */
    const PROVEEDORES = [self::ANTHROPIC, self::DEEPSEEK];

    /** Default del proveedor (coincide con el default de la columna `users.agente_proveedor`). */
    const PROVEEDOR_POR_DEFECTO = self::ANTHROPIC;

    /** Default del pensamiento (coincide con el default de la columna `users.agente_pensamiento`). */
    const PENSAMIENTO_POR_DEFECTO = 'agil';

    /**
     * Qué variantes de "cómo piensa" tiene cada proveedor. Claude tiene las tres de siempre;
     * DeepSeek tiene dos (Flash = agil, Pro = profundo) y NO tiene equilibrado.
     */
    const PENSAMIENTOS_POR_PROVEEDOR = [
        self::ANTHROPIC => ['agil', 'equilibrado', 'profundo'],
        self::DEEPSEEK  => ['agil', 'profundo'],
    ];

    /** Versión de la API de Anthropic que se declara en cada llamada (DeepSeek la ignora). */
    const ANTHROPIC_VERSION = '2023-06-01';

    /** Beta de caché de prompt: los system y las tools llevan cache_control (DeepSeek lo ignora). */
    const ANTHROPIC_BETA = 'prompt-caching-2024-07-31';

    /** Ruta del endpoint de mensajes, común a los dos proveedores. */
    const RUTA_MESSAGES = '/v1/messages';

    /**
     * Tipos de error que la API declara transitorios (el proveedor saturado): el llamador le
     * dice a la persona que el servicio está sobrecargado y que pruebe en unos segundos, no que
     * hubo una falla técnica. Nadie reintenta solo: el job de respuesta tiene $tries = 1.
     */
    const TIPOS_DE_ERROR_TRANSITORIO = ['overloaded_error', 'api_error'];

    /**
     * Códigos HTTP que se tratan como transitorios. 529 es el de Anthropic saturado; 429, 500, 502
     * y 503 son lo que devuelve DeepSeek saturado (y cualquier proxy o balanceador caído).
     */
    const CODIGOS_HTTP_TRANSITORIOS = [429, 500, 502, 503, 529];

    /**
     * El nombre del proveedor tal como lo lee una persona: "Claude" / "DeepSeek".
     *
     * @param  string  $proveedor
     * @return string
     */
    public static function nombre_de($proveedor): string
    {
        return (string) $proveedor === self::DEEPSEEK ? 'DeepSeek' : 'Claude';
    }

    /**
     * true si la instalación tiene clave cargada para ese proveedor.
     *
     * @param  string  $proveedor
     * @return bool
     */
    public static function hay_credenciales($proveedor): bool
    {
        return (string) config('services.' . (string) $proveedor . '.api_key') !== '';
    }

    /**
     * Los proveedores que tienen clave en esta instalación, en el orden de PROVEEDORES.
     *
     * @return array<int, string>
     */
    public static function proveedores_disponibles(): array
    {
        $disponibles = [];

        foreach (self::PROVEEDORES as $proveedor) {

            if (self::hay_credenciales($proveedor)) {

                $disponibles[] = $proveedor;
            }
        }

        return $disponibles;
    }

    /**
     * Las variantes de "cómo piensa" válidas para un proveedor (vacío si el proveedor no existe).
     *
     * @param  string  $proveedor
     * @return array<int, string>
     */
    public static function pensamientos_de($proveedor): array
    {
        return isset(self::PENSAMIENTOS_POR_PROVEEDOR[(string) $proveedor])
            ? self::PENSAMIENTOS_POR_PROVEEDOR[(string) $proveedor]
            : [];
    }

    /**
     * El proveedor que el dueño ELIGIÓ (`users.agente_proveedor`), cayendo al default si la columna
     * está vacía o fuera del enum. Sin mirar las credenciales: es lo que se muestra en la
     * configuración y lo que se compara al validar un PUT.
     *
     * @param  \App\Models\User|null  $owner
     * @return string
     */
    public static function proveedor_elegido($owner): string
    {
        $valor = is_null($owner) ? '' : (string) $owner->agente_proveedor;

        return in_array($valor, self::PROVEEDORES, true) ? $valor : self::PROVEEDOR_POR_DEFECTO;
    }

    /**
     * Los dueños por los que ya se avisó el fallback de clave en este proceso PHP, por owner_id
     * (0 = sin dueño). Ver el comentario del warning en proveedor_de().
     *
     * @var array<int, bool>
     */
    protected static $avisados = [];

    /**
     * El proveedor con el que EFECTIVAMENTE se llama: el elegido si tiene clave; si no, el primero
     * que la tenga, con un warning en el log (ver el docblock de la clase); si ninguno la tiene, el
     * elegido, para que el llamador falle por "sin credenciales" como hasta hoy.
     *
     * @param  \App\Models\User|null  $owner
     * @return string
     */
    public static function proveedor_de($owner): string
    {
        $elegido = self::proveedor_elegido($owner);

        if (self::hay_credenciales($elegido)) {

            return $elegido;
        }

        $disponibles = self::proveedores_disponibles();

        if (count($disponibles) === 0) {

            return $elegido;
        }

        $reemplazo = $disponibles[0];

        /*
         * UN warning por dueño y por request, no uno por llamada: por cada mensaje esto se resuelve
         * dos veces (el guard del job y el service), y además en cada GET de config o de consumo y
         * en cada recolección nocturna del admin. Repetirlo no agrega información y entierra el
         * log. El resultado no cambia: solo se calla el aviso repetido dentro del mismo proceso PHP
         * (en un worker de cola de larga vida, una vez por dueño mientras viva el worker).
         */
        $clave_de_aviso = is_null($owner) ? 0 : (int) $owner->id;

        if (! isset(self::$avisados[$clave_de_aviso])) {
            self::$avisados[$clave_de_aviso] = true;

            Log::warning(
                'ProveedorIaHelper: el dueño eligió ' . self::nombre_de($elegido) . ' pero esta instalación no tiene '
                . 'su clave cargada; el asistente cae a ' . self::nombre_de($reemplazo) . '.',
                [
                    'owner_id'  => is_null($owner) ? null : $owner->id,
                    'elegido'   => $elegido,
                    'reemplazo' => $reemplazo,
                ]
            );
        }

        return $reemplazo;
    }

    /**
     * La variante de "cómo piensa" del dueño, válida para ESE proveedor: `users.agente_pensamiento`
     * si el proveedor la tiene; si no, el default. Así un `equilibrado` guardado con Claude cae a
     * `agil` cuando el asistente corre con DeepSeek, que no tiene equilibrado.
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $proveedor
     * @return string
     */
    public static function pensamiento_de($owner, $proveedor): string
    {
        $valor = is_null($owner) ? '' : (string) $owner->agente_pensamiento;

        return in_array($valor, self::pensamientos_de($proveedor), true) ? $valor : self::PENSAMIENTO_POR_DEFECTO;
    }

    /**
     * Con qué corre el chat del dueño (AsistenteIaService): el proveedor efectivo, la variante de
     * pensamiento válida para él, el id del modelo y el parámetro `thinking` del payload.
     *
     * 🔴 LOS IDS NO SE HARDCODEAN ACÁ: salen de config/services.php, que es donde se pueden mover
     * por .env. Anthropic: el mapeo de siempre (profundo → model_profundo, equilibrado →
     * model_equilibrado, el resto → model_agil), y si el elegido viniera vacío (config mal armada)
     * cae al `services.anthropic.model` de siempre — así un negocio nunca queda sin modelo. Sin
     * clave `thinking`: es exactamente lo que se mandaba antes de esta misión. DeepSeek: profundo →
     * model_profundo con thinking `thinking_profundo` (enabled), el resto → model_agil con thinking
     * `thinking_agil` (disabled); si el modelo viene vacío cae a `services.deepseek.model`.
     *
     * 🔴 CON UNA FOTO EN EL PEDIDO, DEEPSEEK VA CON EL MODELO DE VISIÓN. `deepseek-v4-pro` (Profundo)
     * NO ve imágenes (api-docs.deepseek.com: Flash "Vision: Supported", Pro "Not supported"), y
     * DeepSeek no lo denuncia con un error: mapea el pedido y contesta como si la foto no
     * estuviera — un camino de falla mudo, el dueño manda la factura y recibe una respuesta que
     * no la miró. Por eso, con `$necesita_vision`, el modelo se elige EXPLÍCITO
     * (`services.deepseek.model_vision`, Flash; si viene vacío, `model_agil`) y el `thinking` sigue
     * siendo el de la variante elegida: Profundo mantiene `enabled`, porque Flash también razona.
     * Anthropic no cambia: todos sus modelos ven. El `modelo` que se devuelve es el que efectivamente
     * se usa y el que se registra en ai_token_usages.
     *
     * 🔴 CON `$forzar_profundo` EL PENSAMIENTO DEL DUEÑO SE IGNORA Y SE VA AL PROFUNDO (misión
     * asistente-capacidades-y-hilos, 22/9/2026). Lo pide el loop del chat cuando el turno ya tocó
     * una tool de carga: "las acciones contra deepseek pro a ver si cambia" (Lucas, 22/9). Es POR
     * TURNO y por vuelta, no una preferencia guardada: `users.agente_pensamiento` no se toca y la
     * configuración sigue mostrando lo que el dueño eligió. El orden importa — el forzado se aplica
     * ANTES de elegir el modelo, así que la guarda de visión (que corre después) sigue mandando y un
     * turno con foto va a Flash aunque haya escalado. Y el `thinking` sale del pensamiento ya
     * forzado, así que el techo que sube agregar_thinking() acompaña al modelo que corre.
     *
     * @param  \App\Models\User|null  $owner
     * @param  bool  $necesita_vision  true si el pedido lleva al menos un bloque `image`.
     * @param  bool  $forzar_profundo  true para correr esta llamada con el modelo Profundo.
     * @return array{proveedor:string, pensamiento:string, modelo:string, thinking:array|null}
     */
    public static function modelo_del_asistente($owner, $necesita_vision = false, $forzar_profundo = false): array
    {
        $proveedor   = self::proveedor_de($owner);
        $pensamiento = self::pensamiento_de($owner, $proveedor);

        /* El `in_array` es por si alguna vez hay un proveedor sin variante profunda: ahí no se fuerza nada. */
        if ($forzar_profundo && in_array('profundo', self::pensamientos_de($proveedor), true)) {

            $pensamiento = 'profundo';
        }

        if ($proveedor === self::DEEPSEEK) {

            $es_profundo = $pensamiento === 'profundo';

            if ($necesita_vision) {
                $modelo = (string) config('services.deepseek.model_vision');

                if ($modelo === '') {
                    $modelo = (string) config('services.deepseek.model_agil');
                }
            } else {
                $modelo = (string) config($es_profundo ? 'services.deepseek.model_profundo' : 'services.deepseek.model_agil');
            }

            return [
                'proveedor'   => $proveedor,
                'pensamiento' => $pensamiento,
                'modelo'      => self::modelo_o_general($modelo, self::DEEPSEEK),
                'thinking'    => self::thinking_de_deepseek($es_profundo),
            ];
        }

        /* PHP 7.4: sin match(). 'agil' y cualquier valor no reconocido caen al mismo default de siempre. */
        $modelos_por_pensamiento = [
            'profundo'    => (string) config('services.anthropic.model_profundo'),
            'equilibrado' => (string) config('services.anthropic.model_equilibrado'),
        ];

        $preferido = isset($modelos_por_pensamiento[$pensamiento])
            ? $modelos_por_pensamiento[$pensamiento]
            : (string) config('services.anthropic.model_agil');

        return [
            'proveedor'   => $proveedor,
            'pensamiento' => $pensamiento,
            'modelo'      => self::modelo_o_general($preferido, self::ANTHROPIC),
            'thinking'    => null,
        ];
    }

    /**
     * Con qué corren las llamadas "generales" del asistente —el bot de WhatsApp a los clientes del
     * negocio y el título de conversación—, que no distinguen variante de pensamiento: el modelo
     * general del proveedor efectivo (`services.<proveedor>.model`). Con DeepSeek va con el thinking
     * apagado (son respuestas cortas y baratas, el análogo de Ágil); con Anthropic, sin clave
     * `thinking`, como siempre.
     *
     * @param  \App\Models\User|null  $owner
     * @return array{proveedor:string, modelo:string, thinking:array|null}
     */
    public static function modelo_general($owner): array
    {
        $proveedor = self::proveedor_de($owner);

        return [
            'proveedor' => $proveedor,
            'modelo'    => (string) config('services.' . $proveedor . '.model'),
            'thinking'  => $proveedor === self::DEEPSEEK ? self::thinking_de_deepseek(false) : null,
        ];
    }

    /**
     * La URL del endpoint de mensajes del proveedor: `{base_url}/v1/messages`.
     *
     * @param  string  $proveedor
     * @return string
     */
    public static function url_messages($proveedor): string
    {
        return rtrim((string) config('services.' . (string) $proveedor . '.base_url'), '/') . self::RUTA_MESSAGES;
    }

    /**
     * Cliente HTTP hacia el proveedor: la clave en `x-api-key`, los headers de versión y de caché de
     * prompt de Anthropic (DeepSeek los ignora sin romper), el timeout que pide el llamador y el
     * bloque TLS del proveedor (WAMP/Windows suele requerir ca_bundle o verify_ssl=false). Es la
     * misma lógica que tenían AsistenteIaService::build_http_client() y sus dos copias, movida acá.
     *
     * @param  string  $proveedor
     * @param  int  $timeout_segundos
     * @return \Illuminate\Http\Client\PendingRequest
     */
    public static function cliente_http($proveedor, $timeout_segundos)
    {
        $proveedor = (string) $proveedor;

        $http = Http::withHeaders([
            'x-api-key'         => (string) config('services.' . $proveedor . '.api_key'),
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'anthropic-beta'    => self::ANTHROPIC_BETA,
            'content-type'      => 'application/json',
        ])->timeout((int) $timeout_segundos);

        $verify_ssl = (bool) config('services.' . $proveedor . '.verify_ssl', true);
        $ca_bundle  = config('services.' . $proveedor . '.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Le suma la clave `thinking` al payload SOLO si el proveedor la necesita: con null (Anthropic)
     * el payload vuelve tal cual, byte por byte lo mismo que se mandaba antes de esta misión.
     *
     * 🔴 Y CON EL THINKING PRENDIDO SUBE `max_tokens` AL TECHO DE PROFUNDO. No está documentado si
     * el endpoint compatible con Anthropic de DeepSeek cuenta los tokens de razonamiento contra
     * `max_tokens` (en el formato OpenAI no los cuenta; en el de Anthropic sí): con los 1500 del
     * asistente, un razonamiento largo podría agotar el techo antes de escribir una sola palabra
     * de respuesta, y Profundo cortaría "sin texto". Por eso, cuando el `thinking` que se agrega es
     * `enabled`, el techo del payload se sube a `services.deepseek.max_tokens_profundo` si el que
     * trae es menor — nunca se baja. Es inocuo para el costo: la salida se paga por token
     * GENERADO, no por el techo declarado.
     *
     * @param  array  $payload
     * @param  array|null  $thinking
     * @return array
     */
    public static function agregar_thinking(array $payload, $thinking): array
    {
        if (is_null($thinking)) {

            return $payload;
        }

        $payload['thinking'] = $thinking;

        if (isset($thinking['type']) && (string) $thinking['type'] === 'enabled') {

            $techo_profundo = (int) config('services.deepseek.max_tokens_profundo');
            $techo_actual   = isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 0;

            if ($techo_profundo > $techo_actual) {
                $payload['max_tokens'] = $techo_profundo;
            }
        }

        return $payload;
    }

    /**
     * 🔴 UN TURNO NO PUEDE PASAR DE THINKING APAGADO A PRENDIDO A MITAD DE CAMINO (medido contra la
     * API real de DeepSeek en demo3, 23/9/2026: HTTP 400 "The `content[].thinking` in the thinking
     * mode must be passed back to the API").
     *
     * Con `thinking: enabled` DeepSeek exige que cada turno del assistant que trae un `tool_use`
     * lleve también su bloque `thinking`, devuelto tal cual. Un turno que arrancó con el thinking
     * apagado (Ágil) y ya dio una vuelta de tool_use NO tiene esos bloques —el modelo no pensó—, y
     * no se pueden inventar. Si el loop escala a Profundo después de esa vuelta y prende el
     * thinking, DeepSeek rechaza el pedido entero y la persona ve "se me cortó la conexión" en cada
     * reintento (le pasó a un dueño en Ágil pidiendo crear un proveedor desde una factura: el
     * `tool_use` de `proponer_*` dispara el escalado y la vuelta siguiente moría).
     *
     * Por eso, si el thinking que se quiere mandar es `enabled` y el historial ya tiene un turno
     * assistant con `tool_use` sin bloque `thinking`, esa llamada va con el thinking apagado: el
     * MODELO sigue siendo el escalado (Pro), solo que sin razonamiento explícito, que es lo único
     * que DeepSeek acepta con ese historial. Un turno que arranca con el thinking prendido
     * (dueño en Profundo) guarda los bloques que devolvió el modelo y no se toca. Sin `thinking`
     * (Anthropic, null) y con `disabled` el resultado es el mismo que entró.
     *
     * @param  array|null  $thinking  El bloque que eligió modelo_del_asistente().
     * @param  array  $messages  Los mensajes que van en este pedido.
     * @return array|null
     */
    public static function thinking_apto_para_historial($thinking, array $messages)
    {
        if (! is_array($thinking) || ! isset($thinking['type']) || (string) $thinking['type'] !== 'enabled') {

            return $thinking;
        }

        foreach ($messages as $mensaje) {

            if (! is_array($mensaje) || ($mensaje['role'] ?? '') !== 'assistant' || ! isset($mensaje['content']) || ! is_array($mensaje['content'])) {
                continue;
            }

            $tiene_tool_use = false;
            $tiene_thinking = false;

            foreach ($mensaje['content'] as $bloque) {

                $tipo = is_array($bloque) && isset($bloque['type']) ? (string) $bloque['type'] : '';

                if ($tipo === 'tool_use') {
                    $tiene_tool_use = true;
                }

                if ($tipo === 'thinking' || $tipo === 'redacted_thinking') {
                    $tiene_thinking = true;
                }
            }

            if ($tiene_tool_use && ! $tiene_thinking) {

                return ['type' => 'disabled'];
            }
        }

        return $thinking;
    }

    /**
     * true si la respuesta fallida es un error transitorio (el proveedor saturado), que se le cuenta
     * a la persona como "sobrecargado, probá en unos segundos" y no como una falla técnica.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     * @return bool
     */
    public static function es_error_transitorio($response): bool
    {
        $error_body = $response->json();
        $error_type = is_array($error_body) && isset($error_body['error']['type']) ? $error_body['error']['type'] : null;

        if (! is_null($error_type) && in_array((string) $error_type, self::TIPOS_DE_ERROR_TRANSITORIO, true)) {

            return true;
        }

        return in_array((int) $response->status(), self::CODIGOS_HTTP_TRANSITORIOS, true);
    }

    /**
     * El id del modelo, o el general del proveedor si el preferido vino vacío (config mal armada):
     * un negocio nunca queda sin modelo.
     *
     * @param  string  $modelo
     * @param  string  $proveedor
     * @return string
     */
    protected static function modelo_o_general($modelo, $proveedor): string
    {
        if ((string) $modelo !== '') {

            return (string) $modelo;
        }

        return (string) config('services.' . (string) $proveedor . '.model');
    }

    /**
     * El bloque `thinking` que se le manda a DeepSeek: `{type: enabled}` para profundo y
     * `{type: disabled}` para el resto, con el valor de config por si hay que moverlo por .env.
     *
     * Con `enabled` viaja también `budget_tokens`, y no porque DeepSeek lo use: su doc dice que lo
     * ignora. Va porque el esquema de Anthropic —que es el que este endpoint imita— lo exige junto
     * con `enabled` (y menor que `max_tokens`), y si la validación de DeepSeek copia ese esquema, un
     * `enabled` pelado rebota con 400 en el primer uso real de Profundo. Mandarlo no cuesta nada;
     * omitirlo puede costar la funcionalidad entera. Queda por debajo de `max_tokens_profundo`.
     *
     * @param  bool  $es_profundo
     * @return array{type:string, budget_tokens?:int}
     */
    protected static function thinking_de_deepseek($es_profundo): array
    {
        $tipo = (string) config($es_profundo ? 'services.deepseek.thinking_profundo' : 'services.deepseek.thinking_agil');

        if ($tipo === '') {
            $tipo = $es_profundo ? 'enabled' : 'disabled';
        }

        if ($tipo !== 'enabled') {

            return ['type' => $tipo];
        }

        $techo  = (int) config('services.deepseek.max_tokens_profundo', 8000);
        $budget = (int) config('services.deepseek.thinking_budget_tokens', 4000);

        if ($budget >= $techo) {
            $budget = (int) floor($techo / 2);
        }

        return ['type' => 'enabled', 'budget_tokens' => max(1024, $budget)];
    }
}
