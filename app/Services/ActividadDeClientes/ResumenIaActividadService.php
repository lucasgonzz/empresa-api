<?php

namespace App\Services\ActividadDeClientes;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use Illuminate\Support\Facades\Http;

/**
 * La lectura en criollo de lo que un cliente hizo en la tienda online: el párrafo que va ARRIBA de
 * todo en el modal de actividad. Molde exacto de ResumenIaOfertasService.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 LA IA NO RECALCULA NADA: LEE LO QUE YA SE CALCULÓ
 * -------------------------------------------------------------------------------------------
 *
 * Todo lo que viaja en el prompt sale de ActividadDeClientesService, que ya leyó la tabla que
 * corresponde según la antigüedad pedida y ya resolvió los totales. Acá la IA no tiene ninguna
 * fórmula con qué inventar un número: recibe los contadores hechos y lo único que hace es contarlos
 * en dos o tres oraciones y decir de qué le conviene hablarle al cliente. Y el prompt se lo dice
 * textual ("no inventes ni recalcules ningún número"), porque un número inventado en esta pantalla
 * no se ve raro: se ve plausible, y el comerciante sale a llamar al cliente con él.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 ES SINCRÓNICA A PROPÓSITO, Y NO UN JOB
 * -------------------------------------------------------------------------------------------
 *
 * Molde: WhatsappChatController::summary() — un endpoint que llama a Anthropic desde el request y
 * devuelve el texto sin persistir nada. El modal no puede quedar esperando a un worker: se abre, se
 * lee y se cierra. Lo único que queda escrito de esta llamada es la fila de `ai_token_usages`.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 armar_datos() Y armar_prompt() VAN SEPARADOS
 * -------------------------------------------------------------------------------------------
 *
 * Igual que en ofertas (ResumenIaOfertasService:20-23). Acá el bloque de datos no se guarda en
 * `ai_conversations.contexto`, pero la separación se mantiene por el mismo motivo de fondo: los
 * DATOS tienen que poder viajar SIN la instrucción de redacción pegada, para que el día que alguien
 * quiera charlar sobre ellos no herede además la orden de escribir un párrafo de cinco oraciones.
 * Hay un test que lo blinda: armar_datos() no puede contener ninguna de las palabras con las que se
 * redacta.
 *
 * -------------------------------------------------------------------------------------------
 * QUIÉN PAGA Y QUIÉN APRETÓ EL BOTÓN
 * -------------------------------------------------------------------------------------------
 *
 * El consumo se registra SIEMPRE, con un `proceso` propio ('actividad_cliente') distinto de los que
 * ya existen: el gasto tiene otro dueño y hay que poder distinguirlo al mirar los números. `user_id`
 * es el comercio y `auth_user_id` es el empleado que apretó el botón (mismo criterio y mismo motivo
 * que WhatsappChatController::suggest():620-625). AiTokenUsageHelper::registrar() nunca lanza, así
 * que no hace falta envolverla: una falla de contabilidad no puede voltear un resumen que el
 * operador ya está esperando en pantalla.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class ResumenIaActividadService
{
    /**
     * Techo de tokens de la respuesta. Más bajo que el de ofertas (2500) porque acá la IA devuelve
     * UN PÁRRAFO, no una decisión por línea.
     */
    const MAX_TOKENS = 700;

    /** Timeout de la llamada HTTP a Anthropic, en segundos. */
    const TIMEOUT_SEGUNDOS = 60;

    /** Proceso con el que se imputa el gasto en `ai_token_usages`. */
    const PROCESO = 'actividad_cliente';

    /**
     * El mensaje de los errores TRANSITORIOS de la API (overloaded_error, api_error, HTTP 529).
     *
     * Es una constante y no un string suelto porque el controller lo compara para decidir si el
     * 422 lleva este texto o uno genérico: un error de red no es lo mismo que un error de código, y
     * al comerciante hay que decirle "volvé a intentar" en vez de mostrarle el cuerpo crudo de una
     * respuesta HTTP. Mismo texto y mismo criterio que ResumenIaOfertasService:191-195 y
     * AsistenteIaService:142-146.
     */
    const MENSAJE_IA_NO_DISPONIBLE = 'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.';

    /**
     * true si hay clave de Anthropic configurada. No tenerla no es un error del sistema: es una
     * cuenta sin la IA contratada, y el endpoint contesta un 422 amigable SIN salir a la red.
     *
     * @return bool
     */
    public function hay_credenciales(): bool
    {
        return (string) config('services.anthropic.api_key') !== '';
    }

    /**
     * El bloque de DATOS ya calculados, sin una sola instrucción de redacción adentro.
     *
     * 🔴 Acá no va ni una regla de estilo, ni un "escribí", ni un "máximo N oraciones". Todo eso
     * vive en armar_prompt(), que envuelve a éste. Hay un test que recorre este texto buscando las
     * palabras de la redacción justamente para que la separación no se pierda con el tiempo.
     *
     * @param  array $actividad El bloque `actividad` que devuelve ActividadDeClientesService
     * @param  mixed $nombre    Nombre del cliente o del comprador, para que la IA lo pueda nombrar
     * @return string
     */
    public function armar_datos(array $actividad, $nombre): string
    {
        $totales = (isset($actividad['totales']) && is_array($actividad['totales'])) ? $actividad['totales'] : [];

        $filas = [];

        $filas[] = 'Cliente: ' . $this->nombre_informado($nombre);
        $filas[] = 'Ventana: ' . $this->describir_ventana($actividad);
        $filas[] = 'De donde salen los numeros: ' . $this->describir_fuente($actividad);
        $filas[] = 'Compradores de la tienda que se estan sumando: ' . $this->describir_compradores($actividad);
        $filas[] = 'Numeros ya calculados:';
        $filas[] = '- Vistas de articulos: ' . $this->entero($totales, 'vistas');
        $filas[] = '- Articulos distintos que miro: ' . $this->entero($totales, 'articulos_distintos');
        $filas[] = '- Tiempo total mirando articulos: ' . $this->entero($totales, 'tiempo_total_segundos') . ' segundos';
        $filas[] = '- Busquedas: ' . $this->entero($totales, 'busquedas')
            . ' (de esas, ' . $this->entero($totales, 'busquedas_sin_resultado') . ' no devolvieron ni un solo articulo)';
        $filas[] = '- Agrego al carrito: ' . $this->entero($totales, 'agregados_al_carrito') . ' veces';
        $filas[] = '- Saco del carrito: ' . $this->entero($totales, 'quitados_del_carrito') . ' veces';
        $filas[] = '- Empezo a comprar sin cerrar: ' . $this->entero($totales, 'checkouts_empezados') . ' veces';
        $filas[] = '- Compras cerradas: ' . $this->entero($totales, 'compras')
            . ' por un total de ' . $this->formatear_cantidad(isset($totales['monto_comprado']) ? $totales['monto_comprado'] : 0);
        $filas[] = '- Ultima señal de actividad: ' . $this->texto_o_guion(isset($totales['ultima_actividad']) ? $totales['ultima_actividad'] : null);

        $filas[] = 'Articulos que miro:';
        $filas   = array_merge($filas, $this->filas_de_articulos($actividad));

        $filas[] = 'Lo que busco en la tienda:';
        $filas   = array_merge($filas, $this->filas_de_busquedas($actividad));

        return implode("\n", $filas);
    }

    /**
     * Rol + los datos ya calculados + las reglas de redacción. Los datos vienen de armar_datos();
     * acá solo se les pone las instrucciones alrededor, nunca al revés.
     *
     * @param  array $actividad El bloque `actividad` que devuelve ActividadDeClientesService
     * @param  mixed $nombre    Nombre del cliente o del comprador
     * @return string
     */
    public function armar_prompt(array $actividad, $nombre): string
    {
        $reglas = [
            '- Escribi en espanol rioplatense, tuteando, con tono cercano y directo.',
            '- Maximo 5 oraciones, texto corrido, sin markdown, sin listas, sin guiones de lista, sin saludar ni presentarte.',
            '- No inventes ni recalcules ningun numero: usa los que estan en los datos y nada mas.',
            '- Si busco algo y no encontro nada, decilo: es lo mas accionable que hay, porque significa que hay demanda y no hay oferta.',
            '- Si miro mucho un articulo y no lo compro, decilo.',
            '- Cerra diciendo de que le conviene hablarle o que le conviene ofrecerle.',
        ];

        /*
         * La aclaración del histórico va SOLO cuando los datos son del agregado: pedirle que aclare
         * "esto llega hasta ayer y no tiene detalle por hora" cuando está leyendo los eventos crudos
         * sería hacerle escribir una salvedad falsa.
         */
        if ($this->es_agregado($actividad)) {
            $reglas[] = '- Estos datos son del historial completo, donde solo esta el resumen por dia hasta ayer y no hay detalle por hora: aclaraselo al comerciante.';
        }

        return "Sos el que le explica al comerciante que estuvo haciendo este cliente en su tienda online.\n\n"
            . $this->armar_datos($actividad, $nombre) . "\n\n"
            . "Reglas:\n"
            . implode("\n", $reglas) . "\n\n"
            . 'Responde SOLO con el texto del parrafo, sin ningun titulo ni texto alrededor.';
    }

    /**
     * Le pide el párrafo a Anthropic y devuelve el texto pelado. Modelo desde config, nunca
     * hardcodeado.
     *
     * 🔴 El registro del consumo va en CADA llamada exitosa, con `proceso` propio. registrar() nunca
     * lanza (lo garantiza su docblock), así que no hace falta envolverla en un try.
     *
     * @param  string   $prompt
     * @param  int|null $user_id      Dueño del comercio: el que paga
     * @param  int|null $auth_user_id Empleado que apretó el botón; null si no hay persona
     * @return string
     * @throws \RuntimeException Si no hay clave, si la llamada falla o si vuelve sin texto
     */
    public function pedir_resumen(string $prompt, $user_id = null, $auth_user_id = null): string
    {
        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            throw new \RuntimeException('La clave ANTHROPIC_API_KEY no está configurada en el entorno.');
        }

        $response = $this->build_anthropic_http_client($api_key)->post('https://api.anthropic.com/v1/messages', [
            'model'      => (string) config('services.anthropic.model'),
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        if (!$response->successful()) {
            /*
             * Mismo manejo de errores transitorios que ResumenIaOfertasService::pedir_resumen():
             * overloaded_error / api_error / HTTP 529 son "volvé a intentar en un rato" y no "algo
             * está roto", y por eso llevan un mensaje que el comerciante puede entender.
             */
            $error_body = $response->json();
            $error_type = isset($error_body['error']['type']) ? $error_body['error']['type'] : null;

            if (in_array($error_type, ['overloaded_error', 'api_error']) || $response->status() === 529) {
                throw new \RuntimeException(self::MENSAJE_IA_NO_DISPONIBLE);
            }

            throw new \RuntimeException(
                'Error al comunicarse con Claude API (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $response_data = $response->json();

        if (!is_null($user_id)) {
            AiTokenUsageHelper::registrar([
                'user_id'      => $user_id,
                'auth_user_id' => $auth_user_id,
                'proceso'      => self::PROCESO,
                'modelo'       => (string) config('services.anthropic.model'),
                'body'         => is_array($response_data) ? $response_data : [],
            ]);
        }

        $text = isset($response_data['content'][0]['text']) ? $response_data['content'][0]['text'] : null;

        if (is_null($text)) {
            throw new \RuntimeException('Claude devolvió una respuesta sin contenido de texto.');
        }

        return trim($text);
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS (verify_ssl / ca_bundle). Copia del de ofertas:
     * el `ca_bundle` existe porque en Windows el PHP de WAMP no trae el bundle de certificados y sin
     * él la llamada falla con un error de TLS que no dice nada.
     *
     * @param  string $api_key
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key)
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(self::TIMEOUT_SEGUNDOS);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * @param  array $actividad
     * @return bool true si el bloque vino del agregado diario
     */
    protected function es_agregado(array $actividad)
    {
        $fuente = isset($actividad['fuente']) ? (string) $actividad['fuente'] : '';

        return $fuente === ActividadDeClientesService::FUENTE_AGREGADO;
    }

    /**
     * La ventana en criollo. Con el histórico se dice hasta cuándo llega DE VERDAD (ayer), que es
     * justo lo que el agregado puede contestar.
     *
     * @param  array $actividad
     * @return string
     */
    protected function describir_ventana(array $actividad)
    {
        $desde = $this->texto_o_guion(isset($actividad['desde']) ? $actividad['desde'] : null);
        $hasta = $this->texto_o_guion(isset($actividad['hasta']) ? $actividad['hasta'] : null);

        if ($this->es_agregado($actividad)) {
            return 'todo el historial, del ' . $desde . ' al ' . $hasta;
        }

        $periodo = isset($actividad['periodo']) ? (int) $actividad['periodo'] : 0;

        return 'ultimos ' . $periodo . ' dias, del ' . $desde . ' al ' . $hasta;
    }

    /**
     * @param  array $actividad
     * @return string
     */
    protected function describir_fuente(array $actividad)
    {
        if ($this->es_agregado($actividad)) {
            return 'el resumen por dia, que llega hasta ayer y no guarda la hora de cada movimiento';
        }

        return 'los movimientos con fecha y hora, tal como los mando la tienda';
    }

    /**
     * @param  array $actividad
     * @return string
     */
    protected function describir_compradores(array $actividad)
    {
        $compradores = (isset($actividad['compradores']) && is_array($actividad['compradores']))
            ? $actividad['compradores']
            : [];

        $nombres = [];

        foreach ($compradores as $comprador) {
            $nombre = isset($comprador['nombre']) ? trim((string) $comprador['nombre']) : '';

            if ($nombre !== '') {
                $nombres[] = $nombre;
            }
        }

        if (empty($nombres)) {
            return 'ninguno';
        }

        return implode(', ', $nombres);
    }

    /**
     * @param  array $actividad
     * @return array<int, string>
     */
    protected function filas_de_articulos(array $actividad)
    {
        $articulos = (isset($actividad['articulos']) && is_array($actividad['articulos']))
            ? $actividad['articulos']
            : [];

        if (empty($articulos)) {
            return ['- (no miro ningun articulo en esta ventana)'];
        }

        $filas = [];

        foreach ($articulos as $articulo) {
            $filas[] = '- ' . $this->texto_o_guion(isset($articulo['nombre']) ? $articulo['nombre'] : null)
                . ': ' . (isset($articulo['vistas']) ? (int) $articulo['vistas'] : 0) . ' vistas'
                . ', ' . (isset($articulo['tiempo_segundos']) ? (int) $articulo['tiempo_segundos'] : 0) . ' segundos mirandolo'
                . ', ' . (isset($articulo['agregados_al_carrito']) ? (int) $articulo['agregados_al_carrito'] : 0) . ' veces al carrito'
                . ', ultima vez ' . $this->texto_o_guion(isset($articulo['ultima_vez']) ? $articulo['ultima_vez'] : null);
        }

        return $filas;
    }

    /**
     * 🔴 "Buscó y no encontró nada" se dice con todas las letras y no como un `0` suelto: es el dato
     * más accionable de toda la pantalla y un número pelado en el prompt no lo deja ver. Un
     * `resultados` en null es "la tienda no lo informó" y NO es lo mismo que cero.
     *
     * @param  array $actividad
     * @return array<int, string>
     */
    protected function filas_de_busquedas(array $actividad)
    {
        $busquedas = (isset($actividad['busquedas']) && is_array($actividad['busquedas']))
            ? $actividad['busquedas']
            : [];

        if (empty($busquedas)) {
            return ['- (no busco nada en esta ventana)'];
        }

        $filas = [];

        foreach ($busquedas as $busqueda) {
            $resultados = isset($busqueda['resultados']) ? $busqueda['resultados'] : null;

            if (isset($busqueda['sin_resultado']) && $busqueda['sin_resultado']) {
                $detalle = 'NO ENCONTRO NINGUN ARTICULO';
            } elseif (is_null($resultados)) {
                $detalle = 'la tienda no informo cuantos articulos le aparecieron';
            } else {
                $detalle = 'le aparecieron ' . (int) $resultados . ' articulos';
            }

            $filas[] = '- "' . $this->texto_o_guion(isset($busqueda['termino']) ? $busqueda['termino'] : null) . '"'
                . ': lo busco ' . (isset($busqueda['veces']) ? (int) $busqueda['veces'] : 0) . ' veces'
                . ', ' . $detalle
                . ', ultima vez ' . $this->texto_o_guion(isset($busqueda['ultima_vez']) ? $busqueda['ultima_vez'] : null);
        }

        return $filas;
    }

    /**
     * @param  mixed $nombre
     * @return string
     */
    protected function nombre_informado($nombre)
    {
        $limpio = trim((string) $nombre);

        return $limpio === '' ? 'sin nombre cargado' : $limpio;
    }

    /**
     * @param  array  $totales
     * @param  string $clave
     * @return int
     */
    protected function entero(array $totales, $clave)
    {
        return isset($totales[$clave]) ? (int) $totales[$clave] : 0;
    }

    /**
     * @param  mixed $valor
     * @return string
     */
    protected function texto_o_guion($valor)
    {
        if (is_null($valor)) {
            return '-';
        }

        $texto = trim((string) $valor);

        return $texto === '' ? '-' : $texto;
    }

    /**
     * Números sin decimales de relleno (16 y no 16.00). Copia del de ofertas.
     *
     * @param  mixed $valor
     * @return string
     */
    protected function formatear_cantidad($valor)
    {
        $float = (float) $valor;

        if ($float == (int) $float) {
            return (string) (int) $float;
        }

        return rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.');
    }
}
