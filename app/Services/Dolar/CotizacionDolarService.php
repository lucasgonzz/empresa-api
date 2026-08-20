<?php

namespace App\Services\Dolar;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cotizaciones del dólar para el comercio (misión cotizacion-dolar).
 *
 * Sale a dolarapi.com, devuelve las tres casas que le importan al sistema (Oficial, Blue, MEP) y
 * mide la variación entre la referencia que el usuario eligió y la cotización de hoy.
 *
 * 🔴 LA REGLA QUE ORDENA TODO ESTE ARCHIVO: una medición que falla NUNCA devuelve un valor
 * tranquilizador. Ni un array vacío que se pueda leer como "no hay novedades", ni un 0% que se
 * pueda leer como "no varió". Cuando no se pudo medir, se dice que no se pudo medir, con el motivo
 * (APRENDER_NO_PARCHEAR.md).
 *
 * De ahí salen las tres decisiones que más se "simplifican" de vuelta:
 *  - `obtener()` devuelve SIEMPRE un array con `estado`, y con `error` cargado cuando estado no es
 *    'ok'. Nunca devuelve `[]` ni `null`.
 *  - Faltando UNA de las tres casas, el estado es 'proveedor_caido', NO un 'ok' con dos casas:
 *    devolver dos de tres haría que el usuario elija entre las que hay creyendo que son todas.
 *  - `comparar()` devuelve `null` cuando no se pudo medir, y jamás `variacion_porcentaje => 0`.
 *
 * 🔴 LA CACHÉ ES BEST-EFFORT, NUNCA UNA GARANTÍA. El sistema corre con CACHE_DRIVER=array (.env y
 * .env.testing), o sea que la caché vive solo mientras dura el proceso PHP y entre dos requests
 * HTTP no persiste nada. Ningún camino de código de acá puede depender de que la caché tenga algo.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class CotizacionDolarService
{
    /** Segundos de espera de la respuesta completa. */
    const TIMEOUT_SEGUNDOS = 8;

    /** Segundos de espera solo para abrir la conexión. */
    const CONNECT_TIMEOUT_SEGUNDOS = 3;

    /** Minutos que se guarda una respuesta EXITOSA. Los fallos NO se cachean. */
    const CACHE_MINUTOS = 10;

    /** Clave de caché. Global, no por usuario: la cotización no es de nadie. */
    const CACHE_KEY = 'dolar_cotizaciones_v1';

    /**
     * Mapeo clave interna -> casa de dolarapi.
     * 🔴 El MEP viene como 'bolsa'. La casa 'mep' NO EXISTE en esa API: verificado el 20/8/2026
     * contra GET https://dolarapi.com/v1/dolares, que devuelve siete casas (oficial, blue, bolsa,
     * contadoconliqui, mayorista, cripto, tarjeta) y ninguna llamada 'mep'. Un mapeo directo por
     * nombre dejaría el MEP afuera y el modal mostraría dos opciones donde se pidieron tres.
     */
    const CASAS = [
        'oficial' => 'oficial',
        'blue'    => 'blue',
        'mep'     => 'bolsa',
    ];

    /** Nombre que ve el usuario para cada clave interna. */
    const NOMBRES = [
        'oficial' => 'Oficial',
        'blue'    => 'Blue',
        'mep'     => 'MEP',
    ];

    /** Las dos puntas válidas de una casa. */
    const PUNTAS = ['compra', 'venta'];

    /**
     * Variación mínima que se usa cuando la del usuario no sirve (null o <= 0).
     * 🔴 Un 0 en base (fila vieja, import a mano) haría aparecer el modal en cada login ante el
     * movimiento más chico: se lo trata como 1.00, que es el default de la columna.
     */
    const VARIACION_MINIMA_POR_DEFECTO = 1.00;

    /**
     * Trae las tres cotizaciones. NUNCA devuelve un array vacío que se pueda leer como "no hay
     * novedades".
     *
     * @return array{estado:string, cotizaciones:array, error:array|null}
     *   estado: 'ok' | 'proveedor_caido'
     *   cotizaciones: [ ['clave','nombre','compra','venta','actualizada_at'], ... ]
     *                 SIEMPRE las tres claves cuando estado == 'ok'; [] cuando no.
     *   error: null cuando estado == 'ok'; ['motivo' => ..., 'mensaje' => ...] cuando no.
     */
    public static function obtener()
    {
        /*
         * Lectura best-effort de la caché: si el driver no guardó nada (que es lo que pasa entre
         * requests con CACHE_DRIVER=array), simplemente se sale a la API. Nada depende de esto.
         */
        $cacheada = Cache::get(self::CACHE_KEY);

        if (is_array($cacheada) && isset($cacheada['estado'])) {
            return $cacheada;
        }

        $resultado = self::consultar_al_proveedor();

        /*
         * 🔴 Solo se cachea el ÉXITO. Un hipo de red cacheado 10 minutos convierte un error de un
         * segundo en diez minutos de "no puedo consultar" para el que aprieta el botón.
         */
        if ($resultado['estado'] === 'ok') {
            Cache::put(self::CACHE_KEY, $resultado, Carbon::now()->addMinutes(self::CACHE_MINUTOS));
        }

        return $resultado;
    }

    /**
     * Mide la variación entre la referencia guardada y la cotización de hoy.
     *
     * @param  \App\Models\User $owner       Fila del DUEÑO del comercio.
     * @param  array            $cotizaciones Las del servicio, con estado 'ok'.
     * @return array|null  null cuando NO SE PUDO MEDIR. Nunca un 0 de consuelo.
     */
    public static function comparar($owner, array $cotizaciones)
    {
        if (is_null($owner)) {
            return null;
        }

        /* Nunca eligió nada. No es lo mismo que "eligió manual". */
        if (is_null($owner->dolar_cotizacion_origen) || $owner->dolar_cotizacion_origen === '') {
            return null;
        }

        $casa  = $owner->dolar_cotizacion_casa;
        $punta = $owner->dolar_cotizacion_punta;

        /* Manual cargado desde el formulario de perfil: hay valor, pero no hay contra qué medirlo. */
        if (is_null($casa) || is_null($punta) || !in_array($punta, self::PUNTAS, true)) {
            return null;
        }

        /* 🔴 Sin referencia positiva no hay división posible. Ver R11: nunca 0%, siempre null. */
        $valor_referencia = $owner->dolar_cotizacion_valor;

        if (is_null($valor_referencia) || (float) $valor_referencia <= 0) {
            return null;
        }

        $por_clave = self::por_clave($cotizaciones);

        /* La casa guardada no vino en esta respuesta: tampoco se pudo medir. */
        if (!isset($por_clave[$casa]) || !isset($por_clave[$casa][$punta])) {
            return null;
        }

        $valor_referencia = (float) $valor_referencia;
        $valor_nuevo      = (float) $por_clave[$casa][$punta];

        $variacion = round(($valor_nuevo - $valor_referencia) / $valor_referencia * 100, 2);

        /*
         * 🔴 El umbral filtra si se MUESTRA el modal, nunca borra la medición: `variacion_porcentaje`
         * sale siempre completo aunque `supera_umbral` sea false. Y compara el VALOR ABSOLUTO,
         * porque que el dólar baje un 3% le importa al comerciante tanto como que suba.
         */
        return [
            'casa'                 => $casa,
            'punta'                => $punta,
            'valor_referencia'     => $valor_referencia,
            'valor_nuevo'          => $valor_nuevo,
            'diferencia'           => round($valor_nuevo - $valor_referencia, 2),
            'variacion_porcentaje' => $variacion,
            'supera_umbral'        => abs($variacion) >= self::variacion_minima($owner),
        ];
    }

    /**
     * Indexa por clave interna la lista que devuelve `obtener()`, para poder pedir
     * `$por_clave['blue']['venta']` sin recorrer el array a mano en cada llamador.
     *
     * @param  array $cotizaciones
     * @return array  clave interna => item de cotización
     */
    public static function por_clave(array $cotizaciones)
    {
        $mapa = [];

        foreach ($cotizaciones as $cotizacion) {
            if (is_array($cotizacion) && isset($cotizacion['clave'])) {
                $mapa[$cotizacion['clave']] = $cotizacion;
            }
        }

        return $mapa;
    }

    /**
     * La variación mínima efectiva del usuario, ya saneada (ver R10 y la constante).
     *
     * @param  \App\Models\User $owner
     * @return float
     */
    public static function variacion_minima($owner)
    {
        if (is_null($owner) || is_null($owner->dolar_variacion_minima)) {
            return (float) self::VARIACION_MINIMA_POR_DEFECTO;
        }

        $minima = (float) $owner->dolar_variacion_minima;

        return $minima > 0 ? $minima : (float) self::VARIACION_MINIMA_POR_DEFECTO;
    }

    /**
     * El nombre que ve el usuario para una clave interna ('blue' -> 'Blue'). Devuelve la clave tal
     * cual si no la conoce, para no romper un texto por un dato inesperado.
     *
     * @param  string|null $clave
     * @return string
     */
    public static function nombre_de_casa($clave)
    {
        if (is_null($clave)) {
            return '';
        }

        return isset(self::NOMBRES[$clave]) ? self::NOMBRES[$clave] : (string) $clave;
    }

    /**
     * La llamada real al proveedor, sin caché de por medio.
     *
     * Sin reintentos a propósito: un retry(2) duplica el peor caso a 16 segundos y no cambia nada
     * contra un proveedor caído. Un solo intento, corto, y un error rápido que el usuario entiende.
     *
     * @return array
     */
    protected static function consultar_al_proveedor()
    {
        /*
         * 🔴 La URL se lee de config() y NUNCA con env() directo: con `config:cache` corrido en
         * producción, env() devuelve null y el servicio se apagaría sin avisar. Es el mismo pozo
         * que ya documenta el bloque `github_error_reporter` de config/services.php.
         */
        $url = (string) config('services.dolar_api.url');

        try {
            /*
             * `connect_timeout` va por withOptions() y no por un connectTimeout(): ese método no
             * existe en Laravel 8.83, que es la versión de este repo. Guzzle sí entiende la opción.
             * DNS caído o host inalcanzable falla en 3 segundos, no en 8.
             */
            $respuesta = Http::withHeaders(['Accept' => 'application/json'])
                ->withOptions(['connect_timeout' => self::CONNECT_TIMEOUT_SEGUNDOS])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('CotizacionDolarService: no se pudo conectar al proveedor. ' . $e->getMessage());

            return self::caido('timeout', 'No pudimos conectarnos al servicio de cotizaciones. Probá de nuevo en un rato.');
        } catch (\Exception $e) {
            /*
             * Cualquier otra excepción del transporte cae en el mismo cajón, y es honesto: no se
             * obtuvo respuesta del proveedor. El log lleva la clase para que el que investigue no
             * tenga que adivinar cuál de los dos caminos fue.
             */
            Log::warning('CotizacionDolarService: ' . get_class($e) . ' al consultar el proveedor. ' . $e->getMessage());

            return self::caido('timeout', 'No pudimos conectarnos al servicio de cotizaciones. Probá de nuevo en un rato.');
        }

        if ($respuesta->failed()) {
            $status = $respuesta->status();

            Log::warning('CotizacionDolarService: el proveedor respondió ' . $status . '.');

            return self::caido('http_error', 'El servicio de cotizaciones respondió con un error (' . $status . ').');
        }

        return self::interpretar($respuesta->json());
    }

    /**
     * Convierte el cuerpo crudo de dolarapi en las tres cotizaciones del sistema.
     *
     * @param  mixed $cuerpo  Lo que devolvió `$respuesta->json()` (null si no era JSON).
     * @return array
     */
    protected static function interpretar($cuerpo)
    {
        if (!is_array($cuerpo)) {
            Log::warning('CotizacionDolarService: el proveedor no devolvió un JSON interpretable.');

            return self::caido('payload_invalido', 'El servicio de cotizaciones devolvió datos que no pudimos interpretar.');
        }

        /* Índice casa de dolarapi => item, para no recorrer el array entero por cada casa. */
        $por_casa = [];

        foreach ($cuerpo as $item) {
            if (is_array($item) && isset($item['casa'])) {
                $por_casa[$item['casa']] = $item;
            }
        }

        $cotizaciones = [];
        $faltantes    = [];

        foreach (self::CASAS as $clave => $casa_api) {

            if (!isset($por_casa[$casa_api])) {
                $faltantes[] = self::nombre_de_casa($clave);
                continue;
            }

            $item = $por_casa[$casa_api];

            /*
             * 🔴 `compra` y `venta` numéricas o no hay cotización: un "1.540,00" con formato
             * argentino, un null o un texto darían 0 al castear, y un dólar en 0 recalcularía el
             * catálogo entero a precio cero.
             */
            if (!isset($item['compra']) || !isset($item['venta'])
                || !is_numeric($item['compra']) || !is_numeric($item['venta'])) {

                Log::warning('CotizacionDolarService: la casa ' . $casa_api . ' vino sin compra/venta numéricas.');

                return self::caido('payload_invalido', 'El servicio de cotizaciones devolvió datos que no pudimos interpretar.');
            }

            /*
             * 🔴 Redondeo a 2 decimales ACÁ, en el servicio, y no recién en el save(): `users.dollar`
             * es decimal(10,2), así que el número que el usuario ve en el modal ("Usar $1.523,60")
             * tiene que ser exactamente el que se guarda. Si el redondeo pasara después, el modal
             * prometería un número y la base guardaría otro (R9).
             *
             * Y OJO con el MEP: hoy viene con compra == venta (1523,6 y 1523,6). Las dos puntas se
             * devuelven tal cual, sin colapsarlas y sin asumir que compra < venta (R4).
             */
            $cotizaciones[] = [
                'clave'          => $clave,
                'nombre'         => self::nombre_de_casa($clave),
                'compra'         => round((float) $item['compra'], 2),
                'venta'          => round((float) $item['venta'], 2),
                'actualizada_at' => self::fecha_local(isset($item['fechaActualizacion']) ? $item['fechaActualizacion'] : null),
            ];
        }

        /*
         * 🔴 FALTANDO UNA CASA, EL ESTADO ES 'proveedor_caido' Y LAS COTIZACIONES VAN VACÍAS.
         * Devolver dos de tres es la versión sutil de la medición que falla y devuelve un valor
         * tranquilizador: el usuario elegiría entre las que hay creyendo que son todas.
         */
        if (count($faltantes) > 0) {
            Log::warning('CotizacionDolarService: el proveedor no devolvió ' . implode(', ', $faltantes) . '.');

            return self::caido(
                'casas_faltantes',
                'El servicio de cotizaciones no devolvió el dólar ' . implode(', ', $faltantes) . '.'
            );
        }

        return [
            'estado'       => 'ok',
            'cotizaciones' => $cotizaciones,
            'error'        => null,
        ];
    }

    /**
     * Pasa la `fechaActualizacion` de dolarapi (UTC, con Z) a la hora de Buenos Aires y la formatea
     * como el resto del sistema. 🔴 Nunca se muestra la cruda: config/app.php está en
     * America/Argentina/Buenos_Aires y la API responde en UTC, así que serían tres horas de más (R5).
     *
     * @param  mixed $cruda
     * @return string|null  null si no vino o no se pudo interpretar; nunca una fecha inventada.
     */
    protected static function fecha_local($cruda)
    {
        if (!is_string($cruda) || $cruda === '') {
            return null;
        }

        try {
            return Carbon::parse($cruda)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning('CotizacionDolarService: fechaActualizacion ilegible (' . $cruda . ').');

            return null;
        }
    }

    /**
     * Arma la respuesta de proveedor caído. Existe para que las cuatro salidas de error tengan
     * exactamente la misma forma y ninguna se pueda confundir con un 'ok' vacío.
     *
     * @param  string $motivo  timeout | http_error | payload_invalido | casas_faltantes
     * @param  string $mensaje Texto que ve el usuario.
     * @return array
     */
    protected static function caido($motivo, $mensaje)
    {
        return [
            'estado'       => 'proveedor_caido',
            'cotizaciones' => [],
            'error'        => [
                'motivo'  => $motivo,
                'mensaje' => $mensaje,
            ],
        ];
    }
}
