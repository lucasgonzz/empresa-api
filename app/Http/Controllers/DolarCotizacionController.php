<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\DolarCotizacionRegistro;
use App\Services\Dolar\CotizacionDolarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cotización del dólar del comercio (misión cotizacion-dolar).
 *
 *   GET  api/dolar-cotizacion               -> las tres casas de hoy + la selección guardada + la variación
 *   POST api/dolar-cotizacion               -> elegir una cotización (y disparar el recálculo si cambió)
 *   PUT  api/dolar-cotizacion/preferencias  -> solo el "avisarme cuando cambie" y su umbral
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL GET DEVUELVE 200 AUNQUE EL PROVEEDOR ESTÉ CAÍDO, Y ES A PROPÓSITO
 * -------------------------------------------------------------------------------------------
 *
 * `src/main.js:152` de la SPA tiene un interceptor global: ante CUALQUIER 4xx/5xx con la sesión
 * iniciada abre el modal de error global. Un 503 en el chequeo del login le abriría a todo el mundo
 * un cartel de error genérico por un servicio de terceros que se cayó.
 *
 * Que dolarapi no conteste NO es un error de este endpoint: es EL RESULTADO DE LA MEDICIÓN, y este
 * endpoint lo reporta fielmente en el campo `estado`, con el motivo adentro de `error`. El estado
 * queda explícito y distinguible, que es lo que pide APRENDER_NO_PARCHEAR.md, sin abrir carteles
 * ajenos.
 *
 * Los errores que SÍ son de este endpoint mantienen su código: 401 sin sesión, 403 sin la extensión
 * o sin admin_access, 422 payload inválido, 409 en el POST cuando no se puede guardar.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 TODA ESCRITURA VA A LA FILA DEL OWNER. SIEMPRE.
 * -------------------------------------------------------------------------------------------
 *
 * `UserHelper::user()` con el default resuelve el owner_id cuando el autenticado es un empleado
 * (UserHelper.php:14-27). La cotización es del COMERCIO, no de la persona: si un empleado con
 * admin_access la guardara en su propia fila, el costeo —que lee la del dueño (ArticleHelper:650,
 * ArticlePriceTypeMonedaHelper:106, ProviderOrderHelper:327)— seguiría usando el dólar viejo y
 * nadie se enteraría. `$auth_user` se usa SOLO para dejar asentado quién lo hizo en el historial.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 PROHIBIDO LLAMAR A UserController@update DESDE ACÁ PARA "UNIFICAR"
 * -------------------------------------------------------------------------------------------
 *
 * `check_actualizar_articulos()` despacharía una SEGUNDA corrida por el mismo cambio, y desde el
 * 11/8/2026 cada disparo abre su propia `price_update_run` con su propia notificación (ver el
 * docblock de PriceUpdateRunHelper::abrir). El usuario vería dos avisos con números parciales.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class DolarCotizacionController extends Controller
{
    /** Mensaje del 403 por rol. La extensión la corta el middleware; esto corta al empleado raso. */
    const MENSAJE_SIN_PERMISO = 'No tenés acceso a esta funcionalidad.';

    /** Disparo que se asume cuando el POST no lo aclara. */
    const DISPARO_POR_DEFECTO = 'configuracion';

    /**
     * Las tres casas de hoy, la selección guardada del comercio y la variación entre una y otra.
     *
     * 🔴 LECTURA PURA: no escribe una sola columna ni crea una sola fila de historial. Un test lo
     * asevera comparando la foto de las siete columnas antes y después.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        if (!$this->is_admin()) {
            return response()->json(['message' => self::MENSAJE_SIN_PERMISO], 403);
        }

        /** @var \App\Models\User $owner Fila del DUEÑO. Es la única que se lee y la única que se escribe. */
        $owner = UserHelper::user();

        $resultado = CotizacionDolarService::obtener();

        /*
         * 🔴 La comparación solo se intenta con estado 'ok'. Con el proveedor caído `comparacion`
         * va en null y el motivo viaja en `error`: nunca un `variacion_porcentaje: 0`, que el front
         * leería como "tu cotización está al día" cuando en realidad no se midió nada.
         */
        $comparacion = $resultado['estado'] === 'ok'
            ? CotizacionDolarService::comparar($owner, $resultado['cotizaciones'])
            : null;

        return response()->json([
            'estado'             => $resultado['estado'],
            'cotizaciones'       => $resultado['cotizaciones'],
            /* Dato local: sale igual aunque el proveedor esté caído. */
            'seleccion_actual'   => $this->seleccion_actual($owner),
            'valor_dolar_actual' => is_null($owner->dollar) ? null : (float) $owner->dollar,
            'avisar_cambios'     => (bool) $owner->dolar_avisar_cambios,
            'variacion_minima'   => CotizacionDolarService::variacion_minima($owner),
            'comparacion'        => $comparacion,
            'error'              => $resultado['error'],
        ], 200);
    }

    /**
     * Guarda la cotización elegida en la fila del dueño, deja el rastro en el historial y encola el
     * recálculo de precios si el valor efectivamente cambió.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        if (!$this->is_admin()) {
            return response()->json(['message' => self::MENSAJE_SIN_PERMISO], 403);
        }

        $request->validate([
            'origen'           => 'required|in:blue,oficial,mep,manual',
            'casa'             => 'required|in:blue,oficial,mep',
            'punta'            => 'required|in:compra,venta',
            'valor_manual'     => 'required_if:origen,manual|nullable|numeric|min:0.01|max:99999999.99',
            'avisar_cambios'   => 'required|boolean',
            'variacion_minima' => 'required|numeric|min:0.01|max:100',
            'disparo'          => 'nullable|in:login,configuracion',
        ]);

        $origen = $request->origen;
        $casa   = $request->casa;
        $punta  = $request->punta;

        $es_manual = $origen === 'manual';

        /*
         * Con un origen preestablecido, la casa de referencia ES el origen: comparar "el blue" contra
         * "el oficial" no significa nada y dejaría al modal midiendo una variación que el usuario
         * nunca pidió. Va como 422 con el formato de Laravel, que el interceptor de la SPA ya
         * sabe mostrar.
         */
        if (!$es_manual && $casa !== $origen) {
            throw ValidationException::withMessages([
                'casa' => ['Con un origen preestablecido, la casa de referencia tiene que ser la misma que el origen.'],
            ]);
        }

        /** @var \App\Models\User $owner Fila del DUEÑO. La única que se escribe. */
        $owner = UserHelper::user();
        /** @var \App\Models\User $auth_user Quién está operando (el dueño, o un empleado con admin_access). */
        $auth_user = UserHelper::user(false);

        $resultado = CotizacionDolarService::obtener();

        /*
         * 🔴 Proveedor caído + origen preestablecido = 409 y NO SE ESCRIBE NADA. No se puede guardar
         * "el blue venta" sin saber cuánto vale: quedaría una selección que apunta a un número que
         * nadie midió.
         */
        if ($resultado['estado'] !== 'ok' && !$es_manual) {
            return response()->json([
                'estado' => $resultado['estado'],
                'error'  => $resultado['error'],
            ], 409);
        }

        $por_clave = CotizacionDolarService::por_clave($resultado['cotizaciones']);

        $notifications = [];

        if ($resultado['estado'] !== 'ok') {
            /*
             * Manual con el proveedor caído: se guarda el valor, pero SIN referencia. Y se dice, no
             * en silencio: sin referencia no hay comparación posible, así que el usuario dejaría de
             * recibir avisos sin haberlo pedido.
             */
            $valor            = round((float) $request->valor_manual, 2);
            $casa_guardada    = null;
            $punta_guardada   = null;
            $valor_cotizacion = null;

            $notifications[] = [
                'message' => 'Guardamos tu cotización, pero no pudimos guardar la referencia para avisarte de cambios. Probá de nuevo más tarde.',
                'type'    => 'warning',
            ];
        } elseif ($es_manual) {
            $valor            = round((float) $request->valor_manual, 2);
            $casa_guardada    = $casa;
            $punta_guardada   = $punta;
            $valor_cotizacion = isset($por_clave[$casa][$punta]) ? (float) $por_clave[$casa][$punta] : null;
        } else {
            /*
             * 🔴 SE IGNORA `valor_manual` AUNQUE VENGA. Con un origen preestablecido el número sale
             * de la API y de ningún otro lado: un front viejo o un request armado a mano no puede
             * meter un dólar arbitrario en el costeo de todo el catálogo.
             */
            $valor            = isset($por_clave[$origen][$punta]) ? (float) $por_clave[$origen][$punta] : null;
            $casa_guardada    = $origen;
            $punta_guardada   = $punta;
            $valor_cotizacion = $valor;
        }

        /*
         * Cinturón: con estado 'ok' el servicio garantiza las tres casas, así que esto no debería
         * pasar nunca. Si pasa, es un 409 explícito y no un dólar en null escrito en la base.
         */
        if (is_null($valor)) {
            return response()->json([
                'estado' => 'proveedor_caido',
                'error'  => [
                    'motivo'  => 'casas_faltantes',
                    'mensaje' => 'El servicio de cotizaciones no devolvió la cotización que elegiste.',
                ],
            ], 409);
        }

        $dolar_anterior = $owner->dollar;

        $owner->dollar                          = $valor;
        $owner->dolar_cotizacion_origen         = $origen;
        $owner->dolar_cotizacion_casa           = $casa_guardada;
        $owner->dolar_cotizacion_punta          = $punta_guardada;
        $owner->dolar_cotizacion_valor          = $valor_cotizacion;
        $owner->dolar_cotizacion_actualizada_at = Carbon::now();
        $owner->dolar_avisar_cambios            = $request->boolean('avisar_cambios') ? 1 : 0;
        $owner->dolar_variacion_minima          = round((float) $request->variacion_minima, 2);
        $owner->save();

        /*
         * 🔴 La fila de historial se escribe SIEMPRE, incluso cuando el valor no cambió: el usuario
         * reconfirmó la misma cotización, y eso es información. `price_update_runs` no sirve para
         * esto justamente porque solo nace cuando hubo recálculo.
         */
        DolarCotizacionRegistro::create([
            'user_id'              => $owner->id,
            'auth_user_id'         => is_null($auth_user) ? null : $auth_user->id,
            'origen'               => $origen,
            'casa'                 => $casa_guardada,
            'punta'                => $punta_guardada,
            'valor_dolar'          => $valor,
            'valor_cotizacion'     => $valor_cotizacion,
            'valor_dolar_anterior' => $dolar_anterior,
            'variacion_porcentaje' => $this->variacion_contra_anterior($dolar_anterior, $valor),
            'disparo'              => is_null($request->disparo) ? self::DISPARO_POR_DEFECTO : $request->disparo,
        ]);

        $recalculo_encolado = $this->encolar_recalculo($owner, $dolar_anterior, $casa_guardada, $punta_guardada);

        if ($recalculo_encolado) {
            $notifications[] = [
                'message' => 'Cotización actualizada. Se están recalculando los precios.',
                'type'    => 'success',
            ];
        } else {
            $notifications[] = [
                'message' => 'Cotización actualizada.',
                'type'    => 'success',
            ];
        }

        return response()->json([
            'ok'                 => true,
            'dollar'             => (float) $owner->dollar,
            'seleccion_actual'   => $this->seleccion_actual($owner),
            'recalculo_encolado' => $recalculo_encolado,
            'notifications'      => $notifications,
        ], 200);
    }

    /**
     * Cambia SOLO el "avisarme cuando cambie" y su umbral.
     *
     * Existe como endpoint aparte porque el caso "el usuario dijo *ahora no* pero apagó el aviso" es
     * exactamente eso: cambiar preferencias sin cambiar el dólar. Meterlo en el POST obligaría a que
     * "no cambiar el dólar" fuera un caso especial del endpoint que cambia el dólar.
     *
     * 🔴 No toca `users.dollar`, no despacha ningún job y no escribe historial.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preferencias(Request $request)
    {
        if (!$this->is_admin()) {
            return response()->json(['message' => self::MENSAJE_SIN_PERMISO], 403);
        }

        $request->validate([
            'avisar_cambios'   => 'required|boolean',
            'variacion_minima' => 'required|numeric|min:0.01|max:100',
        ]);

        /** @var \App\Models\User $owner Fila del DUEÑO. */
        $owner = UserHelper::user();

        $owner->dolar_avisar_cambios   = $request->boolean('avisar_cambios') ? 1 : 0;
        $owner->dolar_variacion_minima = round((float) $request->variacion_minima, 2);
        $owner->save();

        return response()->json([
            'ok'               => true,
            'avisar_cambios'   => (bool) $owner->dolar_avisar_cambios,
            'variacion_minima' => (float) $owner->dolar_variacion_minima,
        ], 200);
    }

    /**
     * La selección guardada del comercio, tal como la muestra el modal.
     *
     * Devuelve SIEMPRE el objeto, con `origen` en null cuando el comercio nunca eligió nada: un
     * `seleccion_actual: null` obligaría al front a distinguir "no vino" de "no eligió", que son la
     * misma cosa escrita de dos formas.
     *
     * @param  \App\Models\User $owner
     * @return array
     */
    protected function seleccion_actual($owner)
    {
        return [
            'origen'         => $owner->dolar_cotizacion_origen,
            'casa'           => $owner->dolar_cotizacion_casa,
            'punta'          => $owner->dolar_cotizacion_punta,
            'valor'          => is_null($owner->dolar_cotizacion_valor) ? null : (float) $owner->dolar_cotizacion_valor,
            'actualizada_at' => $this->fecha($owner->dolar_cotizacion_actualizada_at),
        ];
    }

    /**
     * Formatea una fecha de la base al formato del resto del sistema, venga como string de MySQL o
     * como Carbon recién asignado. Devuelve null si no hay nada: nunca una fecha inventada.
     *
     * @param  mixed $valor
     * @return string|null
     */
    protected function fecha($valor)
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($valor)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Variación porcentual del dólar nuevo contra el que había antes.
     *
     * 🔴 null cuando no había anterior o era 0: sin base positiva no hay porcentaje, y un 0 se
     * leería como "no varió" cuando lo cierto es que no se pudo medir.
     *
     * @param  mixed $anterior
     * @param  float $nuevo
     * @return float|null
     */
    protected function variacion_contra_anterior($anterior, $nuevo)
    {
        if (is_null($anterior) || (float) $anterior <= 0) {
            return null;
        }

        $anterior = (float) $anterior;

        return round(($nuevo - $anterior) / $anterior * 100, 2);
    }

    /**
     * Encola el recálculo de precios si el valor del dólar efectivamente cambió.
     *
     * 🔴 Si NO cambió no se despacha nada: recalcular el catálogo entero por una reconfirmación le
     * mandaría al comerciante una notificación de "se actualizaron tus precios" sin que se le haya
     * movido un precio.
     *
     * `$from_dolar = true` hace que el job filtre por `cost_in_dollars = 1` o
     * `price_type_monedas.cotizar_desde_otra_moneda = 1` (ProcessSetFinalPrices.php:70-80), que es
     * el subconjunto correcto. `'dolar'` es un valor que `price_update_runs.origen` ya usa: no se
     * inventa uno nuevo. Y `$origen_detalle` es texto para que lo lea una persona, igual que en el
     * resto del repo.
     *
     * @param  \App\Models\User $owner
     * @param  mixed            $dolar_anterior
     * @param  string|null      $casa
     * @param  string|null      $punta
     * @return bool
     */
    protected function encolar_recalculo($owner, $dolar_anterior, $casa, $punta)
    {
        if ((float) $dolar_anterior === (float) $owner->dollar) {
            return false;
        }

        $desde = number_format((float) $dolar_anterior, 2, ',', '.');
        $hasta = number_format((float) $owner->dollar, 2, ',', '.');

        if (is_null($casa) || is_null($punta)) {
            $detalle = 'Cotización cargada a mano: de ' . $desde . ' a ' . $hasta;
        } else {
            $detalle = 'Cotización ' . CotizacionDolarService::nombre_de_casa($casa) . ' (' . $punta . '): de '
                     . $desde . ' a ' . $hasta;
        }

        /* $owner->id explícito y no UserHelper::userId(): devuelve lo mismo, pero acá conviene que
         * el destinatario del recálculo no dependa del contexto de sesión. */
        ProcessSetFinalPrices::dispatch($owner->id, null, null, true, 'dolar', $detalle);

        return true;
    }
}
