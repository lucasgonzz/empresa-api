<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiTokenUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El consumo de IA de un negocio contra el tope de su plan (misión
 * foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * Lo usan el footer del chat (GET api/mi-consumo-ia) y el corte que va ANTES de despachar el job de
 * respuesta (AiConversationController::send_message y AdminSync\AsistenteController::mensajes): si el
 * negocio ya superó su tope, no se gasta una llamada a la API y se le contesta con el texto de
 * límite.
 *
 * 🔴 SIN TOPE NUNCA CORTA. Un tope null o 0 se lee como "sin límite": es la guarda de
 * compatibilidad de las dos direcciones del contrato con el admin. Un cliente al que nadie le
 * pusheó un plan (o un admin viejo que no pushea) queda con los dos topes en null y el agente
 * responde como hoy. El corte solo se activa con un tope > 0 recibido del admin y guardado en el
 * dueño.
 *
 * 🔴 UNA INTERACCIÓN ES UNA RESPUESTA DEL AGENTE, NO UNA LLAMADA A LA API (misión
 * agente-memoria-y-sin-techo, 30/10/2026; hasta ahí se contaban las filas `chat_mensaje` de
 * ai_token_usages). Cada mensaje del chat graba una fila por VUELTA del loop de tool use, así que un
 * solo pedido contaba hasta 8 interacciones. El caso real: Pablo (Ferre Total, plan «Agentes Light»,
 * tope de 100 por día) leyó el 29/10 a las 23:51 «Llegaste al límite de tu plan de este mes» con 106
 * filas `chat_mensaje` de ese día —muchas menos respuestas: cada pedido sumaba una fila por vuelta—, y
 * al otro día le andaba (el que había pasado era el DIARIO, y el texto decía «de este mes»). Con el techo de vueltas
 * en 24 (AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES) el tope de 100 se comería en 4 o 5
 * pedidos. Ahora interacciones_del_dia() cuenta las respuestas del agente de hoy (ver ahí qué entra y
 * qué no). El admin solo manda el NÚMERO del tope (ClientAiPlanSyncService): qué es una interacción lo
 * decide este helper, sin cambio de contrato.
 *
 * 🔴 Y SE CUENTAN SOBRE ai_token_usages, NO SOBRE ai_messages (ronda de arreglos de la revisión
 * independiente, R3, 30/10/2026). La primera versión contaba los `ai_messages` del asistente, y
 * AiConversationController::destroy borra la conversación con sus mensajes EN DURO: borrar una charla
 * le devolvía al dueño las interacciones del día. Ahora cada vuelta del loop graba su fila
 * `chat_mensaje` con `referencia_id` = el mensaje que contesta (AsistenteIaService), y una interacción
 * es un `referencia_id` distinto de hoy: las filas de consumo no se borran con la conversación.
 * (Verificado antes de cambiarlo: el admin no lee `referencia_id` de ai_token_usages —ConsumoIaController
 * le manda totales agrupados por día, proceso, proveedor, modelo y persona— y la única que graba
 * `chat_mensaje` es la respuesta a la persona en el loop del chat.)
 *
 * LA TRANSCRIPCIÓN DE FOTOS SUMA TOKENS PERO NO INTERACCIONES (misión asistente-deepseek-pro-razona,
 * 24/9/2026). Un turno de DeepSeek que razona en Pro con fotos hace antes una llamada al modelo con
 * visión que las transcribe, y la graba con el proceso `chat_transcripcion_foto`
 * (TranscripcionDeFotosIaHelper::PROCESO). No es una interacción por construcción: es otro proceso, y
 * las interacciones son las filas `chat_mensaje` (una llamada más del mismo turno, que tiene UNA
 * respuesta). Pero
 * tokens_del_mes() suma TODAS las filas del dueño sin filtrar por proceso, así que esos tokens sí
 * cuentan contra el tope MENSUAL: se pagaron por su turno. No hay que tocar nada acá para que eso pase;
 * si alguien filtra tokens_del_mes() por proceso, tiene que dejar adentro este.
 *
 * Los tokens se cuentan sobre el MES CALENDARIO y las interacciones sobre el DÍA, las dos ventanas
 * en la zona de la app (America/Argentina/Buenos_Aires, config/app.php). El defecto de plataforma
 * conocido de un cliente migrado del shared (UTC) al VPS (-03) —el mismo que documenta
 * ConsumoIaController— corre estas ventanas unas horas en las filas viejas, y no es algo que este
 * helper pueda arreglar sin tocar config/database.php.
 *
 * 🔴 EL TOPE MENSUAL PUEDE SER DE PLATA (misión plan-ia-por-gasto, 2/11/2026). Pedido de Lucas: «el
 * límite no sería en sí la cantidad de tokens usados, sino la plata gastada de esos tokens». Medido en
 * Ferre Total: 11,3 millones de tokens de caché de DeepSeek Flash cuestan 7 centavos y 217 mil de salida
 * de Sonnet 5, US$ 2,17, y contra un tope de tokens los primeros pesan cincuenta veces más. Si el admin
 * mandó un tope de gasto, MANDA EL GASTO: el tope de tokens no corta ni avisa, y el negocio corta por
 * gasto o por interacciones del día. Sin tope de gasto, todo funciona exactamente como antes.
 *
 * 🔴 LOS DÓLARES NO SALEN DE ESTE PROYECTO. `consumo_usd` y `tope_usd` son de uso interno: el dueño ve un
 * porcentaje (porcentaje_del_plan()), y las columnas del plan en dólares están en el `$hidden` de User.
 *
 * LA TABLA DE PRECIOS LA MANDA EL ADMIN, junto con el plan (`users.plan_ia_precios`): sigue viviendo
 * SOLO en su config/ia_precios.php, y cuando cambia la reenvía a todos con `ia:sincronizar-precios`.
 * El gasto se calcula al leer y no se guarda, igual que allá.
 *
 * ⚠️ El gasto es una COTA SUPERIOR de lo que se pagó, por la misma razón que en el admin: DeepSeek cobra la
 * mitad fuera de su horario pico y la tabla trae su precio pleno. Para un tope sirve: corta antes de lo
 * justo y nunca después.
 */
class TopeDeTokensHelper
{
    /** Fracción de un tope a partir de la cual se avisa que está cerca (estilo Claude). */
    const UMBRAL_CERCA = 0.8;

    /**
     * El proceso de `ai_token_usages` de cada vuelta del loop del chat (AsistenteIaService). Desde la
     * misión agente-memoria-y-sin-techo una FILA ya no es una interacción: lo es cada `referencia_id`
     * distinto de estas filas (el mensaje que contesta), ver interacciones_del_dia().
     */
    const PROCESO_CHAT = 'chat_mensaje';

    /**
     * Lo que se le contesta al dueño cuando superó el tope MENSUAL de tokens de su plan. Va como el
     * contenido del assistant, que nace 'listo' sin despachar el job (ver el corte en
     * AiConversationController y AdminSync\AsistenteController): un negocio pasado de tope no gasta una
     * llamada a la API.
     *
     * Desde la misión agente-memoria-y-sin-techo los llamadores no lo usan directo: piden
     * mensaje_limite(), que elige entre este y MENSAJE_LIMITE_DIARIO. Queda como constante por
     * compatibilidad (código o tests que lo lean).
     */
    const MENSAJE_LIMITE = 'Llegaste al límite de tu plan de este mes. Escribinos para ampliarlo.';

    /**
     * Lo que se le contesta al dueño cuando superó el tope DIARIO de interacciones (misión
     * agente-memoria-y-sin-techo, 30/10/2026). Antes salía MENSAJE_LIMITE para los dos topes, y Pablo
     * (Ferre Total) leyó «de este mes» por el tope de HOY: al otro día le andaba y el texto le había
     * mentido. Este dice cuál tope es y que mañana se renueva.
     */
    const MENSAJE_LIMITE_DIARIO = 'Llegaste al límite de mensajes de hoy de tu plan. Mañana se renueva; si necesitás más, escribinos para ampliarlo.';

    /** Divisor de la tabla de precios: sus valores son en dólares por MILLÓN de tokens. */
    const TOKENS_POR_UNIDAD_DE_PRECIO = 1000000;

    /**
     * Las cuatro puntas que se cobran por separado, de la columna de `ai_token_usages` a la clave de la
     * tarifa. Las claves son el contrato con el admin (`precios_ia`, config/ia_precios.php): un renombre de
     * un lado deja al otro leyendo cero sin un solo error.
     *
     * @var array<string, string>
     */
    const PUNTAS = [
        'input_tokens'                => 'input',
        'output_tokens'               => 'output',
        'cache_creation_input_tokens' => 'cache_write',
        'cache_read_input_tokens'     => 'cache_read',
    ];

    /**
     * Cómo nombra la tabla de precios a los modelos de cada proveedor: de la columna `proveedor` de
     * `ai_token_usages` a los prefijos de config/ia_precios.php del admin. Sirve para cobrarle a un modelo sin
     * tarifa la más cara DE SU PROVEEDOR (ver tarifa_de_reemplazo()). La columna es confiable: los llamadores de
     * AiTokenUsageHelper::registrar() que no la mandan solo usan Anthropic, y ese es su default.
     *
     * @var array<string, array<int, string>>
     */
    const PREFIJOS_POR_PROVEEDOR = [
        'anthropic' => ['claude'],
        'deepseek'  => ['deepseek'],
        'openai'    => ['gpt', 'text-embedding'],
    ];

    /**
     * Lo que ya se avisó al log en este proceso: un modelo sin tarifa cae en cada mensaje del dueño y un
     * worker vive días; sin esto el warning taparía el log.
     *
     * @var array<string, bool>
     */
    protected static $ya_avisado = [];

    /**
     * El estado de consumo del dueño contra los topes de su plan.
     *
     * Misión agente-memoria-y-sin-techo: suma (sin sacar ni cambiar ninguna) `supero_tokens` y
     * `supero_interacciones`, para que mensaje_limite() diga cuál de los dos topes se pasó. `supero`
     * sigue siendo el O de los dos, como siempre.
     *
     * Misión plan-ia-por-gasto (ver el 🔴 del docblock de la clase): suma `consumo_usd`, `tope_usd`,
     * `supero_gasto` y `cerca_del_mes`. Con tope de gasto, `tope_tokens` sale null porque ya no rige:
     * `supero_tokens` queda en false y `supero` es el O de gasto e interacciones. Sin tope de gasto,
     * `consumo_usd` y `tope_usd` son null, no se hace ninguna consulta de más y lo demás es lo de siempre.
     * 🔴 `consumo_usd` y `tope_usd` no se le muestran al dueño.
     *
     * @param  \App\Models\User|null  $owner
     * @return array{consumo_tokens:int, consumo_interacciones:int, consumo_usd:float|null, tope_tokens:int|null, tope_interacciones:int|null, tope_usd:float|null, supero:bool, cerca:bool, cerca_del_mes:bool, supero_tokens:bool, supero_interacciones:bool, supero_gasto:bool}
     */
    public static function estado($owner)
    {
        $tope_usd = self::tope_de_gasto_vigente($owner);

        /* Un tope de gasto reemplaza al de tokens: es el único mensual que rige. */
        $tope_tokens = is_null($tope_usd)
            ? self::tope_valido(is_null($owner) ? null : $owner->plan_ia_tope_tokens_mensual)
            : null;
        $tope_interacciones = self::tope_valido(is_null($owner) ? null : $owner->plan_ia_tope_interacciones_diarias);

        $owner_id = is_null($owner) ? 0 : (int) $owner->id;

        $consumo_tokens = $owner_id > 0 ? self::tokens_del_mes($owner_id) : 0;
        $consumo_interacciones = $owner_id > 0 ? self::interacciones_del_dia($owner_id) : 0;
        $consumo_usd = is_null($tope_usd) ? null : self::gasto_del_mes($owner);

        $supero_tokens = self::supera($consumo_tokens, $tope_tokens);
        $supero_interacciones = self::supera($consumo_interacciones, $tope_interacciones);
        $supero_gasto = self::supera($consumo_usd, $tope_usd);

        $cerca_del_mes = self::cerca($consumo_tokens, $tope_tokens) || self::cerca($consumo_usd, $tope_usd);

        return [
            'consumo_tokens'        => $consumo_tokens,
            'consumo_interacciones' => $consumo_interacciones,
            'consumo_usd'           => $consumo_usd,
            'tope_tokens'           => $tope_tokens,
            'tope_interacciones'    => $tope_interacciones,
            'tope_usd'              => $tope_usd,
            'supero'                => $supero_tokens || $supero_gasto || $supero_interacciones,
            'cerca'                 => $cerca_del_mes || self::cerca($consumo_interacciones, $tope_interacciones),
            'cerca_del_mes'         => $cerca_del_mes,
            'supero_tokens'         => $supero_tokens,
            'supero_interacciones'  => $supero_interacciones,
            'supero_gasto'          => $supero_gasto,
        ];
    }

    /**
     * Cuánto del plan del mes lleva gastado el negocio, para mostrárselo al dueño sin decirle plata: un
     * entero de 0 a 100, o null si el plan no tiene tope de gasto (ahí el SPA muestra los tokens, como
     * siempre).
     *
     * Va para abajo: un 99,6 % dice 99, porque decir 100 % con el agente todavía contestando es mentira. El
     * round de adentro saca el ruido de los decimales (0,29 / 1 * 100 da 28,999999…).
     *
     * @param  array  $estado  Lo que devolvió estado().
     * @return int|null
     */
    public static function porcentaje_del_plan(array $estado)
    {
        if (empty($estado['tope_usd'])) {

            return null;
        }

        $porcentaje = (int) floor(round((float) $estado['consumo_usd'] / (float) $estado['tope_usd'] * 100, 6));

        return min(max($porcentaje, 0), 100);
    }

    /**
     * Cuántos dólares lleva gastados el dueño en el mes calendario, a la tarifa de `plan_ia_precios`:
     * agrupa `ai_token_usages` por modelo y multiplica las cuatro puntas.
     *
     * Suma TODOS los procesos, igual que tokens_del_mes() y por la misma razón (ver el docblock de la
     * clase): el chat, las fotos, el escaneo de facturas, las importaciones. Las búsquedas de imágenes no
     * van por tokens y no están en esta tabla.
     *
     * La consulta va por el índice (user_id, created_at) de ai_token_usages: un dueño, un mes, y agrupa en
     * la base y no en PHP. No se cachea: un corte con un número viejo deja pasar lo que ya no debe.
     *
     * 🔴 UN MODELO SIN TARIFA NUNCA ES GRATIS: se cobra a la más cara DE SU PROVEEDOR (tarifa_de_reemplazo()) y
     * queda un warning en el log. Es lo contrario de lo que hace el admin en su pantalla, que lo deja afuera y
     * lo dice, y está bien que difieran: allá un total inventado engaña a quien lo lee, y acá un modelo nuevo
     * que no cuente deja a un negocio gastar sin techo.
     *
     * @param  \App\Models\User|null  $owner
     * @return float  Dólares, con seis decimales para comparar sin ruido.
     */
    public static function gasto_del_mes($owner)
    {
        $owner_id = is_null($owner) ? 0 : (int) $owner->id;

        if ($owner_id <= 0) {

            return 0.0;
        }

        /* `proveedor` va en el agrupado porque de él sale la tarifa de un modelo que no está en la tabla. */
        $seleccion = 'modelo, proveedor';

        foreach (array_keys(self::PUNTAS) as $columna) {
            $seleccion .= ', COALESCE(SUM(' . $columna . '), 0) as ' . $columna;
        }

        $filas = AiTokenUsage::where('user_id', $owner_id)
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->groupBy('modelo', 'proveedor')
            ->selectRaw($seleccion)
            ->get();

        $precios = self::precios_de($owner);
        $gasto = 0.0;

        foreach ($filas as $fila) {
            $gasto += self::costo_de_la_fila($fila, $precios);
        }

        return round($gasto, 6);
    }

    /**
     * El costo en dólares de una fila agrupada por modelo. Una fila sin tokens (una llamada que volvió sin
     * `usage` se graba en cero, ver AiTokenUsageHelper) no cuesta nada, tenga tarifa o no, y no avisa.
     *
     * @param  \App\Models\AiTokenUsage  $fila
     * @param  array  $precios
     * @return float
     */
    protected static function costo_de_la_fila($fila, array $precios)
    {
        $tokens = 0;

        foreach (array_keys(self::PUNTAS) as $columna) {
            $tokens += (int) $fila->{$columna};
        }

        if ($tokens === 0) {

            return 0.0;
        }

        $tarifa = self::tarifa_de($fila->modelo, $precios);

        if (is_null($tarifa)) {
            $tarifa = self::tarifa_de_reemplazo($fila, $precios);
            self::avisar_una_vez('modelo:' . (string) $fila->modelo, 'TopeDeTokensHelper: modelo sin tarifa en plan_ia_precios, se cobra a la más cara de su proveedor.', [
                'modelo'    => (string) $fila->modelo,
                'proveedor' => (string) $fila->proveedor,
            ]);
        }

        $costo = 0.0;

        foreach (self::PUNTAS as $columna => $punta) {
            $precio = isset($tarifa[$punta]) ? (float) $tarifa[$punta] : 0.0;

            $costo += ((float) $fila->{$columna} * $precio) / self::TOKENS_POR_UNIDAD_DE_PRECIO;
        }

        return $costo;
    }

    /**
     * La tarifa de un modelo, o null si no hay ninguna. Mismo criterio que
     * ClientAiTokenUsage::tarifa_de() del admin, a propósito: que las dos puntas costeen igual.
     *
     * Primero el nombre EXACTO; si no, el PREFIJO MÁS LARGO que corte en un guion. Este proyecto guarda el
     * modelo que devuelve la API, y la API devuelve el id resuelto: el día que `claude-sonnet-4-5` responda
     * `claude-sonnet-4-5-20250929` tiene que seguir costeándose como tal. La frontera del guion es lo que
     * evita que un `claude-haiku-4-55` tome la tarifa de `claude-haiku-4-5`.
     *
     * @param  string|null  $modelo
     * @param  array  $precios  Lo que devolvió precios_de().
     * @return array|null
     */
    protected static function tarifa_de($modelo, array $precios)
    {
        $clave = trim((string) $modelo);

        if ($clave === '') {

            return null;
        }

        if (isset($precios[$clave])) {

            return $precios[$clave];
        }

        $mejor_tarifa = null;
        $mejor_largo = 0;

        foreach ($precios as $nombre => $tarifa) {
            $nombre = (string) $nombre;
            $largo = strlen($nombre);

            if ($largo <= $mejor_largo || strlen($clave) <= $largo) {
                continue;
            }

            if (substr($clave, 0, $largo) === $nombre && substr($clave, $largo, 1) === '-') {
                $mejor_tarifa = $tarifa;
                $mejor_largo = $largo;
            }
        }

        return $mejor_tarifa;
    }

    /**
     * La tarifa con la que se cobra un modelo que no está en la tabla: la más cara de SU proveedor, y si la
     * tabla no tiene ninguno, o la de su proveedor no le sirve, la más cara de todas.
     *
     * 🔴 POR QUÉ NO LA MÁS CARA DE TODA LA TABLA (revisión del contrato, 2/11/2026). DEEPSEEK_MODEL permite un id
     * que la tabla no tenga, y a precio de Opus 5 el volumen de Ferre Total (614 mil de entrada, 27 mil de
     * salida, 11,3 millones de caché) daba US$ 9,40 en vez de US$ 0,28: con un tope de US$ 5 el negocio se
     * quedaba sin agente y el único rastro era un warning.
     *
     * 🔴 Y LA DE SU PROVEEDOR TIENE QUE COBRAR LO QUE LA FILA USÓ. El único modelo de OpenAI de la tabla es un
     * embedding, con la salida en cero: a esa tarifa, un `gpt-*` desconocido saldría gratis (hoy es el caso de
     * `gpt-4o-mini-transcribe`, que la tabla no trae). Se mira solo entrada y salida: que DeepSeek no cobre la
     * escritura en caché es su precio real, no una tarifa que no sirve.
     *
     * @param  \App\Models\AiTokenUsage  $fila
     * @param  array  $precios  Lo que devolvió precios_de().
     * @return array
     */
    protected static function tarifa_de_reemplazo($fila, array $precios)
    {
        $tarifa = self::tarifa_mas_cara(self::precios_del_proveedor($fila->proveedor, $precios));

        foreach (['input_tokens' => 'input', 'output_tokens' => 'output'] as $columna => $punta) {
            $precio = isset($tarifa[$punta]) ? (float) $tarifa[$punta] : 0.0;

            if ((int) $fila->{$columna} > 0 && $precio <= 0) {

                return self::tarifa_mas_cara($precios);
            }
        }

        return empty($tarifa) ? self::tarifa_mas_cara($precios) : $tarifa;
    }

    /**
     * Las filas de la tabla que son de un proveedor, por el prefijo del nombre. El prefijo corta en un guion,
     * igual que en tarifa_de(): `claude-opus-5` es de Claude y `claudette-x` no.
     *
     * @param  string|null  $proveedor  La columna `proveedor` de ai_token_usages.
     * @param  array  $precios  Lo que devolvió precios_de().
     * @return array<string, array>
     */
    protected static function precios_del_proveedor($proveedor, array $precios)
    {
        $proveedor = strtolower(trim((string) $proveedor));

        if (! isset(self::PREFIJOS_POR_PROVEEDOR[$proveedor])) {

            return [];
        }

        $del_proveedor = [];

        foreach ($precios as $nombre => $tarifa) {
            foreach (self::PREFIJOS_POR_PROVEEDOR[$proveedor] as $prefijo) {
                if (strpos((string) $nombre, $prefijo . '-') === 0) {
                    $del_proveedor[$nombre] = $tarifa;
                }
            }
        }

        return $del_proveedor;
    }

    /**
     * La tarifa más cara de una lista: la de mayor suma de las cuatro puntas. Se compara la fila entera y no
     * punta por punta para no armar una tarifa que ningún modelo tiene.
     *
     * @param  array  $precios  Si es la tabla entera y un tope de gasto rige, no está vacía
     *                          (tope_de_gasto_vigente()). Una lista vacía devuelve [].
     * @return array
     */
    protected static function tarifa_mas_cara(array $precios)
    {
        $mas_cara = [];
        $mayor_suma = -1.0;

        foreach ($precios as $tarifa) {
            $suma = 0.0;

            foreach (self::PUNTAS as $punta) {
                $suma += isset($tarifa[$punta]) ? (float) $tarifa[$punta] : 0.0;
            }

            if ($suma > $mayor_suma) {
                $mayor_suma = $suma;
                $mas_cara = $tarifa;
            }
        }

        return $mas_cara;
    }

    /**
     * La tabla de precios guardada en el dueño, solo con las filas que son tarifas. [] si no hay o si el
     * JSON está roto.
     *
     * @param  \App\Models\User|null  $owner
     * @return array<string, array>
     */
    protected static function precios_de($owner)
    {
        $precios = is_null($owner) ? null : $owner->plan_ia_precios;

        if (is_string($precios)) {
            $precios = json_decode($precios, true);
        }

        return is_array($precios) ? array_filter($precios, 'is_array') : [];
    }

    /**
     * El tope de gasto que rige para el dueño, en dólares, o null si manda el de tokens.
     *
     * Null o 0 es "sin tope de gasto", y una columna que todavía no existe se lee igual: un deploy sube los
     * archivos antes de migrar, y Eloquent devuelve null para un atributo que la fila no trae.
     *
     * 🔴 UN TOPE SIN TABLA DE PRECIOS NO RIGE. Sin precios no hay forma de medir plata ni tarifa con la que
     * cobrar un modelo desconocido: cobrar cero lo dejaría sin techo, y cortar a ciegas dejaría sin agente a
     * un negocio que pagó. Queda con el tope de tokens, que es lo que tenía, y con un warning en el log. No
     * debería pasar: el admin manda las dos claves juntas.
     *
     * @param  \App\Models\User|null  $owner
     * @return float|null
     */
    protected static function tope_de_gasto_vigente($owner)
    {
        $tope = is_null($owner) ? 0.0 : (float) $owner->plan_ia_tope_gasto_usd_mensual;

        if ($tope <= 0) {

            return null;
        }

        if (empty(self::precios_de($owner))) {
            self::avisar_una_vez('sin_precios:' . (int) $owner->id, 'TopeDeTokensHelper: hay tope de gasto pero no hay tabla de precios, sigue mandando el tope de tokens.', [
                'owner_id' => (int) $owner->id,
            ]);

            return null;
        }

        return $tope;
    }

    /**
     * Un warning en el log, una sola vez por proceso para cada clave.
     *
     * @param  string  $clave
     * @param  string  $mensaje
     * @param  array  $contexto
     * @return void
     */
    protected static function avisar_una_vez($clave, $mensaje, array $contexto)
    {
        if (isset(self::$ya_avisado[$clave])) {

            return;
        }

        self::$ya_avisado[$clave] = true;

        Log::warning($mensaje, $contexto);
    }

    /**
     * El texto de límite que corresponde al estado (misión agente-memoria-y-sin-techo, 30/10/2026): el
     * del tope mensual de tokens si se pasó ése —mañana no se arregla—, y si no el del tope diario de
     * interacciones. Con un estado viejo, sin las claves nuevas, o sin ningún tope pasado, el mensual de
     * siempre (es lo que contestaba el corte antes de esta misión).
     *
     * Misión plan-ia-por-gasto: el de gasto también es mensual y dice lo mismo, sin hablar de plata.
     *
     * @param  array  $estado  Lo que devolvió estado().
     * @return string
     */
    public static function mensaje_limite(array $estado)
    {
        $supero_tokens = ! empty($estado['supero_tokens']);
        $supero_interacciones = ! empty($estado['supero_interacciones']);
        $supero_gasto = ! empty($estado['supero_gasto']);

        if ($supero_interacciones && ! $supero_tokens && ! $supero_gasto) {

            return self::MENSAJE_LIMITE_DIARIO;
        }

        return self::MENSAJE_LIMITE;
    }

    /**
     * Un tope guardado en el dueño, normalizado: null o 0 (o negativo) se leen como "sin tope".
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected static function tope_valido($valor)
    {
        $valor = (int) $valor;

        return $valor > 0 ? $valor : null;
    }

    /**
     * La suma de los cuatro contadores de `ai_token_usages` del dueño en el mes calendario actual.
     *
     * @param  int  $owner_id
     * @return int
     */
    protected static function tokens_del_mes($owner_id)
    {
        $total = AiTokenUsage::where('user_id', $owner_id)
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens + cache_creation_input_tokens + cache_read_input_tokens), 0) as total')
            ->value('total');

        return (int) $total;
    }

    /**
     * Cuántas respuestas le dio hoy el agente al negocio (ver los 🔴 del docblock): los mensajes DISTINTOS
     * (`referencia_id`) de las filas `chat_mensaje` de hoy del dueño (`ai_token_usages.user_id`, que junta
     * las del dueño y las de sus empleados), más UNA por cada fila de hoy sin `referencia_id` (las que el
     * loop grabó antes de la ronda de arreglos R3: sin el mensaje, cada vuelta cuenta como antes).
     *
     * 🔴 POR QUÉ ACÁ Y NO EN ai_messages. Las filas de consumo no se borran con la conversación
     * (AiConversationController::destroy borra la conversación y sus mensajes en duro): contando mensajes,
     * borrar una charla le devolvía al dueño las interacciones del día.
     *
     * Qué NO cuenta, por construcción (solo el loop del chat graba `chat_mensaje`, y solo corre para
     * contestarle a la persona: lo despachan send_message y AdminSync\AsistenteController::mensajes):
     * - las conversaciones de origen 'mcp': el servidor MCP corre las herramientas sin el loop;
     * - las respuestas con un texto de límite (MENSAJE_LIMITE, MENSAJE_LIMITE_DIARIO): nacen `listo` sin
     *   despachar el job, no llaman a la IA;
     * - los mensajes que escribe el sistema solo (el recordatorio de una tarea por WhatsApp, los avisos
     *   de AvisosDelAsistenteHelper, el resumen con el que nace la conversación de una sugerencia, el
     *   cierre de las imágenes de categorías): no pasan por el loop;
     * - las otras llamadas del turno con su propio proceso (la transcripción de fotos, el título, el hilo).
     *
     * ⚠️ Una respuesta cuenta desde su PRIMERA llamada a la IA (su primera fila), no desde que se crea:
     * un mensaje `pendiente` que todavía no volvió de la primera vuelta no cuenta, y uno cuya primera
     * llamada falla tampoco (no gastó). El corte se mide antes de crear la respuesta, igual que antes.
     *
     * La consulta va por el índice (user_id, created_at) de ai_token_usages
     * (ai_token_usages_user_created_index): un dueño, un día. Es el filtro con índice que pidió la
     * revisión (R4) en lugar del join de ai_messages con su subconsulta por mensaje.
     *
     * @param  int  $owner_id
     * @return int
     */
    protected static function interacciones_del_dia($owner_id)
    {
        $fila = AiTokenUsage::where('user_id', (int) $owner_id)
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->where('proceso', self::PROCESO_CHAT)
            ->selectRaw('COUNT(DISTINCT referencia_id) as respuestas, COALESCE(SUM(CASE WHEN referencia_id IS NULL THEN 1 ELSE 0 END), 0) as sin_referencia')
            ->first();

        if (is_null($fila)) {

            return 0;
        }

        return (int) $fila->respuestas + (int) $fila->sin_referencia;
    }

    /**
     * true si hay tope definido y el consumo lo alcanzó o pasó.
     *
     * @param  int|float|null  $consumo
     * @param  int|float|null  $tope
     * @return bool
     */
    protected static function supera($consumo, $tope)
    {
        return !is_null($tope) && $consumo >= $tope;
    }

    /**
     * true si hay tope definido y el consumo llegó al umbral de aviso (>= 80%).
     *
     * @param  int|float|null  $consumo
     * @param  int|float|null  $tope
     * @return bool
     */
    protected static function cerca($consumo, $tope)
    {
        return !is_null($tope) && $consumo >= ($tope * self::UMBRAL_CERCA);
    }
}
