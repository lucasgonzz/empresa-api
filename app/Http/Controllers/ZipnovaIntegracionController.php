<?php

namespace App\Http\Controllers;

use App\Services\Zipnova\ZipnovaConexionException;
use App\Services\Zipnova\ZipnovaConexionService;
use App\Services\Zipnova\ZipnovaException;
use Illuminate\Http\Request;

/**
 * Endpoints de la tarjeta de Zipnova en ABM -> Integraciones -> Tienda online (misión
 * zipnova-envios, 14/9/2026): conectar con API Token + API Secret, desconectar, guardar las
 * preferencias, refrescar los depósitos y cotizar de prueba. La lógica vive en
 * `App\Services\Zipnova\ZipnovaConexionService`; acá solo se traducen las excepciones a códigos
 * HTTP y se responde la tarjeta actualizada.
 *
 * Todos responden `{integracion}` con la MISMA forma que un item de `GET /api/integraciones`
 * (`IntegracionesController::integracion_zipnova()`), así la SPA pisa la tarjeta con la
 * respuesta y no vuelve a pedir el listado.
 *
 * Códigos:
 *  - 422 `{message}`: credenciales rechazadas por Zipnova, sin cuenta, no conectado, depósito
 *    inexistente, validación de entrada. Es "corregí algo y volvé a probar".
 *  - 502 `{message}`: Zipnova no respondió o respondió 5xx. Es "probá en un rato".
 *
 * Acá sí hay `$request->validate()` a pesar de la convención del repo de validar en el front:
 * las credenciales viajan a un tercero y una cadena vacía o de dos letras produce un 401 de
 * Zipnova que confunde ("no reconoció el token") cuando el problema es que no se pegó nada.
 */
class ZipnovaIntegracionController extends Controller
{
    /**
     * Ruta pública del webhook de esta instancia, relativa al root de la API.
     *
     * Se arma con `$request->root()` y no con `config('app.url')`: en el shared hosting la API
     * se sirve bajo `/public` y `root()` ya lo incluye, mientras que `APP_URL` de los `.env` de
     * los clientes no siempre está al día (mismo criterio que `url_del_webhook()` de Mercado
     * Pago en `tienda-api`).
     */
    const RUTA_WEBHOOK = '/api/zipnova/webhook';

    /**
     * POST integraciones/zipnova/conectar `{api_token, api_secret}`.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function conectar(Request $request)
    {
        $request->validate([
            'api_token'  => 'required|string|min:8',
            'api_secret' => 'required|string|min:8',
        ]);

        $webhook_url = rtrim($request->root(), '/') . self::RUTA_WEBHOOK;

        try {
            (new ZipnovaConexionService())->conectar(
                $this->userId(),
                $request->input('api_token'),
                $request->input('api_secret'),
                $webhook_url
            );
        } catch (ZipnovaException $e) {
            if ($e->esDeCredenciales()) {
                return response()->json([
                    'message' => 'Zipnova no reconoció el token o el secret. Revisá que los hayas copiado completos.',
                ], 422);
            }

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (ZipnovaConexionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * POST integraciones/zipnova/disconnect. Nunca falla por Zipnova: dar de baja el webhook es
     * best effort y limpiar el conector es local.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect()
    {
        (new ZipnovaConexionService())->desconectar($this->userId());

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * PUT integraciones/zipnova/config
     * `{origin_id?, bulto_default?{peso,alto,ancho,profundidad}, declarar_valor?, envio_gratis_desde?}`.
     *
     * Límites: son los de Zipnova por ítem (10 g a 10.000 kg, 1 a 5000 cm), expresados en las
     * unidades que carga el comercio (kg y cm).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function config(Request $request)
    {
        $request->validate([
            'origin_id'                 => 'nullable|integer',
            'bulto_default'             => 'nullable|array',
            'bulto_default.peso'        => 'nullable|numeric|min:0.01|max:10000',
            'bulto_default.alto'        => 'nullable|numeric|min:1|max:5000',
            'bulto_default.ancho'       => 'nullable|numeric|min:1|max:5000',
            'bulto_default.profundidad' => 'nullable|numeric|min:1|max:5000',
            'declarar_valor'            => 'nullable|boolean',
            'envio_gratis_desde'        => 'nullable|numeric|min:0',
        ]);

        $cambios = $request->only(['origin_id', 'bulto_default', 'declarar_valor', 'envio_gratis_desde']);

        try {
            (new ZipnovaConexionService())->guardar_config($this->userId(), $cambios);
        } catch (ZipnovaConexionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * POST integraciones/zipnova/origenes: vuelve a pedir los depósitos a Zipnova.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function origenes()
    {
        try {
            (new ZipnovaConexionService())->actualizar_origenes($this->userId());
        } catch (ZipnovaConexionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ZipnovaException $e) {
            return response()->json(['message' => $e->getMessage()], $e->esDeCredenciales() ? 422 : 502);
        }

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * POST integraciones/zipnova/cotizar-prueba `{zipcode, city?, state?}`.
     *
     * Responde 200 `{cotizacion: {zipcode, city, state, envio_gratis, opciones}}`. Si Zipnova no
     * reconoce el código postal, 422 `{codigo: 'ubicacion', needs_location: true, message}` para
     * que la tarjeta pida localidad y provincia y reintente, igual que hace la tienda.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cotizar_prueba(Request $request)
    {
        $request->validate([
            'zipcode' => 'required|string|min:4|max:8',
            'city'    => 'nullable|string|max:120',
            'state'   => 'nullable|string|max:120',
        ]);

        try {
            $cotizacion = (new ZipnovaConexionService())->cotizar_prueba(
                $this->userId(),
                $request->input('zipcode'),
                $request->input('city'),
                $request->input('state')
            );
        } catch (ZipnovaConexionException $e) {
            return response()->json(['codigo' => 'sin_zipnova', 'message' => $e->getMessage()], 422);
        } catch (ZipnovaException $e) {
            if ($e->esDeUbicacion()) {
                return response()->json([
                    'codigo'         => 'ubicacion',
                    'needs_location' => true,
                    'message'        => 'Zipnova no reconoció ese código postal. Probá agregando la localidad y la provincia.',
                ], 422);
            }
            if ($e->esDeCredenciales()) {
                return response()->json(['codigo' => 'credenciales', 'message' => $e->getMessage()], 422);
            }

            return response()->json(['codigo' => 'zipnova', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['cotizacion' => $cotizacion], 200);
    }

    /**
     * La tarjeta de Zipnova del comercio autenticado, ya con el estado nuevo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_con_la_tarjeta()
    {
        return response()->json([
            'integracion' => IntegracionesController::integracion_zipnova($this->userId()),
        ], 200);
    }
}
