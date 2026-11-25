<?php

namespace App\Http\Controllers;

use App\Services\Andreani\AndreaniConexionService;
use App\Services\Andreani\AndreaniException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoints de la tarjeta «Envíos (Andreani)» en ABM -> Integraciones -> Tienda online (misión envios-andreani,
 * 25/11/2026): conectar con número de cliente y contratos, desconectar, guardar las preferencias y cotizar de
 * prueba. La lógica vive en `App\Services\Andreani\AndreaniConexionService`; acá solo se valida la forma de la
 * entrada, se traducen las excepciones a códigos HTTP y se responde la tarjeta actualizada.
 *
 * Conectar, desconectar y config responden `{integracion}` con la MISMA forma que un item de
 * `GET /api/integraciones` (`IntegracionesController::integracion_andreani()`), así la SPA pisa la tarjeta con la
 * respuesta y no vuelve a pedir el listado. `cotizar_prueba` responde `{cotizacion}`.
 *
 * Códigos (siempre con `{codigo, message}` salvo en los éxitos):
 *  - 422 `validacion`: falta o sobra algo en la entrada. 422 `credenciales`: Andreani no reconoció el cliente o
 *    el contrato. 422 `sin_andreani`: el comercio no tiene Andreani conectado.
 *  - 502 `andreani`: Andreani no respondió o respondió 5xx. Es "probá en un rato".
 *
 * Validación: se usa `Validator::make` (no `$request->validate()`) a propósito, para devolver el `codigo`
 * `validacion` que la tarjeta distingue de `credenciales`. Los datos viajan a un tercero y una cadena vacía produce
 * un 400 de Andreani que confunde ("no reconoció el contrato") cuando el problema es que no se escribió nada.
 */
class AndreaniIntegracionController extends Controller
{
    /**
     * POST integraciones/andreani/conectar `{cliente, contrato_domicilio, contrato_sucursal?, sucursal_origen?}`.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function conectar(Request $request)
    {
        $invalido = $this->validar($request, [
            'cliente'            => 'required|string|max:80',
            'contrato_domicilio' => 'required|string|max:80',
            'contrato_sucursal'  => 'nullable|string|max:80',
            'sucursal_origen'    => 'nullable|integer|min:1',
        ]);

        if (!is_null($invalido)) {
            return $invalido;
        }

        try {
            (new AndreaniConexionService())->conectar(
                $this->userId(),
                $request->input('cliente'),
                $request->input('contrato_domicilio'),
                $request->input('contrato_sucursal'),
                $request->input('sucursal_origen')
            );
        } catch (AndreaniException $e) {
            if ($e->esDeValidacion()) {
                return response()->json([
                    'codigo'  => 'credenciales',
                    'message' => 'Andreani no reconoció ese número de cliente o contrato. Revisá que los hayas copiado completos.',
                ], 422);
            }

            return response()->json(['codigo' => 'andreani', 'message' => $e->getMessage()], 502);
        } catch (\DomainException $e) {
            return response()->json(['codigo' => 'sin_andreani', 'message' => $e->getMessage()], 422);
        }

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * POST integraciones/andreani/disconnect. Nunca falla por Andreani: limpiar el conector es local.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect()
    {
        (new AndreaniConexionService())->desconectar($this->userId());

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * PUT integraciones/andreani/config
     * `{bulto_default?{peso,alto,ancho,profundidad}, declarar_valor?, envio_gratis_desde?, ofrecer_en_tienda?, contrato_sucursal?, sucursal_origen?}`.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function config(Request $request)
    {
        $invalido = $this->validar($request, [
            'bulto_default'             => 'nullable|array',
            'bulto_default.peso'        => 'nullable|numeric|min:0.01|max:10000',
            'bulto_default.alto'        => 'nullable|numeric|min:1|max:5000',
            'bulto_default.ancho'       => 'nullable|numeric|min:1|max:5000',
            'bulto_default.profundidad' => 'nullable|numeric|min:1|max:5000',
            'declarar_valor'            => 'nullable|boolean',
            'envio_gratis_desde'        => 'nullable|numeric|min:0',
            'ofrecer_en_tienda'         => 'nullable|boolean',
            'contrato_sucursal'         => 'nullable|string|max:80',
            'sucursal_origen'           => 'nullable|integer|min:1',
        ]);

        if (!is_null($invalido)) {
            return $invalido;
        }

        // `only` conserva la diferencia entre "no vino" (se preserva) y "vino en null" (se limpia).
        $cambios = $request->only(['bulto_default', 'declarar_valor', 'envio_gratis_desde', 'ofrecer_en_tienda', 'contrato_sucursal', 'sucursal_origen']);

        try {
            (new AndreaniConexionService())->guardar_config($this->userId(), $cambios);
        } catch (\DomainException $e) {
            return response()->json(['codigo' => 'sin_andreani', 'message' => $e->getMessage()], 422);
        }

        return $this->respuesta_con_la_tarjeta();
    }

    /**
     * POST integraciones/andreani/cotizar-prueba `{zipcode}`.
     *
     * Responde 200 `{cotizacion: {zipcode, motivo, envio_gratis, opciones}}`. `motivo` explica una lista vacía:
     * `excede_medidas` (el bulto por defecto pasa los topes) o `sin_tarifa` (Andreani no tiene tarifa para ese CP).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cotizar_prueba(Request $request)
    {
        // El operador puede pegar " x5000-abc ": se limpia (sin espacios ni guiones, en mayúsculas) ANTES de validar el largo.
        if (is_scalar($request->input('zipcode'))) {
            $request->merge(['zipcode' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('zipcode')))]);
        }

        $invalido = $this->validar($request, ['zipcode' => 'required|string|min:4|max:8']);

        if (!is_null($invalido)) {
            return $invalido;
        }

        try {
            $cotizacion = (new AndreaniConexionService())->cotizar_prueba($this->userId(), $request->input('zipcode'));
        } catch (\DomainException $e) {
            return response()->json(['codigo' => 'sin_andreani', 'message' => $e->getMessage()], 422);
        } catch (AndreaniException $e) {
            return response()->json(['codigo' => 'andreani', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['cotizacion' => $cotizacion], 200);
    }

    /**
     * Valida la entrada y, si no cumple, devuelve el 422 `validacion` con el primer mensaje.
     *
     * @param Request $request
     * @param array $reglas
     * @return \Illuminate\Http\JsonResponse|null null si la entrada es válida.
     */
    protected function validar(Request $request, array $reglas)
    {
        $validador = Validator::make($request->all(), $reglas);

        if ($validador->fails()) {
            return response()->json(['codigo' => 'validacion', 'message' => $validador->errors()->first()], 422);
        }

        return null;
    }

    /**
     * La tarjeta de Andreani del comercio autenticado, ya con el estado nuevo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_con_la_tarjeta()
    {
        return response()->json([
            'integracion' => IntegracionesController::integracion_andreani($this->userId()),
        ], 200);
    }
}
