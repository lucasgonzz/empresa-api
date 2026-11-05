<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El único lugar del repo que sabe que el asistente tiene DOS proveedores de IA: Claude (Anthropic)
 * y DeepSeek (misión proveedores-ia-deepseek, 22/9/2026).
 *
 * Lo eligen por DUEÑO (`users.agente_proveedor`, mismo precedente que `agente_pensamiento` y
 * `agente_confianza`: la config es del comercio, no de la persona) y lo siguen dos caminos del
 * asistente: el chat del dueño (AsistenteIaService, en el sistema y por WhatsApp) y el título de
 * conversación (InferirTituloConversacionIaJob). Ninguno de los dos sabe cuántos proveedores hay ni
 * cómo se llaman: le piden a este helper el modelo, el cliente HTTP, la URL y el payload, y registran
 * el gasto con el `proveedor` que este helper les devolvió, que es el que efectivamente contestó.
 *
 * Misión modelos-ia-por-cliente (30/9/2026): el bot de WhatsApp a los clientes del negocio
 * (WhatsappBotAiService) YA NO sigue `agente_proveedor`: tiene su propio modelo por cliente
 * (`users.ia_modelo_whatsapp`), igual que la verificación de imágenes y la importación de Excel, y lo
 * decide ModelosIaHelper. Este helper les sigue dando el CÓMO (cliente HTTP, URL, thinking, errores
 * transitorios); el QUÉ modelo lo resuelve aquel.
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
    /** Claude, de Anthropic: la alternativa más cara, la de siempre hasta el 28/9/2026. */
    const ANTHROPIC = 'anthropic';

    /** DeepSeek: por su endpoint compatible con Anthropic, el default del sistema desde el
     * 28/9/2026 (misión agente-ia-default-deepseek-directo, pedido de Lucas). */
    const DEEPSEEK = 'deepseek';

    const MODELO_HAIKU_IMAGENES = 'claude-haiku-4-5-20251001';

    /** Los proveedores válidos, en el orden en que se ofrecen (y en el que se cae al buscar clave). */
    const PROVEEDORES = [self::ANTHROPIC, self::DEEPSEEK];

    /** Default del proveedor (coincide con el default de la columna `users.agente_proveedor`). */
    const PROVEEDOR_POR_DEFECTO = self::DEEPSEEK;

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

    /**
     * El selector de modelo de la app «ComercioCity IA» (misión app-ia-y-memoria, 29/10/2026): el dueño
     * elige, para UN turno, entre Rápido (DeepSeek ágil, el default de la app) y Pensamiento (DeepSeek
     * profundo). Viaja en el mensaje del asistente (`ai_messages.modelo_pedido`) y no toca la
     * configuración guardada del dueño. Sin clave de DeepSeek no aplica: va la configuración del dueño.
     */
    const MODELO_PEDIDO_RAPIDO = 'rapido';

    const MODELO_PEDIDO_PENSAMIENTO = 'pensamiento';

    const MODELOS_PEDIDOS = [self::MODELO_PEDIDO_RAPIDO, self::MODELO_PEDIDO_PENSAMIENTO];

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

    /** El proveedor de texto del asistente, incluso para configuraciones anteriores. */
    public static function proveedor_elegido($owner): string
    {
        return self::DEEPSEEK;
    }

    /** El asistente necesita la clave de DeepSeek para responder. */
    public static function proveedor_de($owner): string
    {
        return self::proveedor_elegido($owner);
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
     * Modelo del asistente. La visión usa Haiku y el chat razona en DeepSeek.
     * Los modos elegidos desde la app se aplican solo al turno actual.
     */
    public static function modelo_del_asistente($owner, $necesita_vision = false, $forzar_profundo = false, $modelo_pedido = null): array
    {
        if ($necesita_vision) {
            return [
                'proveedor'   => self::ANTHROPIC,
                'pensamiento' => 'agil',
                'modelo'      => self::MODELO_HAIKU_IMAGENES,
                'thinking'    => null,
            ];
        }

        $pensamiento = self::pensamiento_de($owner, self::DEEPSEEK);
        if (self::modelo_pedido_aplica($modelo_pedido)) {
            $pensamiento = (string) $modelo_pedido === self::MODELO_PEDIDO_PENSAMIENTO ? 'profundo' : 'agil';
        }
        if ($forzar_profundo) {
            $pensamiento = 'profundo';
        }

        $es_profundo = $pensamiento === 'profundo';
        $modelo = (string) config($es_profundo ? 'services.deepseek.model_profundo' : 'services.deepseek.model_agil');

        return [
            'proveedor'   => self::DEEPSEEK,
            'pensamiento' => $pensamiento,
            'modelo'      => self::modelo_o_general($modelo, self::DEEPSEEK),
            'thinking'    => self::thinking_de_deepseek($es_profundo),
        ];
    }

    /**
     * true si el modelo que pidió el dueño desde la app vale para este turno: es uno de los dos del
     * selector y hay clave de DeepSeek. Sin clave, el turno corre con la configuración del dueño (la app
     * no puede pedir un proveedor que esta instalación no tiene).
     *
     * @param  string|null  $modelo_pedido
     * @return bool
     */
    public static function modelo_pedido_aplica($modelo_pedido): bool
    {
        return in_array((string) $modelo_pedido, self::MODELOS_PEDIDOS, true) && self::hay_credenciales(self::DEEPSEEK);
    }

    /**
     * El proveedor con el que corre ESTE turno: DeepSeek si el dueño eligió un modelo desde la app (y
     * hay clave), si no el de siempre (proveedor_de()).
     *
     * @param  \App\Models\User|null  $owner
     * @param  string|null  $modelo_pedido
     * @return string
     */
    public static function proveedor_del_turno($owner, $modelo_pedido = null): string
    {
        return self::proveedor_de($owner);
    }

    /**
     * El id del modelo de DeepSeek que VE imágenes: `services.deepseek.model_vision` (Flash) y, si
     * viene vacío, `model_agil`; si también, el general. Lo usan dos caminos que tienen que coincidir:
     * la guarda de visión de modelo_del_asistente() y la transcripción de fotos de un turno que corre
     * en Pro (TranscripcionDeFotosIaHelper, misión asistente-deepseek-pro-razona, 24/9/2026).
     *
     * @return string
     */
    public static function modelo_de_vision_de_deepseek(): string
    {
        $modelo = (string) config('services.deepseek.model_vision');

        if ($modelo === '') {
            $modelo = (string) config('services.deepseek.model_agil');
        }

        return self::modelo_o_general($modelo, self::DEEPSEEK);
    }

    /**
     * Con qué corren las llamadas "generales" del asistente —el bot de WhatsApp a los clientes del
     * negocio y el título de conversación—, que no distinguen variante de pensamiento: el modelo
     * general del proveedor efectivo (`services.<proveedor>.model`). Con DeepSeek va con el thinking
     * apagado (son respuestas cortas y baratas, el análogo de Ágil); con Anthropic, sin clave
     * `thinking`, como siempre.
     *
     * Misión modelos-ia-por-cliente (30/9/2026): el bot de WhatsApp YA NO pasa por acá. Tiene su
     * propio modelo elegible por cliente (`users.ia_modelo_whatsapp`, ver ModelosIaHelper), separado
     * del asistente, por decisión de Lucas. Lo que sigue usando este método es el título de
     * conversación (InferirTituloConversacionIaJob), que el plan de esa misión deja como estaba.
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
     * Llama al modelo resuelto y devuelve [respuesta, modelo enviado]. Para pedidos compatibles,
     * reserva parte del timeout original para un único respaldo sin cambiar el contrato del pedido.
     * El llamador recibe una sola respuesta y recién ahí interpreta texto o ejecuta herramientas.
     */
    public static function pedir_mensaje($proveedor, int $timeout, array $payload, bool $permitir_respaldo = true, ?callable $durante_respaldo = null): array
    {
        $modelo = (string) $payload['model'];
        $alternativo = self::variante_de_respaldo($proveedor, $timeout, $payload, $permitir_respaldo);
        $url = self::url_messages($proveedor);

        if ($alternativo === null) {
            return [self::cliente_http($proveedor, $timeout)->post($url, $payload), $modelo];
        }

        $inicio = hrtime(true);
        $respuesta = null;
        $error_inicial = null;
        $timeout_inicial = $timeout - max(1, (int) floor($timeout / 4));

        try {
            $respuesta = self::cliente_http($proveedor, $timeout_inicial)->post($url, $payload);
            if (! in_array($respuesta->status(), [408, 429, 500, 502, 503, 504, 529], true)) {
                return [$respuesta, $modelo];
            }
        } catch (ConnectionException $e) {
            if (! self::es_conexion_transitoria($e)) {
                throw $e;
            }
            $error_inicial = $e;
        }

        $respaldar = function () use ($proveedor, $url, $payload, $modelo, $alternativo, $respuesta, $timeout, $inicio) {
            // El aviso de progreso también consume tiempo del presupuesto del llamador.
            $segundos_restantes = self::segundos_restantes($inicio, $timeout);
            if ($segundos_restantes < 1) {
                return null;
            }

            $datos = [
                'modelo_inicial' => $modelo,
                'modelo_de_respaldo' => $alternativo,
                'motivo' => $respuesta === null ? 'conexion' : 'http',
                'http' => $respuesta === null ? null : $respuesta->status(),
            ];

            $payload['model'] = $alternativo;
            try {
                $respaldo = self::cliente_http($proveedor, $segundos_restantes)->post($url, $payload);
            } catch (ConnectionException $e) {
                Log::warning('DeepSeek: falló la conexión con la variante de respaldo.', $datos);
                throw $e;
            }
            Log::info('DeepSeek: respondió la variante de respaldo.', $datos + ['http_de_respaldo' => $respaldo->status()]);
            return $respaldo;
        };

        try {
            $respaldo = $durante_respaldo === null
                ? $respaldar()
                : $durante_respaldo($respaldar, $respuesta === null);
        } catch (ConnectionException $e) {
            $respaldo = null;
        }

        if ($respaldo !== null && $respaldo->successful()) {
            return [$respaldo, $alternativo];
        }
        if ($error_inicial !== null) {
            throw $error_inicial;
        }
        return [$respuesta, $modelo];
    }

    /**
     * true si pedir_mensaje() mandaría este pedido a una variante alternativa cuando el modelo falla.
     * Los llamadores que ya tienen su propio reintento del MISMO modelo (el asistente, con su presupuesto
     * de tiempo y su aviso de progreso) lo usan para decidir: con variante alternativa usan el respaldo;
     * sin ella, conservan su camino de siempre. Es la misma decisión que toma pedir_mensaje().
     */
    public static function tiene_respaldo($proveedor, int $timeout, array $payload, bool $permitir_respaldo = true): bool
    {
        return self::variante_de_respaldo($proveedor, $timeout, $payload, $permitir_respaldo) !== null;
    }

    private static function variante_de_respaldo($proveedor, int $timeout, array $payload, bool $permitir_respaldo): ?string
    {
        if ($proveedor !== self::DEEPSEEK || ! $permitir_respaldo || $timeout < 2 || ! isset($payload['model'])) {
            return null;
        }
        return self::alternativo_de_deepseek((string) $payload['model'], $payload);
    }

    public static function segundos_restantes($inicio, int $timeout): int
    {
        return max(0, $timeout - (int) ceil((hrtime(true) - $inicio) / 1e9));
    }

    private static function alternativo_de_deepseek(string $modelo, array $payload): ?string
    {
        if (! empty($payload['stream'])) {
            return null;
        }
        foreach ($payload['tools'] ?? [] as $herramienta) {
            if (! is_array($herramienta) || array_key_exists('type', $herramienta) || ! isset($herramienta['input_schema'])) {
                return null;
            }
        }
        if ($modelo === 'deepseek-v4-pro') {
            return 'deepseek-flash';
        }
        if (in_array($modelo, ['deepseek-flash', 'deepseek-v4-flash', 'deepseek-v4-flash-vision-exp'], true)
            && ! self::requiere_vision($payload['messages'] ?? [])) {
            return 'deepseek-v4-pro';
        }
        return null;
    }

    private static function requiere_vision(array $bloques): bool
    {
        foreach ($bloques as $bloque) {
            if (! is_array($bloque)) {
                continue;
            }
            if (in_array($bloque['type'] ?? null, ['image', 'image_url', 'document'], true) || self::requiere_vision($bloque)) {
                return true;
            }
        }
        return false;
    }

    private static function es_conexion_transitoria(ConnectionException $e): bool
    {
        $causa = $e->getPrevious();
        $errno = null;
        if ($causa instanceof \GuzzleHttp\Exception\ConnectException) {
            $errno = $causa->getHandlerContext()['errno'] ?? null;
        }
        if ($errno === null && preg_match('/cURL error (\d+)\b/i', $e->getMessage(), $coincide)) {
            $errno = $coincide[1];
        }
        if ($errno !== null) {
            return in_array((int) $errno, [5, 6, 7, 18, 28, 52, 55, 56, 92], true);
        }
        return preg_match('/\b(?:timed?\s*out|timeout|connection (?:reset|refused))\b/i', $e->getMessage()) === 1;
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
     * Es PUBLIC desde la misión modelos-ia-por-cliente (30/9/2026): ModelosIaHelper la usa para el
     * bloque `thinking` de las opciones de DeepSeek de las tareas que no son el asistente (WhatsApp,
     * imágenes, Excel). Solo cambió la visibilidad; el comportamiento es el mismo de siempre.
     *
     * @param  bool  $es_profundo
     * @return array{type:string, budget_tokens?:int}
     */
    public static function thinking_de_deepseek($es_profundo): array
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
