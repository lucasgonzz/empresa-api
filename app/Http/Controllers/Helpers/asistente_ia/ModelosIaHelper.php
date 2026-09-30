<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * El modelo de IA de cada TAREA, elegible por cliente desde el admin (misión modelos-ia-por-cliente,
 * 30/9/2026, pedido de Lucas: "elegir desde el admin, entrando en un cliente, el modelo de IA del
 * asistente, del bot de WhatsApp, de la verificación de imágenes y de la importación de Excel; por
 * defecto todo con DeepSeek").
 *
 * Es el único lugar que sabe tres cosas:
 *
 * 1. EL CATÁLOGO DE OPCIONES (OPCIONES): un id por modelo (`deepseek_flash`, `deepseek_pro`,
 *    `claude_haiku`, `claude_sonnet`, `claude_opus`) con su proveedor, si ve imágenes y la clave de
 *    config de donde sale el id REAL del modelo. Los ids reales nunca se hardcodean: salen de
 *    config/services.php y se mueven por .env.
 *
 * 2. LAS TAREAS (TAREAS) y su default. El asistente no tiene columna propia: su opción se traduce a
 *    las dos columnas que ya usa el modal "Configurá tu asistente" (`agente_proveedor` +
 *    `agente_pensamiento`), así el dueño y el admin escriben lo mismo y "gana el último" sale solo
 *    (decisión 2 de Lucas). Las otras tres tienen su columna en el dueño (`ia_modelo_*`), con null =
 *    el default de la tarea, que vive en DEFAULTS_POR_TAREA (en código, no en la migración: moverlo
 *    es un deploy, no reescribir filas).
 *
 * 3. CON QUÉ SE LLAMA DE VERDAD (resolver()), incluido el FALLBACK SIN CLAVE, que es lo que hace que
 *    esta misión no cambie nada en producción mientras ningún .env tenga DEEPSEEK_API_KEY.
 *
 * 🔴 FALLBACK SIN CLAVE: SI SE CAE A ANTHROPIC, SE USA EL MODELO "LEGADO" DE LA TAREA, NO EL
 * EQUIVALENTE DEL CATÁLOGO. Hoy el default de las tareas es DeepSeek y ninguna instalación tiene su
 * clave, así que TODA la flota va a pasar por este camino hasta que se cargue. Si el fallback fuera
 * "el Claude más parecido a Flash" (Haiku), el bot de WhatsApp pasaría de `ANTHROPIC_MODEL` (Sonnet)
 * a Haiku y la importación de Excel de Sonnet 4.5 a Haiku el día del deploy, sin que nadie lo haya
 * decidido. Con el legado, el modelo que se llama es byte a byte el mismo de antes de la misión:
 *   - whatsapp → services.anthropic.model (lo que devolvía modelo_general() con Anthropic)
 *   - imagenes → services.article_image_validation.model (Haiku 4.5)
 *   - excel    → services.importacion_excel_ia.model_anthropic (la vieja constante CLAUDE_MODEL)
 *   - asistente → lo que ya hace ProveedorIaHelper (no cambia: delega en modelo_del_asistente()).
 * Si se cae al revés (eligieron Claude y la instalación solo tiene clave de DeepSeek) va
 * `deepseek_flash`, salvo excel que va `deepseek_pro`. Si ningún proveedor tiene clave, resolver()
 * devuelve null y cada llamador hace lo que ya hacía "sin configurar".
 *
 * Reusa ProveedorIaHelper (claves, cliente HTTP, URL, thinking, errores transitorios): este helper
 * decide QUÉ modelo, aquel sabe CÓMO se le habla a cada proveedor.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types, sin ?->.
 */
class ModelosIaHelper
{
    /** El asistente del dueño (chat del sistema y por WhatsApp): acciones, informes, cargas. */
    const TAREA_ASISTENTE = 'asistente';

    /** El bot de WhatsApp que les contesta a los clientes del negocio (WhatsappBotAiService). */
    const TAREA_WHATSAPP = 'whatsapp';

    /** La verificación de imágenes de la asignación automática (ArticleImageValidationService). */
    const TAREA_IMAGENES = 'imagenes';

    /** La importación de Excel: identificar columnas y la recomendación (los tres Ai*Analyzer). */
    const TAREA_EXCEL = 'excel';

    /** Las tareas, en el orden en que se muestran en el admin. */
    const TAREAS = [self::TAREA_ASISTENTE, self::TAREA_WHATSAPP, self::TAREA_IMAGENES, self::TAREA_EXCEL];

    /** El nombre de cada tarea tal como lo lee una persona (lo muestra el admin). */
    const NOMBRES_DE_TAREA = [
        self::TAREA_ASISTENTE => 'Asistente del dueño',
        self::TAREA_WHATSAPP  => 'WhatsApp a clientes',
        self::TAREA_IMAGENES  => 'Verificación de imágenes',
        self::TAREA_EXCEL     => 'Importación de Excel',
    ];

    const DEEPSEEK_FLASH = 'deepseek_flash';
    const DEEPSEEK_PRO   = 'deepseek_pro';
    const CLAUDE_HAIKU   = 'claude_haiku';
    const CLAUDE_SONNET  = 'claude_sonnet';
    const CLAUDE_OPUS    = 'claude_opus';

    /**
     * El catálogo. `config_modelo` es la clave de config de donde sale el id real del modelo;
     * `profundo` dice si con DeepSeek el thinking va prendido (Pro razona, Flash no).
     *
     * 🔴 `deepseek_pro` NO VE IMÁGENES (api-docs.deepseek.com: Flash "Vision: Supported", Pro "Not
     * supported") y DeepSeek no lo denuncia: contesta como si la foto no estuviera. Por eso no vale
     * para `imagenes` y, en WhatsApp, un turno con foto va al modelo de visión (ver resolver()).
     */
    const OPCIONES = [
        self::DEEPSEEK_FLASH => [
            'proveedor'     => ProveedorIaHelper::DEEPSEEK,
            'nombre'        => 'DeepSeek Flash',
            'config_modelo' => 'services.deepseek.model_agil',
            'vision'        => true,
            'profundo'      => false,
        ],
        self::DEEPSEEK_PRO => [
            'proveedor'     => ProveedorIaHelper::DEEPSEEK,
            'nombre'        => 'DeepSeek Pro',
            'config_modelo' => 'services.deepseek.model_profundo',
            'vision'        => false,
            'profundo'      => true,
        ],
        self::CLAUDE_HAIKU => [
            'proveedor'     => ProveedorIaHelper::ANTHROPIC,
            'nombre'        => 'Claude Haiku',
            'config_modelo' => 'services.anthropic.model_agil',
            'vision'        => true,
            'profundo'      => false,
        ],
        self::CLAUDE_SONNET => [
            'proveedor'     => ProveedorIaHelper::ANTHROPIC,
            'nombre'        => 'Claude Sonnet',
            'config_modelo' => 'services.anthropic.model_equilibrado',
            'vision'        => true,
            'profundo'      => false,
        ],
        self::CLAUDE_OPUS => [
            'proveedor'     => ProveedorIaHelper::ANTHROPIC,
            'nombre'        => 'Claude Opus',
            'config_modelo' => 'services.anthropic.model_profundo',
            'vision'        => true,
            'profundo'      => false,
        ],
    ];

    /**
     * El default de cada tarea (null en la columna = esto). Flash donde importa la latencia o hay
     * fotos; Pro en Excel, que es una llamada por archivo y donde un mapeo mal hecho ensucia un
     * catálogo entero (tabla "Flash o Pro, por tarea" del plan de la misión).
     */
    const DEFAULTS_POR_TAREA = [
        self::TAREA_ASISTENTE => self::DEEPSEEK_FLASH,
        self::TAREA_WHATSAPP  => self::DEEPSEEK_FLASH,
        self::TAREA_IMAGENES  => self::DEEPSEEK_FLASH,
        self::TAREA_EXCEL     => self::DEEPSEEK_PRO,
    ];

    /** La columna del dueño de cada tarea que tiene columna propia (el asistente no la tiene). */
    const COLUMNAS_POR_TAREA = [
        self::TAREA_WHATSAPP => 'ia_modelo_whatsapp',
        self::TAREA_IMAGENES => 'ia_modelo_imagenes',
        self::TAREA_EXCEL    => 'ia_modelo_excel',
    ];

    /**
     * La traducción de una opción del asistente a las dos columnas que usa el modal del dueño:
     * [agente_proveedor, agente_pensamiento]. Es biyectiva con los pensamientos válidos de cada
     * proveedor (ProveedorIaHelper::PENSAMIENTOS_POR_PROVEEDOR).
     */
    const ASISTENTE_POR_OPCION = [
        self::DEEPSEEK_FLASH => [ProveedorIaHelper::DEEPSEEK, 'agil'],
        self::DEEPSEEK_PRO   => [ProveedorIaHelper::DEEPSEEK, 'profundo'],
        self::CLAUDE_HAIKU   => [ProveedorIaHelper::ANTHROPIC, 'agil'],
        self::CLAUDE_SONNET  => [ProveedorIaHelper::ANTHROPIC, 'equilibrado'],
        self::CLAUDE_OPUS    => [ProveedorIaHelper::ANTHROPIC, 'profundo'],
    ];

    /**
     * El modelo LEGADO de cada tarea que no es el asistente: el que usaba antes de esta misión y el
     * que se usa cuando la tarea cae a Anthropic (ver el 🔴 del docblock de la clase).
     */
    const CONFIG_LEGADO_ANTHROPIC = [
        self::TAREA_WHATSAPP => 'services.anthropic.model',
        self::TAREA_IMAGENES => 'services.article_image_validation.model',
        self::TAREA_EXCEL    => 'services.importacion_excel_ia.model_anthropic',
    ];

    /**
     * Avisos de fallback ya escritos en este proceso PHP, por "owner_id:tarea". Mismo criterio que
     * ProveedorIaHelper::$avisados: uno por dueño y tarea, no uno por llamada (una asignación de
     * imágenes son cientos de llamadas y repetir el warning entierra el log).
     *
     * @var array<string, bool>
     */
    protected static $avisados = [];

    /**
     * true si el id es una tarea conocida.
     *
     * @param  string  $tarea
     * @return bool
     */
    public static function es_tarea($tarea): bool
    {
        return in_array((string) $tarea, self::TAREAS, true);
    }

    /**
     * Las opciones que valen para una tarea, en el orden del catálogo. `imagenes` solo acepta las que
     * ven imágenes (sin `deepseek_pro`): es una tarea de visión pura y Pro contestaría sin mirar.
     *
     * @param  string  $tarea
     * @return array<int, string>
     */
    public static function opciones_validas($tarea): array
    {
        $validas = [];

        foreach (self::OPCIONES as $id => $opcion) {
            if ((string) $tarea === self::TAREA_IMAGENES && ! $opcion['vision']) {
                continue;
            }

            $validas[] = $id;
        }

        return $validas;
    }

    /**
     * El proveedor de una opción del catálogo ('' si la opción no existe).
     *
     * @param  string  $opcion
     * @return string
     */
    public static function proveedor_de_opcion($opcion): string
    {
        return isset(self::OPCIONES[(string) $opcion]) ? self::OPCIONES[(string) $opcion]['proveedor'] : '';
    }

    /**
     * El id REAL del modelo de una opción, leído de config. Si la clave de config viniera vacía
     * (config mal armada), cae al `services.<proveedor>.model` general: una tarea nunca queda sin
     * modelo. Mismo criterio que ProveedorIaHelper::modelo_o_general().
     *
     * @param  string  $opcion
     * @return string
     */
    public static function modelo_de_opcion($opcion): string
    {
        if (! isset(self::OPCIONES[(string) $opcion])) {
            return '';
        }

        $datos  = self::OPCIONES[(string) $opcion];
        $modelo = (string) config($datos['config_modelo']);

        if ($modelo === '') {
            $modelo = (string) config('services.' . $datos['proveedor'] . '.model');
        }

        return $modelo;
    }

    /**
     * La opción que el dueño tiene ELEGIDA para una tarea (o el default si no eligió o si lo guardado
     * no vale para esa tarea). Sin mirar las claves: es lo que se muestra y lo que se compara.
     *
     * Para el asistente se lee de `agente_proveedor` + `agente_pensamiento` con los mismos criterios
     * que ProveedorIaHelper (un 'equilibrado' guardado con DeepSeek es Flash, porque así corre).
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $tarea
     * @return string
     */
    public static function opcion_elegida($owner, $tarea): string
    {
        $tarea = (string) $tarea;

        if ($tarea === self::TAREA_ASISTENTE) {
            $proveedor   = ProveedorIaHelper::proveedor_elegido($owner);
            $pensamiento = ProveedorIaHelper::pensamiento_de($owner, $proveedor);

            return self::opcion_del_asistente($proveedor, $pensamiento);
        }

        $default = isset(self::DEFAULTS_POR_TAREA[$tarea]) ? self::DEFAULTS_POR_TAREA[$tarea] : self::DEEPSEEK_FLASH;

        if (is_null($owner) || ! isset(self::COLUMNAS_POR_TAREA[$tarea])) {
            return $default;
        }

        $valor = (string) $owner->{self::COLUMNAS_POR_TAREA[$tarea]};

        return in_array($valor, self::opciones_validas($tarea), true) ? $valor : $default;
    }

    /**
     * Con qué se llama DE VERDAD para una tarea: la opción elegida si su proveedor tiene clave, y si
     * no el fallback (ver el 🔴 del docblock de la clase).
     *
     * `$necesita_vision`: el pedido lleva al menos un bloque `image`. Si la opción efectiva no ve
     * (solo `deepseek_pro`), la llamada va con el modelo de visión de DeepSeek
     * (ProveedorIaHelper::modelo_de_vision_de_deepseek()), igual que ya hace el asistente, con el
     * thinking de la opción elegida (Flash también razona). Anthropic no cambia: todos sus modelos ven.
     *
     * Para el asistente delega en ProveedorIaHelper::modelo_del_asistente(): el chat del dueño no
     * cambia en nada con esta misión; lo único nuevo es que el admin puede escribir su elección.
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $tarea
     * @param  bool  $necesita_vision
     * @return array|null  {tarea, opcion_elegida, opcion_efectiva (null si es el legado de Anthropic),
     *                     proveedor, modelo, thinking (array|null), vision (bool: el modelo que corre ve),
     *                     fallback (bool)}; null si ningún proveedor tiene clave.
     */
    public static function resolver($owner, $tarea, $necesita_vision = false)
    {
        $tarea   = (string) $tarea;
        $elegida = self::opcion_elegida($owner, $tarea);

        if ($tarea === self::TAREA_ASISTENTE) {
            return self::resolver_asistente($owner, $elegida, $necesita_vision);
        }

        $proveedor_elegido = self::proveedor_de_opcion($elegida);

        if (ProveedorIaHelper::hay_credenciales($proveedor_elegido)) {
            return self::resolucion_de_opcion($tarea, $elegida, $elegida, (bool) $necesita_vision, false);
        }

        /* El otro proveedor: con dos, es "el que no es el elegido". */
        $otro = $proveedor_elegido === ProveedorIaHelper::DEEPSEEK ? ProveedorIaHelper::ANTHROPIC : ProveedorIaHelper::DEEPSEEK;

        if (! ProveedorIaHelper::hay_credenciales($otro)) {
            return null;
        }

        self::avisar_fallback($owner, $tarea, $elegida, $otro);

        if ($otro === ProveedorIaHelper::ANTHROPIC) {
            /*
             * 🔴 El LEGADO, no el Claude del catálogo "equivalente": así la producción de hoy (sin
             * DEEPSEEK_API_KEY en ningún .env) llama exactamente al mismo modelo que antes de esta
             * misión. No hay opción del catálogo que lo represente: `opcion_efectiva` va null y el
             * admin lo muestra por el id del modelo.
             */
            $legado = isset(self::CONFIG_LEGADO_ANTHROPIC[$tarea]) ? (string) config(self::CONFIG_LEGADO_ANTHROPIC[$tarea]) : '';

            if ($legado === '') {
                $legado = (string) config('services.anthropic.model');
            }

            return [
                'tarea'           => $tarea,
                'opcion_elegida'  => $elegida,
                'opcion_efectiva' => null,
                'proveedor'       => ProveedorIaHelper::ANTHROPIC,
                'modelo'          => $legado,
                'thinking'        => null,
                'vision'          => true,
                'fallback'        => true,
            ];
        }

        /* Cayó a DeepSeek: Flash, salvo Excel que es Pro (el default de esa tarea). */
        $reemplazo = $tarea === self::TAREA_EXCEL ? self::DEEPSEEK_PRO : self::DEEPSEEK_FLASH;

        return self::resolucion_de_opcion($tarea, $elegida, $reemplazo, (bool) $necesita_vision, true);
    }

    /**
     * Valida y guarda en el dueño las opciones que vinieron (una tarea ausente no se toca). Todo se
     * valida ANTES de escribir nada: con un solo error no se guarda ninguna.
     *
     * 🔴 NO EXIGE QUE EL PROVEEDOR TENGA CLAVE, a diferencia del PUT del modal del dueño: el admin
     * tiene que poder dejar DeepSeek elegido ANTES de cargar la clave en el .env del cliente (la
     * herramienta de flota la carga después). Mientras tanto corre el fallback y el payload del
     * admin lo muestra ("elegido DeepSeek Flash · corre Claude Haiku").
     *
     * Para el asistente escribe `agente_proveedor` + `agente_pensamiento` (ASISTENTE_POR_OPCION), las
     * mismas columnas que el PUT user/asistente-config: gana el último que escribe.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $cambios  tarea => id de opción.
     * @return array  {ok: bool, errors: array<string, array<int, string>>}
     */
    public static function guardar($owner, array $cambios): array
    {
        $errores = [];

        foreach (self::TAREAS as $tarea) {
            if (! array_key_exists($tarea, $cambios)) {
                continue;
            }

            $valor   = $cambios[$tarea];
            $validas = self::opciones_validas($tarea);

            if (! is_string($valor) || ! in_array($valor, $validas, true)) {
                $errores[$tarea] = [self::mensaje_de_opcion_invalida($tarea, $valor, $validas)];
            }
        }

        if (count($errores) > 0) {
            return ['ok' => false, 'errors' => $errores];
        }

        foreach (self::TAREAS as $tarea) {
            if (! array_key_exists($tarea, $cambios)) {
                continue;
            }

            $opcion = (string) $cambios[$tarea];

            if ($tarea === self::TAREA_ASISTENTE) {
                $owner->agente_proveedor   = self::ASISTENTE_POR_OPCION[$opcion][0];
                $owner->agente_pensamiento = self::ASISTENTE_POR_OPCION[$opcion][1];
                continue;
            }

            $owner->{self::COLUMNAS_POR_TAREA[$tarea]} = $opcion;
        }

        $owner->save();

        return ['ok' => true, 'errors' => []];
    }

    /**
     * El payload del contrato `GET|PUT admin-sync/modelos-ia` (ver el plan de la misión): el catálogo
     * con la disponibilidad de cada opción en esta instalación, y por tarea lo elegido, lo que vale y
     * lo que corre de verdad.
     *
     * `efectiva.opcion` es null cuando se cayó al legado de Anthropic (no es una opción del catálogo);
     * `efectiva.modelo` siempre es el id real. `efectiva` es null si ningún proveedor tiene clave.
     *
     * @param  \App\Models\User|null  $owner
     * @return array
     */
    public static function payload_para_admin($owner): array
    {
        $opciones = [];

        foreach (self::OPCIONES as $id => $datos) {
            $opciones[] = [
                'id'         => $id,
                'proveedor'  => $datos['proveedor'],
                'nombre'     => $datos['nombre'],
                'modelo'     => self::modelo_de_opcion($id),
                'vision'     => (bool) $datos['vision'],
                'disponible' => ProveedorIaHelper::hay_credenciales($datos['proveedor']),
            ];
        }

        $tareas = [];

        foreach (self::TAREAS as $tarea) {
            $resolucion = self::resolver($owner, $tarea);

            $tareas[$tarea] = [
                'nombre'           => self::NOMBRES_DE_TAREA[$tarea],
                'opcion'           => self::opcion_elegida($owner, $tarea),
                'opciones_validas' => self::opciones_validas($tarea),
                'efectiva'         => is_null($resolucion) ? null : [
                    'opcion'    => $resolucion['opcion_efectiva'],
                    'proveedor' => $resolucion['proveedor'],
                    'modelo'    => $resolucion['modelo'],
                    'fallback'  => (bool) $resolucion['fallback'],
                ],
            ];
        }

        return [
            'ok'       => true,
            'opciones' => $opciones,
            'tareas'   => $tareas,
        ];
    }

    /**
     * El texto de una respuesta de la API de Messages: el del PRIMER bloque `type = text`, o null
     * si no hay ninguno.
     *
     * 🔴 NO ES `content[0]['text']`, que es lo que leían los tres analizadores de Excel. Con el
     * thinking prendido (DeepSeek Pro, el default de la importación) el PRIMER bloque es `thinking`
     * —el razonamiento, sin clave `text`— y el texto de la respuesta viene después. Leer content[0]
     * daría null y la importación diría "la IA no pudo interpretar esta planilla" con una respuesta
     * perfectamente buena. Tampoco se concatenan todos los bloques `text`: el JSON que se espera es
     * uno solo, y pegarle un segundo bloque lo rompería.
     *
     * Un bloque que NO declara `type` pero trae `text` también cuenta como texto: es lo que devolvía
     * content[0]['text'] para un body así (varios tests de la importación lo arman de esa forma), y
     * no hay riesgo de confundirlo con un `thinking`, que siempre declara su tipo y lleva su
     * contenido en la clave `thinking`, no en `text`.
     *
     * @param  mixed  $body  El body decodificado de la respuesta.
     * @return string|null
     */
    public static function texto_de_respuesta($body)
    {
        if (! is_array($body) || ! isset($body['content']) || ! is_array($body['content'])) {
            return null;
        }

        foreach ($body['content'] as $bloque) {
            if (! is_array($bloque) || ! isset($bloque['text'])) {
                continue;
            }

            $tipo = isset($bloque['type']) ? (string) $bloque['type'] : 'text';

            if ($tipo === 'text') {
                return (string) $bloque['text'];
            }
        }

        return null;
    }

    /**
     * El objeto JSON que devolvió la IA adentro de su texto, tolerando lo que los modelos agregan
     * alrededor aunque el prompt pida "solo JSON". Devuelve el primer candidato que decodifica a un
     * array Y pasa `$es_valido` (la forma esperada por quien llama), o null si no hay ninguno.
     *
     * 🔴 POR QUÉ NO ALCANZA "DEL PRIMER `{` AL ÚLTIMO `}`" (lo que hacían los parsers de imágenes):
     * la comparación real Flash vs Haiku del 30/9/2026 mostró que DeepSeek Flash a veces escribe
     * PROSA antes del JSON ("Analizo las candidatas: ... {ejemplo} ..."). Si esa prosa trae una llave,
     * el recorte arranca en ella y el JSON queda ilegible: la candidata vuelve `sin_evaluar` aunque la
     * respuesta fuera buena. Y los analizadores de Excel directamente decodificaban el texto entero,
     * así que cualquier prosa los tiraba. Orden de búsqueda:
     *   1. El bloque cercado ```json ... ``` (o ``` ... ```), si existe: es lo que hace Haiku y es la
     *      señal más clara de dónde está el JSON.
     *   2. El texto entero (el caso de siempre: JSON pelado).
     *   3. Desde CADA `{` del texto, el objeto balanceado que abre (el escaneo respeta los strings,
     *      así una llave adentro de un "motivo" no corta nada), y si ese no sirve, desde esa `{`
     *      hasta la última `}`. Gana el primero que tenga la forma esperada: una `{ejemplo}` de la
     *      prosa decodifica mal o no pasa `$es_valido`, y se sigue con la próxima.
     *
     * @param  string  $texto
     * @param  callable|null  $es_valido  fn(array $candidato): bool. Null = cualquier array.
     * @return array|null
     */
    public static function extraer_json($texto, $es_valido = null)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return null;
        }

        $candidatos = [];

        /* 1. Bloques cercados con ``` (con o sin "json"), en orden. */
        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $texto, $cercados)) {
            foreach ($cercados[1] as $cercado) {
                $candidatos[] = trim($cercado);
            }
        }

        /* 2. El texto entero, sin las cercas de las puntas (el comportamiento de siempre). */
        $sin_cercas = preg_replace('/^```(?:json)?\s*/i', '', $texto);
        $sin_cercas = preg_replace('/\s*```$/', '', (string) $sin_cercas);
        $candidatos[] = trim((string) $sin_cercas);

        foreach ($candidatos as $candidato) {
            $decodificado = self::decodificar_si_sirve($candidato, $es_valido);

            if (! is_null($decodificado)) {
                return $decodificado;
            }
        }

        /* 3. Desde cada `{`: el objeto balanceado y, si no, hasta la última `}`. */
        $ultima = strrpos($texto, '}');
        $offset = 0;

        while (($inicio = strpos($texto, '{', $offset)) !== false) {
            $offset = $inicio + 1;

            $balanceado = self::objeto_balanceado($texto, $inicio);

            if (! is_null($balanceado)) {
                $decodificado = self::decodificar_si_sirve($balanceado, $es_valido);

                if (! is_null($decodificado)) {
                    return $decodificado;
                }
            }

            if ($ultima !== false && $ultima > $inicio) {
                $decodificado = self::decodificar_si_sirve(substr($texto, $inicio, $ultima - $inicio + 1), $es_valido);

                if (! is_null($decodificado)) {
                    return $decodificado;
                }
            }
        }

        return null;
    }

    /**
     * json_decode del candidato, solo si da un array y tiene la forma esperada.
     *
     * @param  string  $candidato
     * @param  callable|null  $es_valido
     * @return array|null
     */
    protected static function decodificar_si_sirve($candidato, $es_valido)
    {
        if ($candidato === '' || $candidato[0] !== '{') {
            return null;
        }

        $decodificado = json_decode($candidato, true);

        if (! is_array($decodificado)) {
            return null;
        }

        if (! is_null($es_valido) && ! call_user_func($es_valido, $decodificado)) {
            return null;
        }

        return $decodificado;
    }

    /**
     * El objeto `{...}` balanceado que abre la llave de la posición `$inicio`, o null si no cierra.
     * Las llaves adentro de strings JSON (con sus escapes) no cuentan. Trabaja por bytes: las llaves,
     * las comillas y la barra son ASCII y en UTF-8 ningún byte de un carácter multibyte coincide con
     * ellas, así que no parte caracteres.
     *
     * @param  string  $texto
     * @param  int  $inicio
     * @return string|null
     */
    protected static function objeto_balanceado($texto, $inicio)
    {
        $profundidad = 0;
        $en_string   = false;
        $escapado    = false;
        $largo       = strlen($texto);

        for ($i = $inicio; $i < $largo; $i++) {
            $caracter = $texto[$i];

            if ($en_string) {
                if ($escapado) {
                    $escapado = false;
                } elseif ($caracter === '\\') {
                    $escapado = true;
                } elseif ($caracter === '"') {
                    $en_string = false;
                }

                continue;
            }

            if ($caracter === '"') {
                $en_string = true;
            } elseif ($caracter === '{') {
                $profundidad++;
            } elseif ($caracter === '}') {
                $profundidad--;

                if ($profundidad === 0) {
                    return substr($texto, $inicio, $i - $inicio + 1);
                }
            }
        }

        return null;
    }

    /**
     * true si un error de la importación de Excel es TRANSITORIO ("el servicio no está disponible,
     * esperá unos segundos") y no un rechazo. Lo usan los tres Ai*Analyzer.
     *
     * 🔴 CON ANTHROPIC SE MANTIENE LA REGLA DE SIEMPRE DE LOS ANALIZADORES (tipo `overloaded_error` o
     * `api_error`, o HTTP 529), no la de ProveedorIaHelper::es_error_transitorio(), que además cuenta
     * 429/500/502/503. La diferencia está en el 429 (`rate_limit_error`): los analizadores siempre lo
     * contaron como "el servicio rechazó el pedido" y tests/Import/MensajesDeErrorTest lo fija así.
     * Con DeepSeek sí va ProveedorIaHelper::es_error_transitorio(): DeepSeek saturado contesta
     * 429/500/503 sin `overloaded_error` ni 529, y con la regla de Anthropic el usuario leería
     * "rechazó el pedido" para algo que se arregla esperando.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     * @param  string  $proveedor
     * @return bool
     */
    public static function error_transitorio_de_importacion($response, $proveedor): bool
    {
        if ((string) $proveedor === ProveedorIaHelper::DEEPSEEK) {
            return ProveedorIaHelper::es_error_transitorio($response);
        }

        $cuerpo = $response->json();
        $tipo   = is_array($cuerpo) && isset($cuerpo['error']['type']) ? (string) $cuerpo['error']['type'] : '';

        return in_array($tipo, ['overloaded_error', 'api_error'], true) || (int) $response->status() === 529;
    }

    /**
     * El dueño del comercio de un usuario: él mismo si no tiene `owner_id`, si no su dueño. La
     * configuración de modelos vive en la fila del dueño; los servicios reciben un user_id que casi
     * siempre ya es el del dueño, pero no cuesta nada asegurarlo.
     *
     * @param  int|null  $user_id
     * @return \App\Models\User|null
     */
    public static function dueno_de($user_id)
    {
        if (is_null($user_id) || (int) $user_id <= 0) {
            return null;
        }

        $user = User::find((int) $user_id);

        if (is_null($user)) {
            return null;
        }

        if (! empty($user->owner_id)) {
            $dueno = User::find((int) $user->owner_id);

            return is_null($dueno) ? $user : $dueno;
        }

        return $user;
    }

    /**
     * La opción del catálogo que corresponde a un par (proveedor, pensamiento) del asistente.
     *
     * @param  string  $proveedor
     * @param  string  $pensamiento
     * @return string
     */
    protected static function opcion_del_asistente($proveedor, $pensamiento): string
    {
        foreach (self::ASISTENTE_POR_OPCION as $opcion => $par) {
            if ($par[0] === (string) $proveedor && $par[1] === (string) $pensamiento) {
                return $opcion;
            }
        }

        /* No debería pasar (pensamiento_de() ya normaliza); el default de la tarea. */
        return self::DEFAULTS_POR_TAREA[self::TAREA_ASISTENTE];
    }

    /**
     * resolver() para el asistente: delega en ProveedorIaHelper (sin cambiar nada del chat del dueño)
     * y traduce su resultado a la forma común.
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $elegida
     * @param  bool  $necesita_vision
     * @return array|null
     */
    protected static function resolver_asistente($owner, $elegida, $necesita_vision)
    {
        $eleccion = ProveedorIaHelper::modelo_del_asistente($owner, (bool) $necesita_vision);

        /* modelo_del_asistente() devuelve el elegido cuando ninguno tiene clave: eso es "sin configurar". */
        if (! ProveedorIaHelper::hay_credenciales($eleccion['proveedor'])) {
            return null;
        }

        $efectiva = self::opcion_del_asistente($eleccion['proveedor'], $eleccion['pensamiento']);

        return [
            'tarea'           => self::TAREA_ASISTENTE,
            'opcion_elegida'  => $elegida,
            'opcion_efectiva' => $efectiva,
            'proveedor'       => $eleccion['proveedor'],
            'modelo'          => $eleccion['modelo'],
            'thinking'        => $eleccion['thinking'],
            'vision'          => $eleccion['proveedor'] === ProveedorIaHelper::ANTHROPIC || $necesita_vision || self::OPCIONES[$efectiva]['vision'],
            'fallback'        => $eleccion['proveedor'] !== ProveedorIaHelper::proveedor_elegido($owner),
        ];
    }

    /**
     * La resolución de una opción del catálogo que efectivamente se va a llamar, con la guarda de
     * visión de DeepSeek Pro.
     *
     * @param  string  $tarea
     * @param  string  $elegida
     * @param  string  $efectiva
     * @param  bool  $necesita_vision
     * @param  bool  $fallback
     * @return array
     */
    protected static function resolucion_de_opcion($tarea, $elegida, $efectiva, $necesita_vision, $fallback): array
    {
        $datos     = self::OPCIONES[$efectiva];
        $proveedor = $datos['proveedor'];
        $modelo    = self::modelo_de_opcion($efectiva);
        $vision    = (bool) $datos['vision'];

        if ($necesita_vision && ! $vision) {
            /*
             * 🔴 Pro no ve y DeepSeek no avisa: la foto viajaría y la respuesta la ignoraría. Ese
             * turno va al modelo con visión, como el asistente. El thinking sigue siendo el de la
             * opción elegida (abajo), porque Flash también razona.
             */
            $modelo = ProveedorIaHelper::modelo_de_vision_de_deepseek();
            $vision = true;
        }

        $thinking = null;

        if ($proveedor === ProveedorIaHelper::DEEPSEEK) {
            $thinking = ProveedorIaHelper::thinking_de_deepseek((bool) $datos['profundo']);
        }

        return [
            'tarea'           => (string) $tarea,
            'opcion_elegida'  => (string) $elegida,
            'opcion_efectiva' => (string) $efectiva,
            'proveedor'       => $proveedor,
            'modelo'          => $modelo,
            'thinking'        => $thinking,
            'vision'          => $vision,
            'fallback'        => (bool) $fallback,
        ];
    }

    /**
     * Warning del fallback de clave, uno por dueño y tarea por proceso PHP.
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $tarea
     * @param  string  $elegida
     * @param  string  $reemplazo  El proveedor al que se cae.
     * @return void
     */
    protected static function avisar_fallback($owner, $tarea, $elegida, $reemplazo)
    {
        $clave = (is_null($owner) ? 0 : (int) $owner->id) . ':' . $tarea;

        if (isset(self::$avisados[$clave])) {
            return;
        }

        self::$avisados[$clave] = true;

        Log::warning(
            'ModelosIaHelper: la tarea ' . $tarea . ' tiene elegido ' . $elegida . ' pero esta instalación no tiene '
            . 'la clave de ' . ProveedorIaHelper::nombre_de(self::proveedor_de_opcion($elegida)) . '; cae a '
            . ProveedorIaHelper::nombre_de($reemplazo) . '.',
            [
                'owner_id' => is_null($owner) ? null : $owner->id,
                'tarea'    => $tarea,
                'elegida'  => $elegida,
            ]
        );
    }

    /**
     * El mensaje de un 422 por opción inválida para una tarea.
     *
     * @param  string  $tarea
     * @param  mixed  $valor
     * @param  array  $validas
     * @return string
     */
    protected static function mensaje_de_opcion_invalida($tarea, $valor, array $validas): string
    {
        $mostrado = is_scalar($valor) ? (string) $valor : gettype($valor);

        if ($tarea === self::TAREA_IMAGENES && $mostrado === self::DEEPSEEK_PRO) {
            return 'DeepSeek Pro no ve imágenes: la verificación de imágenes necesita un modelo con visión. '
                 . 'Elegí una de: ' . implode(', ', $validas) . '.';
        }

        return 'La opción "' . $mostrado . '" no existe o no vale para ' . self::NOMBRES_DE_TAREA[$tarea]
             . '. Elegí una de: ' . implode(', ', $validas) . '.';
    }
}
