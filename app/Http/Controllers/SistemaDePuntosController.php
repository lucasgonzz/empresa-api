<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use App\Models\SistemaDePuntos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ABM de la configuración del programa de puntos del comercio.
 *
 *   GET    api/sistema-de-puntos        -> los programas del comercio, con sus listas de precio
 *   POST   api/sistema-de-puntos        -> crea uno
 *   GET    api/sistema-de-puntos/{id}   -> uno solo
 *   PUT    api/sistema-de-puntos/{id}   -> lo edita
 *   DELETE api/sistema-de-puntos/{id}   -> lo borra (el histórico de movimientos NO se toca)
 *
 * Es un ABM genérico de la SPA (`src/models/sistema_de_puntos.js`), así que el contrato de las
 * respuestas es el del resto de los ABM: `{'models': [...]}` en el index y `{'model': {...}}` en
 * el resto. No es una elección de esta misión: `src/store/__base_store.js` lee esas claves y
 * `routeString('sistema_de_puntos')` arma la URL `sistema-de-puntos` sola.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL INVARIANTE "UN SOLO PROGRAMA ACTIVO POR COMERCIO" VIVE ACÁ, NO EN LA BASE
 * -------------------------------------------------------------------------------------------
 *
 * `sistemas_de_puntos` NO tiene unique en `user_id`: la tabla es plural a propósito, para poder
 * tener varios programas el día que se quieran sin un backfill sobre las bases de los ~40
 * clientes. Lo que el MVP necesita es que `PuntosConfigHelper::programa_activo()` tenga una sola
 * respuesta posible, y eso se consigue apagando los demás al guardar uno con `activo = 1`, en la
 * MISMA transacción que la escritura (`apagar_los_demas()` más abajo).
 *
 * Un unique acá rompería el día que se quieran varios; el apagado en código no.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 Y POR ESO TAMBIÉN SE LLAMA A PuntosConfigHelper::limpiar_memo()
 * -------------------------------------------------------------------------------------------
 *
 * Ese helper memoiza "¿hay programa activo?" en una `static` por `user_id` que dura todo el
 * proceso, porque el reconciliador de acumulación lo pregunta cientos de veces por request
 * (cuelga de `CurrentAcountHelper::checkPagos()`). Si se guarda la configuración y no se tira la
 * memoria, lo que quede de ESE request sigue decidiendo con la configuración vieja: se prende el
 * programa y la venta que se guarda a continuación no acumula, sin ningún error a la vista.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class SistemaDePuntosController extends Controller
{
    /** Mensaje del 404 cuando el id pedido no es de este comercio. */
    const MENSAJE_NO_ENCONTRADO = 'No se encontró el sistema de puntos.';

    public function index()
    {
        $models = SistemaDePuntos::where('user_id', $this->userId())
                                    ->orderBy('created_at', 'DESC')
                                    ->withAll()
                                    ->get();

        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request)
    {
        $error = $this->validar($request);

        if (!is_null($error)) {
            return response()->json($error, 422);
        }

        $user_id = $this->userId();

        $model = null;

        /*
         * La creación, el pivot y el apagado de los demás van juntos o no van: si el apagado
         * quedara afuera de la transacción, un error en el medio dejaría dos programas activos
         * y `programa_activo()` empezaría a devolver el que no es.
         */
        DB::transaction(function () use ($request, $user_id, &$model) {

            $model = SistemaDePuntos::create([
                'user_id'           => $user_id,
                'nombre'            => $this->nombre_del_request($request),
                'activo'            => $this->activo_del_request($request),
                'puntos_cada'       => $this->decimal_del_request($request, 'puntos_cada', 1000),
                'puntos_por_tramo'  => $this->decimal_del_request($request, 'puntos_por_tramo', 1),
                'valor_punto'       => $this->decimal_del_request($request, 'valor_punto', 10),
                'vencimiento_meses' => $this->vencimiento_del_request($request),
                'minimo_canje'      => $this->decimal_del_request($request, 'minimo_canje', 500),
                'tope_porcentaje'   => $this->decimal_del_request($request, 'tope_porcentaje', 20),
            ]);

            $this->sincronizar_price_types($model, $request);

            $this->apagar_los_demas($model);
        });

        PuntosConfigHelper::limpiar_memo($user_id);

        return response()->json(['model' => $this->fullModel('sistema_de_puntos', $model->id)], 201);
    }

    public function show($id)
    {
        $model = $this->model_del_comercio($id);

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        return response()->json(['model' => $this->fullModel('sistema_de_puntos', $id)], 200);
    }

    public function update(Request $request, $id)
    {
        $model = $this->model_del_comercio($id);

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        $error = $this->validar($request);

        if (!is_null($error)) {
            return response()->json($error, 422);
        }

        $user_id = $this->userId();

        DB::transaction(function () use ($request, $model) {

            $model->nombre            = $this->nombre_del_request($request);
            $model->activo            = $this->activo_del_request($request);
            $model->puntos_cada       = $this->decimal_del_request($request, 'puntos_cada', 1000);
            $model->puntos_por_tramo  = $this->decimal_del_request($request, 'puntos_por_tramo', 1);
            $model->valor_punto       = $this->decimal_del_request($request, 'valor_punto', 10);
            $model->vencimiento_meses = $this->vencimiento_del_request($request);
            $model->minimo_canje      = $this->decimal_del_request($request, 'minimo_canje', 500);
            $model->tope_porcentaje   = $this->decimal_del_request($request, 'tope_porcentaje', 20);

            $model->save();

            $this->sincronizar_price_types($model, $request);

            $this->apagar_los_demas($model);
        });

        PuntosConfigHelper::limpiar_memo($user_id);

        return response()->json(['model' => $this->fullModel('sistema_de_puntos', $model->id)], 200);
    }

    public function destroy($id)
    {
        $model = $this->model_del_comercio($id);

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        $user_id = $model->user_id;

        DB::transaction(function () use ($model) {

            /*
             * Se sueltan las listas de precio del pivot y se borra la configuración. Los
             * `movimiento_puntos` NO se tocan: son el libro del negocio y siguen explicando el
             * saldo de cada cliente aunque el programa se dé de baja. Su
             * `sistema_de_puntos_id` queda apuntando a una fila que ya no está, que es
             * exactamente lo que se quiere: el histórico dice con qué configuración se otorgó.
             */
            $model->price_types()->detach();

            $model->delete();
        });

        PuntosConfigHelper::limpiar_memo($user_id);

        return response(null);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Privados
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El programa pedido, si es de este comercio.
     *
     * 🔴 El filtro por `user_id` va en la query y no en un `if` después del `find()`: es la
     * única cosa que separa la configuración de un cliente de la de otro en una instancia
     * compartida.
     *
     * @param  int  $id
     * @return \App\Models\SistemaDePuntos|null
     */
    private function model_del_comercio($id)
    {
        return SistemaDePuntos::where('id', $id)
                                ->where('user_id', $this->userId())
                                ->first();
    }

    /**
     * Apaga todos los programas del comercio menos el que se acaba de guardar.
     *
     * Corre siempre, aun cuando el que se guardó quedó en `activo = 0`: en ese caso no hay nada
     * que apagar y el UPDATE no toca ninguna fila. Se prefiere una condición de menos a una
     * rama que solo se ejecuta a veces.
     *
     * @param  \App\Models\SistemaDePuntos  $model
     * @return void
     */
    private function apagar_los_demas($model)
    {
        if (!$model->activo) {
            return;
        }

        SistemaDePuntos::where('user_id', $model->user_id)
                        ->where('id', '!=', $model->id)
                        ->where('activo', 1)
                        ->update(['activo' => 0]);
    }

    /**
     * Sincroniza las listas de precio del programa con lo que mandó el ABM.
     *
     * Misma llamada que usa `CategoryController@store` para su pivot con listas: `detach()` +
     * `attach()` con el valor del pivot. El `multiplicador` viaja aunque la interfaz del MVP no
     * lo exponga (hedge de la decisión del 21/8/2026): si no viene en el pivot llega `null`, que
     * es "usá la tasa global".
     *
     * @param  \App\Models\SistemaDePuntos  $model
     * @param  \Illuminate\Http\Request     $request
     * @return void
     */
    private function sincronizar_price_types($model, Request $request)
    {
        $price_types = $request->price_types;

        if (!is_array($price_types)) {
            $price_types = [];
        }

        GeneralHelper::attachModels($model, 'price_types', $price_types, ['multiplicador']);
    }

    /**
     * Los chequeos que hacen falta antes de escribir. Devuelve null si está todo bien, o el
     * cuerpo del 422.
     *
     * Es el mismo criterio de `LimiteCreditoHelper`: la validación devuelve un array y el que
     * llama decide el código, en vez de tirar una excepción que después hay que atrapar.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|null
     */
    private function validar(Request $request)
    {
        /*
         * 🔴 `puntos_cada` en 0 no es un error de tipeo cualquiera: es el divisor del cálculo
         * (`PuntosBaseHelper::puntos_de_la_base()`), que se defiende devolviendo 0 puntos. O sea
         * que un 0 acá deja el programa prendido y emitiendo nada, sin un solo error a la vista.
         * Se corta en la puerta.
         */
        if ($this->decimal_del_request($request, 'puntos_cada', 1000) <= 0) {
            return ['message' => 'Cada cuántos pesos se dan puntos tiene que ser mayor a cero.'];
        }

        if ($this->decimal_del_request($request, 'puntos_por_tramo', 1) <= 0) {
            return ['message' => 'Los puntos por tramo tienen que ser mayores a cero.'];
        }

        if ($this->decimal_del_request($request, 'valor_punto', 10) <= 0) {
            return ['message' => 'El valor del punto tiene que ser mayor a cero.'];
        }

        if ($this->decimal_del_request($request, 'minimo_canje', 500) < 0) {
            return ['message' => 'El mínimo de canje no puede ser negativo.'];
        }

        $tope = $this->decimal_del_request($request, 'tope_porcentaje', 20);

        if ($tope < 0 || $tope > 100) {
            return ['message' => 'El tope de pago con puntos tiene que estar entre 0 y 100 por ciento.'];
        }

        $vencimiento = $this->vencimiento_del_request($request);

        if (!is_null($vencimiento) && $vencimiento <= 0) {
            return ['message' => 'Los meses de vencimiento tienen que ser mayores a cero, o quedar vacíos para que los puntos no venzan.'];
        }

        return null;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    private function nombre_del_request(Request $request)
    {
        $nombre = trim((string) $request->nombre);

        return $nombre === '' ? 'Programa de puntos' : $nombre;
    }

    /**
     * El `activo` del request, normalizado a 1 o 0.
     *
     * El ABM manda un checkbox, que puede llegar como `true`, `1`, `'1'` o directamente no
     * llegar. `filter_var` con FILTER_VALIDATE_BOOLEAN es el mismo criterio que usan los otros
     * controllers del repo para los checkbox del ABM.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int
     */
    private function activo_del_request(Request $request)
    {
        return filter_var($request->activo, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  string                    $key
     * @param  float                     $default
     * @return float
     */
    private function decimal_del_request(Request $request, $key, $default)
    {
        $valor = $request->input($key);

        if (is_null($valor) || $valor === '') {
            return (float) $default;
        }

        return (float) $valor;
    }

    /**
     * Los meses de vencimiento, o null si los puntos no vencen.
     *
     * 🔴 Vacío y cero NO son lo mismo que "12 meses": `null` es "no vencen nunca" y es una
     * configuración legítima que el comando `puntos:vencer` sabe leer. Por eso este campo no usa
     * `decimal_del_request()` con un default.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|null
     */
    private function vencimiento_del_request(Request $request)
    {
        $valor = $request->input('vencimiento_meses');

        if (is_null($valor) || $valor === '') {
            return null;
        }

        return (int) $valor;
    }
}
