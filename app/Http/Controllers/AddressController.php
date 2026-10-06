<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\address\AjusteDePreciosDeSucursalHelper;
use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{

    /**
     * Mensaje del 404 de "eliminar-resumen" y de "destroy" cuando la sucursal no existe o es de otro
     * comercio.
     *
     * 🔴 CONTRATO CON LA SPA: `empresa-spa/src/store/address.js` distingue este 404 del de una API
     * VIEJA (que no tiene la ruta de resumen, y responde sin esta palabra) buscando "sucursal" en el
     * mensaje. Si se reformula, la palabra "sucursal" tiene que seguir adentro: el test
     * `Eliminar_sucursal_concurrencia_y_bordes_Test` lo fija. Sin ella, la SPA nueva tomaría "ya está
     * eliminada" por "API vieja" y caería al modo clásico (seguro: el DELETE sin decisión lo frena un 422,
     * pero se pierde el aviso).
     */
    const MENSAJE_SUCURSAL_INEXISTENTE = 'La sucursal no existe o no es de este comercio.';

    public function index() {
        $models = Address::where('user_id', $this->userId())
                            ->orderBy('created_at', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        /*
         * Ajuste de precios de la sucursal (recargo o descuento en %). Se resuelve y se valida ANTES
         * de crear nada: un valor invalido es un 422 y no queda ni la sucursal a medio escribir.
         * `null` = no hay nada que escribir (las columnas todavia no existen porque el deploy sube
         * el codigo antes de migrar, o la SPA no manda las claves): se crea la sucursal como siempre.
         */
        $ajuste = AjusteDePreciosDeSucursalHelper::resolver_del_request($request);

        if (!is_null($ajuste) && !$ajuste['valido']) {
            return response()->json(['message' => $ajuste['mensaje']], 422);
        }

        $datos = [
            'num'                   => $this->num('addresses'),
            'street'                => $request->street,
            'street_number'         => $request->street_number,
            'city'                  => $request->city,
            'province'              => $request->province,
            'default_address'       => $request->default_address,
            // Designación de depósito de origen preferente para sugerencias de
            // stock (v2). Cast a bool: la columna no admite null y el ABM sin
            // la extensión no manda la clave.
            'es_deposito_origen'    => (bool) $request->es_deposito_origen,
            'user_id'               => $this->userId(),
            // afip_information por defecto de la sucursal, usado para resolver
            // la identidad fiscal en ventas en negro (remitos sin facturacion).
            'default_afip_information_id' => $request->default_afip_information_id,
        ];

        // Depósito madre (misión deposito-madre): mismo cast que es_deposito_origen, la clave
        // ausente queda en 0. Si viene en 1, el hook `saved` de Address desmarca la madre anterior
        // del comercio. Solo si la columna ya existe (Address::columna_madre_existe(): el deploy
        // sube el código antes de migrar, y nombrarla sin la columna era un 500).
        if (Address::columna_madre_existe()) {
            $datos['es_deposito_madre'] = (bool) $request->es_deposito_madre;
        }

        $model = Address::create(array_merge($datos, is_null($ajuste) ? [] : $ajuste['valores']));
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Address', $id)], 200);
    }

    public function update(Request $request, $id) {

        /*
         * Ajuste de precios de la sucursal. Igual que en store, se valida antes de tocar el modelo.
         *
         * 🔴 UPDATE SIN NINGUNA DE LAS DOS CLAVES NO TOCA EL AJUSTE (el helper devuelve null). Una SPA
         * vieja no conoce las claves y no las manda: si aca se escribiera el par "siempre", el primer
         * guardado de la sucursal desde esa SPA le borraria el recargo o el descuento en silencio.
         * Y sin las columnas todavia (deploy a medio hacer) tampoco se las nombra: seria un 500.
         */
        $ajuste = AjusteDePreciosDeSucursalHelper::resolver_del_request($request);

        if (!is_null($ajuste) && !$ajuste['valido']) {
            return response()->json(['message' => $ajuste['mensaje']], 422);
        }

        $model = Address::find($id);
        $model->street                = $request->street;
        $model->phone                 = $request->phone;
        $model->email                 = $request->email;
        $model->street_number         = $request->street_number;
        $model->city                  = $request->city;
        $model->province              = $request->province;
        $model->default_address       = $request->default_address;
        // Solo si el request trae la clave CON valor: el ABM sin la extensión de
        // sugerencias no la manda, y no debe pisar una designación existente.
        // El !is_null es la otra mitad del criterio (el mismo de
        // usar_condicion_fiscal_en_costeo en UserController@update): sin él, un
        // payload con la clave en null des-designa el depósito en silencio.
        if ($request->has('es_deposito_origen') && !is_null($request->es_deposito_origen)) {
            $model->es_deposito_origen = (bool) $request->es_deposito_origen;
        }
        // Depósito madre: el MISMO guard que es_deposito_origen y por los mismos motivos (el ABM
        // sin la extensión no manda la clave, y un null no puede desmarcar el madre en silencio).
        // La unicidad (marcar esta desmarca a la anterior) la resuelve el hook de Address. Y sin la
        // columna todavía (deploy a medio migrar) no se la nombra: sería un 500.
        if (Address::columna_madre_existe()
            && $request->has('es_deposito_madre') && !is_null($request->es_deposito_madre)) {
            $model->es_deposito_madre = (bool) $request->es_deposito_madre;
        }
        // afip_information por defecto de la sucursal, usado para resolver
        // la identidad fiscal en ventas en negro (remitos sin facturacion).
        $model->default_afip_information_id = $request->default_afip_information_id;
        if (!is_null($ajuste)) {
            foreach ($ajuste['valores'] as $columna => $valor) {
                $model->{$columna} = $valor;
            }
        }
        $model->save();
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 200);
    }

    /**
     * Lo que pasa si se elimina la sucursal: stock (con signo, variantes, papelera), usuarios que la
     * tienen elegida, marcas, ventas, cajas, puntos de venta, clientes, bloqueos y si iría a segundo
     * plano. Es lo que lee el modal de eliminar de la SPA para preguntar (misión
     * eliminar-sucursal-con-stock, 5/10/2026). Solo lee.
     *
     * 404 si la sucursal no existe o es de otro comercio (D15: antes no había scope por dueño).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function eliminar_resumen($id) {

        $owner_id = $this->userId();

        $model = EliminarSucursalHelper::direccion_del_dueno($id, $owner_id);

        if (is_null($model)) {
            return response()->json(['message' => Self::MENSAJE_SUCURSAL_INEXISTENTE], 404);
        }

        return response()->json(EliminarSucursalHelper::resumen($model, $owner_id), 200);
    }

    /**
     * Elimina la sucursal (misión eliminar-sucursal-con-stock, 5/10/2026). Delgado a propósito: valida
     * la tenencia, arma la decisión y delega todo en EliminarSucursalHelper.
     *
     * La decisión viaja en el request (query string o cuerpo), toda OPCIONAL: `stock_accion`
     * (transferir | descartar), `stock_destino_id`, `usuarios_accion` (reasignar | dejar_sin_sucursal),
     * `usuarios_destino_id`, `reemplazo_id`. Sin decisión y con algo que decidir → 422
     * `requiere_decision` (así la SPA vieja, el borrado masivo y el asistente IA reciben un mensaje
     * claro en vez de perder stock en silencio); sin nada que decidir, se borra como siempre.
     *
     * 🔴 La firma sigue siendo `destroy($id)` y la decisión se lee de `request()`: el borrado masivo
     * (`DeleteModelsHelper::process_delete`, también desde su job) y el asistente IA llaman a este
     * método con UN solo argumento. Ver EliminarSucursalHelper::decision_del_request().
     *
     * Respuestas: 200 `{eliminada, resumen}` (antes era un cuerpo vacío: la SPA vieja no lo lee),
     * 202 `{queued, message, background_process_id}` (muchas filas: segundo plano), 404, 422
     * `{message, requiere_decision, bloqueos, faltan}`, 500 si el trabajo se cortó (volver a eliminar
     * continúa donde quedó).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id) {

        $owner_id = $this->userId();

        $model = EliminarSucursalHelper::direccion_del_dueno($id, $owner_id);

        // D15: id de otro comercio o inexistente → 404 (antes: borraba la ajena, o 500 con un id viejo).
        if (is_null($model)) {
            return response()->json(['message' => Self::MENSAJE_SUCURSAL_INEXISTENTE], 404);
        }

        $decision = EliminarSucursalHelper::decision_del_request(request());

        $resultado = EliminarSucursalHelper::eliminar($model, $owner_id, $this->userId(false), $decision);

        if ($resultado['status'] === 200) {
            $this->sendDeleteModelNotification('Address', $model->id);
        }

        return response()->json($resultado['body'], $resultado['status']);
    }
}
